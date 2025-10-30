<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('contract_reserve_histories', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('client_id')
                ->nullable()
                ->constrained('clients')
                ->onDelete('set null');

            $table->foreignId('classification_id')
                ->nullable()
                ->constrained('clients_classification')
                ->onDelete('set null');

            $table->foreignId('contract_id')
                ->nullable()
                ->constrained('contracts')
                ->onDelete('cascade');

            $table->decimal('risk_weight', 10, 2)->nullable();
            $table->decimal('reserve_percent', 10, 2)->nullable();
            $table->decimal('reserve_amount', 18, 10)->nullable();
            $table->decimal('total_reserve_amount', 18, 10)->nullable();
            $table->decimal('provided_amount', 18, 10)->nullable();

            $table->date('date')->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->json('meta')->nullable()->comment('Additional metadata about reserve history');

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('contract_reserve_histories');
    }
};
