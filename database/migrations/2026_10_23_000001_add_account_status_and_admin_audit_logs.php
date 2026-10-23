<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')->update([
            'email' => DB::raw('lower(btrim(email))'),
        ]);

        DB::statement(<<<'SQL'
            alter table users
            alter column email_verified_at type timestamp with time zone
                using email_verified_at at time zone 'UTC',
            alter column created_at type timestamp with time zone
                using created_at at time zone 'UTC',
            alter column updated_at type timestamp with time zone
                using updated_at at time zone 'UTC'
            SQL);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 16)->default('member');
            $table->timestampTz('disabled_at')->nullable();
            $table->index('role');
            $table->index('disabled_at');
        });

        DB::statement('alter table users add constraint users_email_canonical_check check (email = lower(btrim(email)))');
        DB::statement("alter table users add constraint users_role_check check (role in ('admin', 'member'))");

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->foreignId('target_user_id')->constrained('users');
            $table->string('action', 100);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['target_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        DB::statement('alter table users drop constraint users_role_check');
        DB::statement('alter table users drop constraint users_email_canonical_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropIndex(['disabled_at']);
            $table->dropColumn(['role', 'disabled_at']);
        });

        DB::statement(<<<'SQL'
            alter table users
            alter column email_verified_at type timestamp without time zone
                using email_verified_at at time zone 'UTC',
            alter column created_at type timestamp without time zone
                using created_at at time zone 'UTC',
            alter column updated_at type timestamp without time zone
                using updated_at at time zone 'UTC'
            SQL);
    }
};
