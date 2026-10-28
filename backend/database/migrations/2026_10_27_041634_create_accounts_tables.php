<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('account_number', 32)->unique();
            $t->string('name');
            $t->string('type', 16);          // asset|liability|equity|income|expense
            $t->char('normal_side', 1);      // D or C
            $t->char('currency', 3);
            $t->string('status', 16)->default('active'); // active|frozen|closed
            $t->boolean('allow_negative')->default(false);
            $t->timestamps();

            // Needed so entries can reference (id, currency) together.
            $t->unique(['id', 'currency']);
        });

        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_chk
            CHECK (type IN ('asset','liability','equity','income','expense'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_status_chk
            CHECK (status IN ('active','frozen','closed'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_normal_side_chk
            CHECK (
                (type IN ('asset','expense') AND normal_side = 'D') OR
                (type IN ('liability','equity','income') AND normal_side = 'C')
            )");

        Schema::create('account_balances', function (Blueprint $t) {
            $t->unsignedBigInteger('account_id')->primary();
            $t->bigInteger('balance')->default(0);   // minor units (sen)
            $t->unsignedBigInteger('version')->default(0);
            $t->timestamp('updated_at')->useCurrent();
            $t->foreign('account_id')->references('id')->on('accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('accounts');
    }
};