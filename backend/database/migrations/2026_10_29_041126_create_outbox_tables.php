<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $t->string('event_type', 64);
            $t->smallInteger('schema_version');
            $t->string('aggregate_type', 32);
            $t->string('aggregate_id', 64);
            $t->jsonb('payload');
            // clock_timestamp() = actual insert time, taken after the account locks are held.
            $t->timestampTz('occurred_at')->default(DB::raw('clock_timestamp()'));
            $t->timestampTz('published_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
        });

        // The publisher scans only unpublished rows, so index only those.
        DB::statement('CREATE INDEX outbox_unpublished_idx ON outbox_events (id) WHERE published_at IS NULL');

        // At most one posted-event per transaction, enforced by the database.
        DB::statement("CREATE UNIQUE INDEX outbox_one_posted_event_per_tx
            ON outbox_events (aggregate_id) WHERE event_type = 'transaction.posted'");

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION outbox_guard() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'DELETE on outbox_events is not allowed: outbox is append-only';
            END IF;

            IF NEW.id <> OLD.id OR NEW.event_id <> OLD.event_id OR NEW.event_type <> OLD.event_type
               OR NEW.schema_version <> OLD.schema_version OR NEW.aggregate_type <> OLD.aggregate_type
               OR NEW.aggregate_id <> OLD.aggregate_id OR NEW.payload IS DISTINCT FROM OLD.payload
               OR NEW.occurred_at <> OLD.occurred_at THEN
                RAISE EXCEPTION 'outbox_events: only delivery bookkeeping (published_at, attempts, last_error) may change';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER outbox_events_guard
            BEFORE UPDATE OR DELETE ON outbox_events
            FOR EACH ROW EXECUTE FUNCTION outbox_guard();
        SQL);

        Schema::create('consumer_inbox', function (Blueprint $t) {
            $t->string('consumer', 64);
            $t->unsignedBigInteger('event_id');
            $t->timestampTz('received_at')->useCurrent();

            $t->primary(['consumer', 'event_id']);
            $t->foreign('event_id')->references('id')->on('outbox_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_inbox');
        Schema::dropIfExists('outbox_events');
        DB::statement('DROP FUNCTION IF EXISTS outbox_guard()');
    }
};