<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_application_item_real_estates', function (Blueprint $table) {
            $table->id();

            // Explicit short names: the auto-generated
            // `loan_application_item_real_estates_loan_application_item_id_*`
            // identifiers exceed MySQL's 64-char limit.
            $table->unsignedBigInteger('loan_application_item_id');
            $table->unique('loan_application_item_id', 'la_item_re_item_id_unique');
            $table->foreign('loan_application_item_id', 'la_item_re_item_id_fk')
                ->references('id')->on('loan_application_items')
                ->cascadeOnDelete();

            // Mirrors item_real_estates minus the appraisal-result fields
            // (appraiser_company, appraisal_report_number, appraisal_date, appraised_value),
            // which belong to the review phase, not applicant submission.
            $table->string('certificate_number')->nullable();
            $table->string('certificate_password')->nullable();
            $table->string('cadastral_code')->nullable();
            $table->decimal('area_sqm', 10, 6)->nullable();
            $table->boolean('is_joint')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_application_item_real_estates');
    }
};
