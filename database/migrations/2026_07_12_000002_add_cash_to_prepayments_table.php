<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepayments', function (Blueprint $table) {
            $table->boolean('cash')->default(false)->after('partial_amount');
        });
    }

    public function down(): void
    {
        Schema::table('prepayments', function (Blueprint $table) {
            $table->dropColumn('cash');
        });
    }
};
