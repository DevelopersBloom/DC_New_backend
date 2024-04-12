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
        Schema::create('pawnshop_configs', function (Blueprint $table) {
            $table->id();
            $table->boolean('cashboxes_calculated')->default(false);
            $table->boolean('online_cashbox_set')->default(false);
            $table->boolean('orders_set')->default(false);
            $table->integer('pawnshop_id');
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
        Schema::dropIfExists('pawnshop_configs');
    }
};
