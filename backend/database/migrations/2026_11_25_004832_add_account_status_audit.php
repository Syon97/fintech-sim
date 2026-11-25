<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_status_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('from_status', 16);
            $t->string('to_status', 16);
            $t->string('actor', 64);
            $t->text('reason');
            $t->bigInteger('txid')->default(DB::raw('txid_current()')); // which transaction wrote this row
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('account_id')->references('id')->on('accounts');
            $t->index(['account_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE account_status_changes
            ADD CONSTRAINT status_change_values_chk CHECK (from_status IN ('active','frozen','closed') AND to_status IN ('active','frozen')),
            ADD CONSTRAINT status_change_differs_chk CHECK (from_status <> to_status),
            ADD CONSTRAINT status_change_reason_chk CHECK (length(trim(reason)) > 0);

        CREATE TRIGGER account_status_changes_append_only
            BEFORE UPDATE OR DELETE ON account_status_changes
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation();

        -- No status change may commit without its audit record from the SAME transaction.
        CREATE OR REPLACE FUNCTION accounts_status_audited() RETURNS trigger AS $$
        BEGIN
            IF NEW.status IS DISTINCT FROM OLD.status AND NOT EXISTS (
                SELECT 1 FROM account_status_changes c
                 WHERE c.account_id = NEW.id AND c.to_status = NEW.status AND c.txid = txid_current()
            ) THEN
                RAISE EXCEPTION 'accounts: status change on account % has no audit record (use AccountService)', NEW.id;
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        CREATE CONSTRAINT TRIGGER accounts_status_audited
            AFTER UPDATE ON accounts
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION accounts_status_audited();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS accounts_status_audited ON accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS accounts_status_audited()');
        Schema::dropIfExists('account_status_changes');
    }
};