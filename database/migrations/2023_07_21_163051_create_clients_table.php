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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('surname');
            $table->string('middle_name')->nullable();
            $table->string('passport_series')->unique();
            $table->date('passport_validity');
            $table->string('passport_issued');
            $table->date('date_of_birth');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('additional_phone')->nullable();
            $table->string('country');
            $table->string('city');
            $table->string('street');
            $table->string('building')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('card_number')->nullable();
            $table->string('iban')->nullable();

            $table->softDeletes();
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
        Schema::dropIfExists('clients');
    }
};
