<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            alter table admin_audit_logs
            add constraint admin_audit_logs_action_check check (action in (
                'admin.bootstrap',
                'admin.password_reset',
                'admin.account_created',
                'admin.account_disabled'
            ))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('alter table admin_audit_logs drop constraint admin_audit_logs_action_check');
    }
};
