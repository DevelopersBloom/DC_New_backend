<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepayments', function (Blueprint $table) {
            $table->decimal('principal_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('interest_amount', 15, 2)->default(0)->after('principal_amount');
        });

        // Existing rows were principal-only prepayments.
        DB::table('prepayments')->update(['principal_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('prepayments', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'interest_amount']);
        });
    }
};
