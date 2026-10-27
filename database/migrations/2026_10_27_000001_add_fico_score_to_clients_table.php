<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Latest FICO score issued by ACRA for the client (300-850), with the date ACRA issued it. */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('fico_score')->nullable()->after('acra_classification_id');
            $table->date('fico_score_date')->nullable()->after('fico_score');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['fico_score', 'fico_score_date']);
        });
    }
};
