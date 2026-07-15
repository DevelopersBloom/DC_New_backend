<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->integer('prepayment')->nullable()->after('interest_amount');
            $table->integer('principal_amount')->nullable()->after('prepayment');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['prepayment', 'principal_amount']);
        });
    }
};
