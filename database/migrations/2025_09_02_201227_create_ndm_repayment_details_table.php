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
        Schema::create('ndm_repayment_details', function (Blueprint $table) {
            $table->id();

            $table->decimal('interest_unused_part', 20, 2)->nullable();       // չօգտ. մասի տոկոս
            $table->decimal('penalty_overdue_principal', 20, 2)->nullable();   // ժամկետանց գումարի տույժ
            $table->decimal('penalty_overdue_interest', 20, 2)->nullable();    // ժամկետանց տոկոսի տույժ

            $table->decimal('tax_total', 20, 2)->nullable();                   // ընդհանուր հարկ
            $table->decimal('tax_from_interest', 20, 2)->nullable();           // հարկ տոկոսագումարից
            $table->decimal('tax_from_penalty_pr', 20, 2)->nullable();         // հարկ ժամկետանց գումարի տույժից
            $table->decimal('tax_from_penalty_int', 20, 2)->nullable();        // հարկ ժամկետանց տոկոսի տույժից

            $table->decimal('total_amount', 20, 2)->default(0);                // ընդհանուր գումար (այս դետալ գրառման)

            $table->foreignId('account_id')
                ->nullable()->constrained('chart_of_accounts')->nullOnDelete();

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
        Schema::dropIfExists('ndm_repayment_details');
    }
};
