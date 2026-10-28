<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 64);              // e.g. 'ledger.post'
            $t->string('key', 255);
            $t->char('request_hash', 64);         // same key + different payload = error
            $t->jsonb('response')->nullable();
            $t->uuid('transaction_id')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['scope', 'key']);
            $t->foreign('transaction_id')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};