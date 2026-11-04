<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_rule_change_requests', function (Blueprint $t) {
            $t->id();
            $t->string('rule_code', 64);
            $t->string('change_type', 16);                 // define | retire
            $t->string('rule_type', 32)->nullable();       // proposal fields: null for retire
            $t->string('name')->nullable();
            $t->text('rationale')->nullable();
            $t->jsonb('parameters')->nullable();
            $t->string('severity', 16)->nullable();
            $t->text('reason');                            // why the maker wants this change
            $t->string('status', 16)->default('pending');
            $t->string('maker', 64);
            $t->string('checker', 64)->nullable();
            $t->text('decision_note')->nullable();
            $t->unsignedBigInteger('version_id')->nullable(); // define: the version created. retire: the version retired.
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('decided_at')->nullable();

            $t->foreign('version_id')->references('id')->on('monitoring_rule_versions');
            $t->index(['rule_code', 'id']);
        });

        DB::statement("ALTER TABLE monitoring_rule_change_requests
            ADD CONSTRAINT mrcr_status_chk CHECK (status IN ('pending','approved','rejected','withdrawn')),
            ADD CONSTRAINT mrcr_type_chk CHECK (change_type IN ('define','retire')),
            ADD CONSTRAINT mrcr_severity_chk CHECK (severity IS NULL OR severity IN ('low','medium','high','critical')),
            ADD CONSTRAINT mrcr_define_fields_chk CHECK (change_type <> 'define' OR (rule_type IS NOT NULL AND name IS NOT NULL AND rationale IS NOT NULL AND parameters IS NOT NULL AND severity IS NOT NULL)),
            ADD CONSTRAINT mrcr_reason_chk CHECK (length(trim(reason)) > 0),
            ADD CONSTRAINT mrcr_four_eyes_chk CHECK (checker IS NULL OR checker <> maker),
            ADD CONSTRAINT mrcr_decision_chk CHECK ((status IN ('approved','rejected')) = (checker IS NOT NULL)),
            ADD CONSTRAINT mrcr_approved_version_chk CHECK ((status = 'approved') = (version_id IS NOT NULL)),
            ADD CONSTRAINT mrcr_decided_at_chk CHECK ((status = 'pending') = (decided_at IS NULL)),
            ADD CONSTRAINT mrcr_reject_note_chk CHECK (status <> 'rejected' OR length(trim(coalesce(decision_note, ''))) > 0)");

        // At most one open request per rule, enforced by the database.
        DB::statement("CREATE UNIQUE INDEX mrcr_one_pending_per_rule
            ON monitoring_rule_change_requests (rule_code) WHERE status = 'pending'");

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION monitoring_rule_change_guard() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'DELETE on monitoring_rule_change_requests is not allowed: change history is kept';
            END IF;

            IF OLD.status <> 'pending' THEN
                RAISE EXCEPTION 'monitoring_rule_change_requests: decided requests are final';
            END IF;

            IF NEW.id <> OLD.id OR NEW.rule_code <> OLD.rule_code OR NEW.change_type <> OLD.change_type
               OR NEW.rule_type IS DISTINCT FROM OLD.rule_type OR NEW.name IS DISTINCT FROM OLD.name
               OR NEW.rationale IS DISTINCT FROM OLD.rationale OR NEW.parameters IS DISTINCT FROM OLD.parameters
               OR NEW.severity IS DISTINCT FROM OLD.severity OR NEW.reason <> OLD.reason
               OR NEW.maker <> OLD.maker OR NEW.created_at <> OLD.created_at THEN
                RAISE EXCEPTION 'monitoring_rule_change_requests: only decision fields (status, checker, decision_note, version_id, decided_at) may change';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER monitoring_rule_change_requests_guard
            BEFORE UPDATE OR DELETE ON monitoring_rule_change_requests
            FOR EACH ROW EXECUTE FUNCTION monitoring_rule_change_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_rule_change_requests');
        DB::statement('DROP FUNCTION IF EXISTS monitoring_rule_change_guard()');
    }
};