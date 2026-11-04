<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_backtests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('change_request_id')->nullable();
            $t->string('rule_code', 64);
            $t->jsonb('parameters');
            $t->string('severity', 16);
            $t->string('requested_by', 64);
            $t->jsonb('result');
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('change_request_id')->references('id')->on('monitoring_rule_change_requests');
            $t->index(['change_request_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
        CREATE TRIGGER monitoring_backtests_append_only
            BEFORE UPDATE OR DELETE ON monitoring_backtests
            FOR EACH ROW EXECUTE FUNCTION monitoring_forbid_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_backtests');
    }
};