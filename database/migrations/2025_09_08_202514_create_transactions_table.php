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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('document_number')->nullable();
            $table->string('document_type')->nullable();

            $table->foreignId('debit_account_id')->nullable()->constrained('chart_of_accounts');
            $table->string('debit_partner_code')->nullable();
            $table->string('debit_partner_name')->nullable();
            $table->foreignId('debit_currency_id')->nullable()->constrained('currencies');

            $table->foreignId('credit_account_id')->nullable()->constrained('chart_of_accounts');
            $table->string('credit_partner_code')->nullable();
            $table->string('credit_partner_name')->nullable();
            $table->foreignId('credit_currency_id')->nullable()->constrained('currencies');

            $table->decimal('amount_amd', 18, 2);
            $table->decimal('amount_currency', 18, 2)->nullable();
            $table->foreignId('amount_currency_id')->nullable()->constrained('currencies');

            $table->text('comment')->nullable();
            $table->date('disbursement_date')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->boolean('is_system')->default(false);

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
        Schema::dropIfExists('transactions');
    }
};
