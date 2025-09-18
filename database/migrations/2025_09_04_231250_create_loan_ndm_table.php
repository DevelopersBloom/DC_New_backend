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
        Schema::create('loan_ndm', function (Blueprint $table) {
            $table->id();

            $table->string('contract_number');
            $table->foreignId('client_id')->nullable()->constrained('clients');
            $table->string('name');
            $table->foreignId('currency_id')->nullable()->constrained('currencies');

            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('interest_account_id')->nullable()->constrained('chart_of_accounts');
            $table->decimal('amount', 18, 2); // Վարկի գումար
            $table->decimal('income', 18, 2);
            $table->boolean('calculate_first_day')->default(false);

            $table->date('contract_date');
            $table->date('disbursement_date'); // հատկացման ամսաթիվ
            $table->date('maturity_date')->nullable();   // Մարման ժամկետ (վերջնական)
            $table->text('comment')->nullable();
            $table->foreignId('pawnshop_id')->constrained('pawnshops');

            $table->enum('interest_schedule_mode', [
                'fixed_day_of_month',
                'periodicity',
                'last_date'
            ])->default('fixed_day_of_month');

            $table->date('repayment_start_date')->nullable();
            $table->date('repayment_end_date')->nullable();

            $table->enum('day_count_convention', [
                'calendar_year',
                'days_360',
                'fixed_day'
            ])->default('calendar_year');
            $table->enum('access_type', [
                'loan',
                'exchange',
                'overdraft'
            ])->nullable();
            $table->decimal('interest_rate', 9, 4);
            $table->decimal('interest_amount', 18, 2);

            $table->decimal('tax_rate', 9, 4)->nullable(); //հարկի տոկոս
            $table->decimal('effective_interest_rate', 9, 4)->nullable(); //// արդյունավետ տոկոսադրույք (% տարեկան)
            $table->decimal('actual_interest_rate', 9, 6)->nullable();   // փաստացի տոկոսադրուք %
            $table->decimal('effective_interest_amount', 18, 2)->nullable();
            $table->boolean('calculate_effective_amount')->default(false);

            $table->unsignedTinyInteger('interest_day_of_month')->nullable();
            $table->unsignedSmallInteger('interest_periodicity_months')->nullable();
            $table->date('interest_last_date')->nullable();

            $table->string('classification_type')->nullable();
            $table->string('department')->nullable();

            $table->text('notes')->nullable();

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
        Schema::dropIfExists('loan_ndm');
    }
};
