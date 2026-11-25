<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_cases', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id')->unique();       // one case per group
            $t->unsignedBigInteger('account_id');                // opaque id, no foreign key to ledger tables
            $t->string('account_number', 32);
            $t->string('status', 16)->default('open');
            $t->string('owner', 64);
            $t->string('opened_by', 64);
            $t->string('outcome', 32)->nullable();
            $t->text('closing_note')->nullable();
            $t->string('closed_by', 64)->nullable();
            $t->timestampTz('opened_at')->useCurrent();
            $t->timestampTz('closed_at')->nullable();

            $t->foreign('group_id')->references('id')->on('monitoring_alert_groups');
            $t->index(['status', 'id']);
        });

        Schema::create('monitoring_case_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('case_id');
            $t->string('event', 24);
            $t->string('actor', 64);
            $t->text('note')->nullable();
            $t->string('assigned_to', 64)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('case_id')->references('id')->on('monitoring_cases');
            $t->index(['case_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE monitoring_cases
            ADD CONSTRAINT monitoring_cases_status_chk CHECK (status IN ('open','closed')),
            ADD CONSTRAINT monitoring_cases_outcome_chk CHECK (outcome IS NULL OR outcome IN ('no_further_action','escalated_to_compliance')),
            ADD CONSTRAINT monitoring_cases_closed_chk CHECK (
                (status = 'closed') = (outcome IS NOT NULL AND closed_by IS NOT NULL AND closed_at IS NOT NULL
                                       AND length(trim(coalesce(closing_note, ''))) > 0)),
            ADD CONSTRAINT monitoring_cases_open_chk CHECK (
                status <> 'open' OR (outcome IS NULL AND closed_by IS NULL AND closed_at IS NULL AND closing_note IS NULL)),
            ADD CONSTRAINT monitoring_cases_closer_chk CHECK (closed_by IS NULL OR closed_by = owner);

        ALTER TABLE monitoring_case_events
            ADD CONSTRAINT monitoring_case_events_event_chk CHECK (event IN ('opened','assigned','note','account_frozen','account_unfrozen','closed')),
            ADD CONSTRAINT monitoring_case_events_note_chk CHECK (event IN ('opened','assigned') OR length(trim(coalesce(note, ''))) > 0);

        CREATE TRIGGER monitoring_case_events_append_only
            BEFORE UPDATE OR DELETE ON monitoring_case_events
            FOR EACH ROW EXECUTE FUNCTION monitoring_forbid_mutation();

        CREATE OR REPLACE FUNCTION monitoring_case_guard() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'DELETE on monitoring_cases is not allowed: cases are kept';
            END IF;

            IF OLD.status = 'closed' THEN
                RAISE EXCEPTION 'monitoring_cases: closed cases are final';
            END IF;

            IF NEW.id <> OLD.id OR NEW.group_id <> OLD.group_id OR NEW.account_id <> OLD.account_id
               OR NEW.account_number <> OLD.account_number OR NEW.opened_by <> OLD.opened_by
               OR NEW.opened_at <> OLD.opened_at THEN
                RAISE EXCEPTION 'monitoring_cases: only the owner and the closing fields may change';
            END IF;

            IF NEW.status = 'closed' THEN
                IF EXISTS (SELECT 1 FROM monitoring_alerts a WHERE a.group_id = NEW.group_id AND a.status <> 'closed') THEN
                    RAISE EXCEPTION 'monitoring_cases: a case cannot close while its alerts are unresolved';
                END IF;
                IF NEW.outcome = 'no_further_action'
                   AND EXISTS (SELECT 1 FROM monitoring_alerts a WHERE a.group_id = NEW.group_id AND a.disposition = 'confirmed_suspicious') THEN
                    RAISE EXCEPTION 'monitoring_cases: alerts were confirmed suspicious, so the case cannot close as no further action';
                END IF;
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER monitoring_cases_guard
            BEFORE UPDATE OR DELETE ON monitoring_cases
            FOR EACH ROW EXECUTE FUNCTION monitoring_case_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_case_events');
        Schema::dropIfExists('monitoring_cases');
        DB::unprepared('DROP FUNCTION IF EXISTS monitoring_case_guard()');
    }
};