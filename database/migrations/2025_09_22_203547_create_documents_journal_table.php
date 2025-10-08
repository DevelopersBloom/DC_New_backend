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
        Schema::create('documents_journal', function (Blueprint $table) {
            $table->id();

            $table->date('date');

            $table->integer('operation_number')->nullable();             // Գործողության օր

            $table->string('document_number')->nullable();
            $table->string('document_type');

            $table->decimal('amount_amd', 20, 2)->default(0);
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->decimal('amount_currency', 20, 2)->nullable();

            $table->foreignId('partner_id')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();
            $table->foreignId('credit_partner_id')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();

            $table->foreignId('debit_account_id')                   // դեբետ հաշիվ
            ->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('credit_account_id')                  // կրեդիտ հաշիվ
            ->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('cash')->default(false);

            $table->foreignId('ndm_repayment_id')
                ->nullable()
                ->constrained('ndm_repayment_details')
                ->nullOnDelete();

            $table->foreignId('pawnshop_id')
                ->nullable()->constrained('pawnshops');

            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->morphs('journalable');

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
        Schema::dropIfExists('documents_journal');
    }
};
