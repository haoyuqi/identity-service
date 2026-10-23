<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_audit_logs_preserve_actor_target_and_creation_time(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->create();
        $event = AdminAuditLog::create([
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => 'user.disabled',
        ]);

        $this->assertTrue($event->actor->is($actor));
        $this->assertTrue($event->target->is($target));
        $this->assertInstanceOf(Carbon::class, $event->created_at);
        $this->assertSame('user.disabled', $event->action);
    }

    public function test_admin_audit_log_created_at_preserves_non_utc_instants(): void
    {
        $target = User::factory()->create();
        $log = new AdminAuditLog;
        $log->target_user_id = $target->id;
        $log->action = 'user.created';
        $log->created_at = Carbon::parse('2026-10-20 12:30:00+09:00');
        $log->save();

        $storedTimestamp = DB::selectOne(
            "select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as value from admin_audit_logs where id = ?",
            [$log->id],
        );

        $this->assertSame('2026-10-20 03:30:00', $storedTimestamp->value);
    }

    public function test_admin_audit_logs_allow_system_actions_without_an_actor(): void
    {
        $target = User::factory()->create();
        $event = AdminAuditLog::create([
            'target_user_id' => $target->id,
            'action' => 'user.provisioned',
        ]);

        $this->assertNull($event->actor);
        $this->assertTrue($event->target->is($target));
    }

    public function test_admin_audit_logs_cannot_be_updated(): void
    {
        $event = $this->createEvent();
        $event->action = 'user.changed';

        $this->expectException(LogicException::class);
        $event->save();
    }

    public function test_admin_audit_logs_cannot_be_deleted(): void
    {
        $event = $this->createEvent();

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_user_deletion_is_restricted_when_admin_audit_logs_reference_the_account(): void
    {
        $actor = User::factory()->admin()->create();
        $event = $this->createEvent($actor);

        $this->assertNotNull($event->id);
        $this->expectException(QueryException::class);
        $actor->delete();
    }

    private function createEvent(?User $actor = null): AdminAuditLog
    {
        return AdminAuditLog::create([
            'actor_id' => $actor?->id,
            'target_user_id' => User::factory()->create()->id,
            'action' => 'user.created',
        ]);
    }
}
