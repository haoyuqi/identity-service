<?php

use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

$root = dirname(__DIR__, 2);
$readyPath = $argv[1] ?? '';
$startPath = $argv[2] ?? '';
$payload = json_decode(stream_get_contents(STDIN), true);

if (! is_string($readyPath) || $readyPath === ''
    || ! is_string($startPath) || $startPath === ''
    || ! is_array($payload)
    || ! is_string($payload['application_name'] ?? null)
    || ! in_array($payload['action'] ?? null, ['bootstrap', 'disable'], true)) {
    fwrite(STDERR, "invalid-worker-input\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$testConnection = config('database.connections.testing');

if (! is_array($testConnection)) {
    fwrite(STDERR, "unsafe-test-database-configuration\n");
    exit(5);
}

try {
    TestDatabaseGuard::ensureSafeDatabase(
        $app->environment(),
        (string) config('database.default'),
        $testConnection,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'unsafe-test-database:'.get_class($exception)."\n");
    exit(5);
}

DB::connection()->select('select set_config(?, ?, false)', ['application_name', $payload['application_name']]);
file_put_contents($readyPath, 'ready', LOCK_EX);

$deadline = hrtime(true) + 10_000_000_000;

while (! is_file($startPath)) {
    if (hrtime(true) >= $deadline) {
        fwrite(STDERR, "worker-start-timeout\n");
        exit(3);
    }

    usleep(10_000);
}

try {
    if ($payload['action'] === 'bootstrap') {
        if (! is_string($payload['email'] ?? null)) {
            fwrite(STDERR, "invalid-worker-input\n");
            exit(2);
        }

        $app->make(UserManagementService::class)->bootstrapAdministrator(
            'Concurrent Administrator',
            $payload['email'],
            'a-safe-password-123',
        );
        fwrite(STDOUT, "created\n");
        exit(0);
    }

    if (! is_int($payload['actor_id'] ?? null) || ! is_int($payload['target_id'] ?? null)) {
        fwrite(STDERR, "invalid-worker-input\n");
        exit(2);
    }

    $actor = User::query()->findOrFail($payload['actor_id']);
    $target = User::query()->findOrFail($payload['target_id']);
    $app->make(UserManagementService::class)->disableAccount($target, $actor);

    fwrite(STDOUT, "disabled\n");
    exit(0);
} catch (AuthorizationException $exception) {
    if ($exception->getMessage() !== 'An enabled administrator is required.') {
        fwrite(STDERR, 'worker-failed:'.get_class($exception)."\n");
        exit(4);
    }

    fwrite(STDOUT, "actor-inactive\n");
    exit(12);
} catch (DomainException $exception) {
    $expectedMessage = $payload['action'] === 'bootstrap'
        ? 'An enabled administrator already exists.'
        : 'The last enabled administrator cannot be disabled.';

    if ($exception->getMessage() !== $expectedMessage) {
        fwrite(STDERR, 'worker-failed:'.get_class($exception)."\n");
        exit(4);
    }

    fwrite(STDOUT, "already-exists\n");
    exit(10);
} catch (Throwable $exception) {
    fwrite(STDERR, 'worker-failed:'.get_class($exception)."\n");
    exit(4);
}
