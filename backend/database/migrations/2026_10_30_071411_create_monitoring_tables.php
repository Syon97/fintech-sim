<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_rules', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64)->unique();
            $t->string('type', 32);              // validated by the rule registry, not a DB CHECK
            $t->string('name');
            $t->text('rationale');               // the business reason: feeds the rule catalogue
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('monitoring_rule_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('rule_id');
            $t->unsignedInteger('version');
            $t->jsonb('parameters');
            $t->string('severity', 16);
            $t->string('status', 16)->default('draft');   // draft | active | retired
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('activated_at')->nullable();

            $t->foreign('rule_id')->references('id')->on('monitoring_rules');
            $t->unique(['rule_id', 'version']);
        });

        DB::statement("ALTER TABLE monitoring_rule_versions ADD CONSTRAINT mrv_severity_chk
            CHECK (severity IN ('low','medium','high','critical'))");
        DB::statement("ALTER TABLE monitoring_rule_versions ADD CONSTRAINT mrv_status_chk
            CHECK (status IN ('draft','active','retired'))");
        // At most one active version per rule, enforced by the database.
        DB::statement("CREATE UNIQUE INDEX mrv_one_active_per_rule
            ON monitoring_rule_versions (rule_id) WHERE status = 'active'");

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION monitoring_rule_version_guard() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'DELETE on monitoring_rule_versions is not allowed: rule history is kept';
            END IF;

            IF NEW.id <> OLD.id OR NEW.rule_id <> OLD.rule_id OR NEW.version <> OLD.version
               OR NEW.severity <> OLD.severity OR NEW.parameters IS DISTINCT FROM OLD.parameters
               OR NEW.created_at <> OLD.created_at THEN
                RAISE EXCEPTION 'monitoring_rule_versions: parameters are immutable; create a new version instead';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER monitoring_rule_versions_guard
            BEFORE UPDATE OR DELETE ON monitoring_rule_versions
            FOR EACH ROW EXECUTE FUNCTION monitoring_rule_version_guard();
        SQL);

        // Monitoring's own copy of what it needs, built from events only.
        Schema::create('monitoring_activity', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('event_id');
            $t->unsignedSmallInteger('entry_index');
            $t->uuid('transaction_id');
            $t->unsignedBigInteger('account_id');      // opaque id: deliberately NO foreign key to ledger tables
            $t->string('account_number', 32);
            $t->string('account_type', 16);
            $t->char('direction', 1);
            $t->bigInteger('amount');
            $t->char('currency', 3);
            $t->timestampTz('occurred_at');

            $t->foreign('event_id')->references('id')->on('outbox_events');
            $t->unique(['event_id', 'entry_index']);
            $t->index(['account_id', 'occurred_at', 'event_id']);
        });

        Schema::create('monitoring_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('rule_version_id');
            $t->string('dedupe_key', 128);
            $t->unsignedBigInteger('event_id');
            $t->uuid('transaction_id');
            $t->unsignedBigInteger('account_id');
            $t->string('account_number', 32);
            $t->string('severity', 16);
            $t->string('status', 16)->default('open');
            $t->text('explanation');
            $t->jsonb('details');
            $t->timestampTz('event_occurred_at');
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('rule_version_id')->references('id')->on('monitoring_rule_versions');
            $t->foreign('event_id')->references('id')->on('outbox_events');
            // Exactly-once alerts, even if an event is delivered or replayed again.
            $t->unique(['rule_version_id', 'dedupe_key']);
            $t->index(['account_id', 'event_occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alerts');
        Schema::dropIfExists('monitoring_activity');
        Schema::dropIfExists('monitoring_rule_versions');
        Schema::dropIfExists('monitoring_rules');
        DB::statement('DROP FUNCTION IF EXISTS monitoring_rule_version_guard()');
    }
};