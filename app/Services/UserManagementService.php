<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class UserManagementService
{
    /**
     * Shared transaction-scoped lock for administrator state changes.
     * Administrator disabling must use this same key.
     */
    public const ADMINISTRATOR_STATE_LOCK_KEY = 684022671;

    public function __construct()
    {
        // Read the driver from configuration rather than opening a connection: the shared
        // advisory lock below is PostgreSQL SQL and must fail closed on any other driver.
        $connection = (string) config('database.default');
        $driver = config('database.connections.'.$connection.'.driver');

        if ($driver !== 'pgsql') {
            throw new RuntimeException('The account service requires a PostgreSQL connection.');
        }
    }

    public function bootstrapAdministrator(string $name, string $email, string $password): User
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        $this->validateIdentity($name, $email, $password);
        $passwordHash = Hash::make($password);

        $connection = (new User)->getConnection();

        try {
            return $connection->transaction(function () use ($connection, $name, $email, $passwordHash): User {
                $connection->select('select pg_advisory_xact_lock(?)', [self::ADMINISTRATOR_STATE_LOCK_KEY]);

                $existingAdmin = User::on($connection->getName())
                    ->where('role', UserRole::ADMIN->value)
                    ->whereNull('disabled_at')
                    ->exists();

                if ($existingAdmin) {
                    throw new DomainException('An enabled administrator already exists.');
                }

                $user = (new User)->setConnection($connection->getName());
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $passwordHash,
                    'role' => UserRole::ADMIN,
                ]);
                $user->save();

                $this->writeAudit($connection, null, (int) $user->getKey(), AuditAction::BOOTSTRAP);

                return $user;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'email' => 'An account with this email already exists.',
                ]);
            }

            throw $exception;
        }
    }

    public function resetPassword(string $email, string $password): User
    {
        $email = mb_strtolower(trim($email));

        Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => $this->passwordRules(),
            ],
        )->validate();
        $passwordHash = Hash::make($password);

        $connection = (new User)->getConnection();

        return $connection->transaction(function () use ($connection, $email, $passwordHash): User {
            $user = User::on($connection->getName())->where('email', $email)->first();

            if ($user === null) {
                throw new DomainException('No account was found for that email address.');
            }

            $user->password = $passwordHash;
            $user->save();

            $this->writeAudit($connection, null, (int) $user->getKey(), AuditAction::PASSWORD_RESET);

            return $user;
        });
    }

    public function createAccount(
        string $name,
        string $email,
        UserRole $role,
        string $password,
        User $actor,
    ): User {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        $this->validateIdentity($name, $email, $password);
        $passwordHash = Hash::make($password);

        $connection = (new User)->getConnection();

        try {
            return $connection->transaction(function () use ($connection, $name, $email, $role, $passwordHash, $actor): User {
                $connection->select('select pg_advisory_xact_lock(?)', [self::ADMINISTRATOR_STATE_LOCK_KEY]);
                $activeActor = $this->lockActiveAdministrator($connection, $actor);

                $user = (new User)->setConnection($connection->getName());
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $passwordHash,
                    'role' => $role,
                ]);
                $user->save();

                $this->writeAudit($connection, (int) $activeActor->getKey(), (int) $user->getKey(), AuditAction::ACCOUNT_CREATED);

                return $user;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'email' => 'An account with this email already exists.',
                ]);
            }

            throw $exception;
        }
    }

    public function disableAccount(User $target, User $actor): User
    {
        $connection = (new User)->getConnection();

        return $connection->transaction(function () use ($connection, $target, $actor): User {
            $connection->select('select pg_advisory_xact_lock(?)', [self::ADMINISTRATOR_STATE_LOCK_KEY]);
            $activeActor = $this->lockActiveAdministrator($connection, $actor);
            $account = User::on($connection->getName())
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw new DomainException('The account no longer exists.');
            }

            if ($account->disabled_at !== null) {
                throw new DomainException('The account is already disabled.');
            }

            if ($account->getRawOriginal('role') === UserRole::ADMIN->value) {
                $enabledAdministrators = User::on($connection->getName())
                    ->where('role', UserRole::ADMIN->value)
                    ->whereNull('disabled_at')
                    ->count();

                if ($enabledAdministrators <= 1) {
                    throw new DomainException('The last enabled administrator cannot be disabled.');
                }
            }

            if ($activeActor->is($account)) {
                throw new DomainException('Administrators cannot disable their own account.');
            }

            $account->disabled_at = now()->utc();
            $account->save();

            $this->writeAudit(
                $connection,
                (int) $activeActor->getKey(),
                (int) $account->getKey(),
                AuditAction::ACCOUNT_DISABLED,
            );

            return $account;
        });
    }

    private function validateIdentity(string $name, string $email, string $password): void
    {
        Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => $this->passwordRules(),
            ],
        )->validate();
    }

    private function lockActiveAdministrator(Connection $connection, User $actor): User
    {
        $activeActor = User::on($connection->getName())
            ->whereKey($actor->getKey())
            ->lockForUpdate()
            ->first();

        if ($activeActor === null
            || $activeActor->getRawOriginal('role') !== UserRole::ADMIN->value
            || $activeActor->disabled_at !== null) {
            throw new AuthorizationException('An enabled administrator is required.');
        }

        return $activeActor;
    }

    /**
     * @return array<int, string|Closure(string, mixed, Closure(string): void): void>
     */
    private function passwordRules(): array
    {
        return [
            'required',
            'string',
            'min:12',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            },
        ];
    }

    private function writeAudit(Connection $connection, ?int $actorId, int $targetUserId, AuditAction $action): void
    {
        $auditLog = (new AdminAuditLog)->setConnection($connection->getName());
        $auditLog->fill([
            'actor_id' => $actorId,
            'target_user_id' => $targetUserId,
            'action' => $action->value,
        ]);
        $auditLog->save();
    }
}
