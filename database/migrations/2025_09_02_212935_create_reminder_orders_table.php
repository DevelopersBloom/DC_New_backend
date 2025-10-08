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
        Schema::create('reminder_orders', function (Blueprint $table) {
            $table->id();
            $table->date('order_date')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('debit_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('credit_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('debit_partner_id')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();

            $table->foreignId('credit_partner_id')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();
            $table->boolean('is_draft')->default(false);
            $table->unsignedInteger('num');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('reminder_orders');
    }
};
