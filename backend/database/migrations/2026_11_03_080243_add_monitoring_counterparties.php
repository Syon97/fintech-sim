<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_counterparties', function (Blueprint $t) {
            $t->unsignedBigInteger('account_id');                 // opaque ids, no foreign keys to ledger tables
            $t->string('account_number', 32);
            $t->unsignedBigInteger('counterparty_account_id');
            $t->string('counterparty_account_number', 32);
            $t->unsignedBigInteger('first_seen_event_id');
            $t->timestampTz('first_seen_at');

            $t->primary(['account_id', 'counterparty_account_id']);
            $t->foreign('first_seen_event_id')->references('id')->on('outbox_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_counterparties');
    }
};