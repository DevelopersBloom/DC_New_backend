<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_application_estimates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_application_id')->constrained('loan_applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');

            $table->decimal('estimated_amount', 15, 2);
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->text('note')->nullable();

            // Previous values, same pattern as deal_actions.history.
            $table->json('history')->nullable();

            $table->timestamps();

            // One live estimate per admin; resubmission updates in place
            // and pushes the prior value into history.
            $table->unique(['loan_application_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_application_estimates');
    }
};
