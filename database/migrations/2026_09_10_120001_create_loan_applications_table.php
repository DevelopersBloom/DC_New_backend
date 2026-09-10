<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->enum('loan_type', ['car', 'gold', 'property']);
            $table->enum('status', ['submitted', 'in_review', 'approved', 'rejected', 'converted'])
                ->default('submitted');

            $table->text('comments')->nullable();

            $table->foreignId('pawnshop_id')->constrained('pawnshops');
            $table->foreignId('created_by')->constrained('users');

            // FK added later in add_final_estimate_id_fk_to_loan_applications_table
            $table->unsignedBigInteger('final_estimate_id')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->foreignId('contract_id')->nullable()->constrained('contracts');

            $table->softDeletes();
            $table->timestamps();

            // The review queue filters on both.
            $table->index(['status', 'pawnshop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};
