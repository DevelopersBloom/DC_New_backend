<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            // Collateral stage: applicant details taken at intake, and the amount the
            // admin settles on after seeing the participants' estimates (and their average).
            $table->unsignedTinyInteger('client_age')->nullable()->after('comments');
            $table->decimal('client_income', 15, 2)->nullable()->after('client_age');
            $table->decimal('final_estimated_amount', 15, 2)->nullable()->after('final_estimate_id');

            // Loan review stage, filled by the admin.
            $table->boolean('is_employed')->nullable()->after('provided_note');
            $table->string('employer_name')->nullable()->after('is_employed');
            $table->string('employer_position')->nullable()->after('employer_name');
            $table->unsignedInteger('employment_tenure_months')->nullable()->after('employer_position');
            $table->decimal('employment_salary', 15, 2)->nullable()->after('employment_tenure_months');
            $table->boolean('owns_property')->nullable()->after('employment_salary');
            $table->string('owned_property_info')->nullable()->after('owns_property');
            $table->text('loan_purpose')->nullable()->after('owned_property_info');
            $table->decimal('requested_amount', 15, 2)->nullable()->after('loan_purpose');
            $table->string('acra_score', 50)->nullable()->after('requested_amount');
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'client_age', 'client_income', 'final_estimated_amount',
                'is_employed', 'employer_name', 'employer_position', 'employment_tenure_months',
                'employment_salary', 'owns_property', 'owned_property_info', 'loan_purpose',
                'requested_amount', 'acra_score',
            ]);
        });
    }
};
