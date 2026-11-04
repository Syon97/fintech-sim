<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who the alert was handed to, so the timeline can say "Sue reassigned this to Ben".
        Schema::table('monitoring_alert_events', function (Blueprint $t) {
            $t->string('assigned_to', 64)->nullable();
        });

        // Open means nobody has it. Investigating always means somebody does.
        DB::unprepared(<<<'SQL'
        ALTER TABLE monitoring_alerts
            ADD CONSTRAINT monitoring_alerts_assignee_chk CHECK (
                (status <> 'open' OR assigned_to IS NULL)
                AND (status <> 'investigating' OR assigned_to IS NOT NULL)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE monitoring_alerts DROP CONSTRAINT IF EXISTS monitoring_alerts_assignee_chk');
        Schema::table('monitoring_alert_events', function (Blueprint $t) {
            $t->dropColumn('assigned_to');
        });
    }
};