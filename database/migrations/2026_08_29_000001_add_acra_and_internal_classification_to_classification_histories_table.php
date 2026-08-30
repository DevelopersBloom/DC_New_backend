<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('classification_histories', function (Blueprint $table) {
            $table->foreignId('acra_classification_id')
                ->nullable()
                ->after('classification_id')
                ->constrained('clients_classification')
                ->nullOnDelete();
            $table->foreignId('internal_classification_id')
                ->nullable()
                ->after('acra_classification_id')
                ->constrained('clients_classification')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('classification_histories', function (Blueprint $table) {
            $table->dropForeign(['acra_classification_id']);
            $table->dropForeign(['internal_classification_id']);
            $table->dropColumn(['acra_classification_id', 'internal_classification_id']);
        });
    }
};
