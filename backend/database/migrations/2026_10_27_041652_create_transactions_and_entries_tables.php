<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->string('type', 16);          // deposit|withdrawal|transfer|fee|reversal
            $t->string('description')->nullable();
            $t->date('effective_date');
            $t->uuid('reverses_transaction_id')->nullable()->unique(); // reversed at most once
            $t->jsonb('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();

        });

        Schema::table('transactions', function (Blueprint $t) {
            $t->foreign('reverses_transaction_id')->references('id')->on('transactions');
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_chk
            CHECK (type IN ('deposit','withdrawal','transfer','fee','reversal'))");

        Schema::create('entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('transaction_id');
            $t->unsignedBigInteger('account_id');
            $t->char('direction', 1);        // D or C
            $t->bigInteger('amount');        // minor units, always positive
            $t->char('currency', 3);
            $t->bigInteger('balance_after'); // account balance after this entry
            $t->timestamp('created_at')->useCurrent();

            $t->foreign('transaction_id')->references('id')->on('transactions');
            // Composite FK: the entry's currency must match the account's currency.
            $t->foreign(['account_id', 'currency'])->references(['id', 'currency'])->on('accounts');
            $t->index(['account_id', 'id']);
            $t->index('transaction_id');
        });

        DB::statement("ALTER TABLE entries ADD CONSTRAINT entries_direction_chk CHECK (direction IN ('D','C'))");
        DB::statement("ALTER TABLE entries ADD CONSTRAINT entries_amount_chk CHECK (amount > 0)");

        // Append-only: no UPDATE or DELETE, ever. Corrections are reversing entries.
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION '% on % is not allowed: ledger is append-only', TG_OP, TG_TABLE_NAME;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER entries_append_only
            BEFORE UPDATE OR DELETE ON entries
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation();

        CREATE TRIGGER transactions_append_only
            BEFORE UPDATE OR DELETE ON transactions
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
        SQL);

        // Balanced: checked at COMMIT time, so entries can be inserted one by one.
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION assert_transaction_balanced() RETURNS trigger AS $$
        DECLARE d bigint; c bigint;
        BEGIN
            SELECT COALESCE(SUM(amount) FILTER (WHERE direction = 'D'), 0),
                   COALESCE(SUM(amount) FILTER (WHERE direction = 'C'), 0)
              INTO d, c
              FROM entries WHERE transaction_id = NEW.transaction_id;

            IF d <> c THEN
                RAISE EXCEPTION 'Transaction % is unbalanced: debits=% credits=%',
                    NEW.transaction_id, d, c;
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        CREATE CONSTRAINT TRIGGER entries_balanced
            AFTER INSERT ON entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION assert_transaction_balanced();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS entries; DROP TABLE IF EXISTS transactions;
                         DROP FUNCTION IF EXISTS assert_transaction_balanced();
                         DROP FUNCTION IF EXISTS forbid_mutation();');
    }
};