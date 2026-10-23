<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UserManagementService
{
    /**
     * Shared transaction-scoped lock for administrator state changes.
     * Administrator disabling must use this same key.
     */
    public const ADMINISTRATOR_STATE_LOCK_KEY = 684022671;

    public function bootstrapAdministrator(string $name, string $email, string $password): User
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        $this->validateIdentity($name, $email, $password);

        $connection = (new User)->getConnection();

        try {
            return $connection->transaction(function () use ($connection, $name, $email, $password): User {
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
                    'password' => $password,
                    'role' => UserRole::ADMIN,
                ]);
                $user->save();

                $this->writeAudit($connection, null, (int) $user->getKey(), 'admin.bootstrap');

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

        $connection = (new User)->getConnection();

        return $connection->transaction(function () use ($connection, $email, $password): User {
            $user = User::on($connection->getName())->where('email', $email)->first();

            if ($user === null) {
                throw new DomainException('No account was found for that email address.');
            }

            $user->password = $password;
            $user->save();

            $this->writeAudit($connection, null, (int) $user->getKey(), 'admin.password_reset');

            return $user;
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
                if (strlen((string) $value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            },
        ];
    }

    private function writeAudit(Connection $connection, ?int $actorId, int $targetUserId, string $action): void
    {
        $auditLog = (new AdminAuditLog)->setConnection($connection->getName());
        $auditLog->fill([
            'actor_id' => $actorId,
            'target_user_id' => $targetUserId,
            'action' => $action,
        ]);
        $auditLog->save();
    }
}
