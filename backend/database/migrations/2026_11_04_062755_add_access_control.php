<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NOT NULL unique column: this assumes the users table is empty (it is, unless
        // you created users yourself; if so, run php artisan migrate:fresh first).
        Schema::table('users', function (Blueprint $t) {
            $t->string('username', 64)->unique();
            $t->string('role', 16)->default('analyst');
            $t->boolean('active')->default(true);
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE users
            ADD CONSTRAINT users_role_chk CHECK (role IN ('analyst','supervisor','rule_admin')),
            ADD CONSTRAINT users_username_chk CHECK (username ~ '^[a-z0-9._-]{3,64}$');

        CREATE OR REPLACE FUNCTION users_guard() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'DELETE on users is not allowed: deactivate the account instead';
            END IF;
            IF NEW.username <> OLD.username THEN
                RAISE EXCEPTION 'users: username cannot change, because history refers to it';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER users_guard
            BEFORE UPDATE OR DELETE ON users
            FOR EACH ROW EXECUTE FUNCTION users_guard();
        SQL);

        Schema::create('auth_events', function (Blueprint $t) {
            $t->id();
            $t->string('event', 16);                       // login | login_failed | logout
            $t->string('username', 64)->nullable();        // as typed: not trusted for failed logins
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['username', 'id']);
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE auth_events
            ADD CONSTRAINT auth_events_event_chk CHECK (event IN ('login','login_failed','logout'));

        CREATE OR REPLACE FUNCTION auth_forbid_mutation() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION '% on % is not allowed: audit trail is append-only', TG_OP, TG_TABLE_NAME;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER auth_events_append_only
            BEFORE UPDATE OR DELETE ON auth_events
            FOR EACH ROW EXECUTE FUNCTION auth_forbid_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
        DB::unprepared('DROP FUNCTION IF EXISTS auth_forbid_mutation()');
        DB::unprepared('DROP TRIGGER IF EXISTS users_guard ON users');
        DB::unprepared('DROP FUNCTION IF EXISTS users_guard()');
        DB::unprepared('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_chk, DROP CONSTRAINT IF EXISTS users_username_chk');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['username', 'role', 'active']);
        });
    }
};