<?php

namespace App\Console\Commands;

use App\Services\UserManagementService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ResetAdministratorPasswordCommand extends Command
{
    protected $signature = 'identity:admin:reset-password';

    protected $description = 'Reset an account password';

    public function handle(UserManagementService $userManagement): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive input is required.');

            return self::FAILURE;
        }

        $email = (string) $this->ask('Email address');
        $password = (string) $this->secret('New password', false);
        $confirmation = (string) $this->secret('Confirm password', false);

        if (! hash_equals($password, $confirmation)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        try {
            $userManagement->resetPassword($email, $password);
        } catch (ValidationException $exception) {
            $this->reportValidationErrors($exception);

            return self::FAILURE;
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Password reset failed; no changes were applied.');

            return self::FAILURE;
        }

        $this->info('Password reset.');

        return self::SUCCESS;
    }

    private function reportValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $messages) {
            $this->error($messages[0]);
        }
    }
}
