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
        Schema::create('clients_classification', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('name');
            $table->unsignedInteger('order')->default(1);
            $table->decimal('reserve_percent', 5, 2)->nullable();
            $table->decimal('risk_weight', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('clients_classification');
    }
};
