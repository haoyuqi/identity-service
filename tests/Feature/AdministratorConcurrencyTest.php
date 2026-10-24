<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class AdministratorConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_bootstrap_processes_create_exactly_one_administrator(): void
    {
        $results = $this->runConcurrentLifecycleWorkers([
            ['action' => 'bootstrap', 'email' => 'first@example.test'],
            ['action' => 'bootstrap', 'email' => 'second@example.test'],
        ], 'identity-service-bootstrap-worker');

        $this->assertEqualsCanonicalizing([
            ['output' => 'created', 'exit_code' => 0],
            ['output' => 'already-exists', 'exit_code' => 10],
        ], $results);
        $this->assertSame(1, User::query()->where('role', UserRole::ADMIN->value)->whereNull('disabled_at')->count());
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'admin.bootstrap')->count());
        $this->assertContains(User::query()->sole()->email, ['first@example.test', 'second@example.test']);
    }

    public function test_concurrent_mutual_disable_keeps_winner_and_rejects_disabled_actor(): void
    {
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();

        $results = $this->runConcurrentLifecycleWorkers([
            ['action' => 'disable', 'actor_id' => (int) $first->getKey(), 'target_id' => (int) $second->getKey()],
            ['action' => 'disable', 'actor_id' => (int) $second->getKey(), 'target_id' => (int) $first->getKey()],
        ], 'identity-service-disable-worker');

        $this->assertEqualsCanonicalizing([
            ['output' => 'disabled', 'exit_code' => 0],
            ['output' => 'actor-inactive', 'exit_code' => 12],
        ], $results);

        $activeAdministrator = User::query()
            ->where('role', UserRole::ADMIN->value)
            ->whereNull('disabled_at')
            ->sole();
        $disabledAdministrator = User::query()
            ->where('role', UserRole::ADMIN->value)
            ->whereNotNull('disabled_at')
            ->sole();
        $audit = AdminAuditLog::query()->where('action', 'admin.account_disabled')->sole();

        $this->assertContains($activeAdministrator->getKey(), [$first->getKey(), $second->getKey()]);
        $this->assertSame($disabledAdministrator->getKey(), $audit->target_user_id);
        $this->assertSame($activeAdministrator->getKey(), $audit->actor_id);
    }

    /**
     * @param  array<int, array<string, int|string>>  $payloads
     * @return array<int, array{output: string, exit_code: int}>
     */
    private function runConcurrentLifecycleWorkers(array $payloads, string $applicationName): array
    {
        $directory = sys_get_temp_dir().'/identity-service-lifecycle-race-'.bin2hex(random_bytes(10));
        $this->assertTrue(mkdir($directory, 0700));

        $startPath = $directory.'/start';
        $workers = [];
        $connection = DB::connection();
        $lockAcquired = false;

        try {
            try {
                $connection->select('select pg_advisory_lock(?)', [UserManagementService::ADMINISTRATOR_STATE_LOCK_KEY]);
                $lockAcquired = true;

                foreach ($payloads as $index => $payload) {
                    $payload['application_name'] = $applicationName;
                    $workers[] = $this->startLifecycleWorker(
                        $directory.'/ready-'.$index,
                        $startPath,
                        $payload,
                    );
                }

                $this->waitForWorkersToBeReady($workers);
                touch($startPath);
                $this->waitForWorkersToContendOnAdministratorLock($connection, $applicationName);
            } finally {
                if ($lockAcquired) {
                    $connection->select('select pg_advisory_unlock(?)', [UserManagementService::ADMINISTRATOR_STATE_LOCK_KEY]);
                    $lockAcquired = false;
                }
            }

            return array_map($this->finishLifecycleWorker(...), $workers);
        } finally {
            if ($lockAcquired) {
                $connection->select('select pg_advisory_unlock(?)', [UserManagementService::ADMINISTRATOR_STATE_LOCK_KEY]);
            }

            if (! is_file($startPath)) {
                touch($startPath);
            }

            foreach ($workers as $worker) {
                $this->stopLifecycleWorker($worker);
            }

            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }

            rmdir($directory);
        }
    }

    /**
     * @param  array<string, int|string>  $payload
     * @return array{process: resource, stdout: resource, stderr: resource, ready_path: string}
     */
    private function startLifecycleWorker(string $readyPath, string $startPath, array $payload): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/bootstrap-administrator-worker.php'), $readyPath, $startPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start an independent bootstrap worker.');
        }

        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'ready_path' => $readyPath,
        ];
    }

    /**
     * @param  array<int, array{process: resource, stdout: resource, stderr: resource, ready_path: string}>  $workers
     */
    private function waitForWorkersToBeReady(array $workers): void
    {
        $deadline = hrtime(true) + 15_000_000_000;

        while (hrtime(true) < $deadline) {
            if (array_all($workers, static fn (array $worker): bool => is_file($worker['ready_path']))) {
                return;
            }

            usleep(10_000);
        }

        $this->fail('Independent lifecycle workers did not become ready within 15 seconds.');
    }

    private function waitForWorkersToContendOnAdministratorLock(Connection $connection, string $applicationName): void
    {
        $deadline = hrtime(true) + 15_000_000_000;

        do {
            $waitingWorkers = (int) $connection->scalar(
                'select count(*) from pg_stat_activity where application_name = ? and wait_event_type = ?',
                [$applicationName, 'Lock'],
            );

            if ($waitingWorkers === 2) {
                return;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        $this->fail('Both independent workers did not reach the PostgreSQL advisory lock wait within 15 seconds.');
    }

    /**
     * @param  array{process: resource, stdout: resource, stderr: resource, ready_path: string}  $worker
     * @return array{output: string, exit_code: int}
     */
    private function finishLifecycleWorker(array $worker): array
    {
        $deadline = hrtime(true) + 15_000_000_000;

        do {
            $status = proc_get_status($worker['process']);

            if (! $status['running']) {
                break;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($worker['process']);
            $this->fail('An independent lifecycle worker exceeded its 15 second timeout.');
        }

        $exitCode = $status['exitcode'];
        $output = trim(stream_get_contents($worker['stdout']));
        $error = trim(stream_get_contents($worker['stderr']));
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        proc_close($worker['process']);

        if ($error !== '') {
            $this->fail('An independent bootstrap worker failed: '.$error);
        }

        return ['output' => $output, 'exit_code' => $exitCode];
    }

    /**
     * @param  array{process: resource, stdout: resource, stderr: resource, ready_path: string}  $worker
     */
    private function stopLifecycleWorker(array $worker): void
    {
        if (is_resource($worker['process'])) {
            $status = proc_get_status($worker['process']);

            if ($status['running']) {
                proc_terminate($worker['process']);
            }

            proc_close($worker['process']);
        }

        foreach (['stdout', 'stderr'] as $pipe) {
            if (is_resource($worker[$pipe])) {
                fclose($worker[$pipe]);
            }
        }
    }
}
