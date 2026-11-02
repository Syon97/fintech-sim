<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_alert_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');       // opaque id, no foreign key to ledger tables
            $t->string('account_number', 32);
            $t->timestampTz('first_alert_at');
            $t->timestampTz('last_alert_at');
            $t->unsignedInteger('alert_count')->default(0);
            $t->smallInteger('max_score')->default(0);
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['account_id', 'last_alert_at']);
        });

        // Columns are nullable so alerts created in Slice 1 stay valid (they have no score or group).
        Schema::table('monitoring_alerts', function (Blueprint $t) {
            $t->smallInteger('risk_score')->nullable();
            $t->jsonb('score_breakdown')->nullable();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->string('assigned_to', 64)->nullable();
            $t->string('disposition', 32)->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampTz('updated_at')->nullable();

            $t->foreign('group_id')->references('id')->on('monitoring_alert_groups');
            $t->index(['status', 'risk_score']);
        });

        DB::statement("ALTER TABLE monitoring_alerts
            ADD CONSTRAINT monitoring_alerts_status_chk CHECK (status IN ('open','investigating','closed')),
            ADD CONSTRAINT monitoring_alerts_disposition_chk CHECK (disposition IS NULL OR disposition IN ('false_positive','confirmed_suspicious')),
            ADD CONSTRAINT monitoring_alerts_closed_chk CHECK ((status = 'closed') = (disposition IS NOT NULL)),
            ADD CONSTRAINT monitoring_alerts_score_chk CHECK (risk_score IS NULL OR risk_score BETWEEN 0 AND 100)");

        Schema::create('monitoring_alert_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('alert_id');
            $t->string('from_status', 16);
            $t->string('to_status', 16);
            $t->string('actor', 64);
            $t->text('note')->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('alert_id')->references('id')->on('monitoring_alerts');
            $t->index(['alert_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION monitoring_forbid_mutation() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION '% on % is not allowed: audit trail is append-only', TG_OP, TG_TABLE_NAME;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER monitoring_alert_events_append_only
            BEFORE UPDATE OR DELETE ON monitoring_alert_events
            FOR EACH ROW EXECUTE FUNCTION monitoring_forbid_mutation();

        CREATE OR REPLACE FUNCTION monitoring_alert_guard() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'DELETE on monitoring_alerts is not allowed: alerts are kept';
            END IF;

            IF OLD.status = 'closed' THEN
                RAISE EXCEPTION 'monitoring_alerts: closed alerts are final';
            END IF;

            IF NEW.id <> OLD.id OR NEW.rule_version_id <> OLD.rule_version_id OR NEW.dedupe_key <> OLD.dedupe_key
               OR NEW.event_id <> OLD.event_id OR NEW.transaction_id <> OLD.transaction_id
               OR NEW.account_id <> OLD.account_id OR NEW.account_number <> OLD.account_number
               OR NEW.severity <> OLD.severity OR NEW.explanation <> OLD.explanation
               OR NEW.details IS DISTINCT FROM OLD.details OR NEW.event_occurred_at <> OLD.event_occurred_at
               OR NEW.created_at <> OLD.created_at OR NEW.risk_score IS DISTINCT FROM OLD.risk_score
               OR NEW.score_breakdown IS DISTINCT FROM OLD.score_breakdown OR NEW.group_id IS DISTINCT FROM OLD.group_id THEN
                RAISE EXCEPTION 'monitoring_alerts: only workflow fields (status, assigned_to, disposition, closed_at, updated_at) may change';
            END IF;

            IF NEW.status <> OLD.status AND NOT (
                   (OLD.status = 'open' AND NEW.status = 'investigating')
                OR (OLD.status = 'investigating' AND NEW.status IN ('open', 'closed'))) THEN
                RAISE EXCEPTION 'monitoring_alerts: illegal status transition % -> %', OLD.status, NEW.status;
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER monitoring_alerts_guard
            BEFORE UPDATE OR DELETE ON monitoring_alerts
            FOR EACH ROW EXECUTE FUNCTION monitoring_alert_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alert_events');
        DB::statement('DROP TRIGGER IF EXISTS monitoring_alerts_guard ON monitoring_alerts');
        DB::statement('DROP FUNCTION IF EXISTS monitoring_alert_guard()');
        DB::statement('DROP FUNCTION IF EXISTS monitoring_forbid_mutation()');
        DB::statement('ALTER TABLE monitoring_alerts
            DROP CONSTRAINT IF EXISTS monitoring_alerts_status_chk,
            DROP CONSTRAINT IF EXISTS monitoring_alerts_disposition_chk,
            DROP CONSTRAINT IF EXISTS monitoring_alerts_closed_chk,
            DROP CONSTRAINT IF EXISTS monitoring_alerts_score_chk');
        Schema::table('monitoring_alerts', function (Blueprint $t) {
            $t->dropColumn(['risk_score', 'score_breakdown', 'group_id', 'assigned_to', 'disposition', 'closed_at', 'updated_at']);
        });
        Schema::dropIfExists('monitoring_alert_groups');
    }
};