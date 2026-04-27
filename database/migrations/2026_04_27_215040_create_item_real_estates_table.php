<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_real_estates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id')->unique();

            $table->string('certificate_number')->nullable();
            $table->string('certificate_password')->nullable();
            $table->string('cadastral_code')->nullable();
            $table->decimal('area_sqm', 10, 6)->nullable();
            $table->string('appraiser_company')->nullable();    // Անկախ գնահատող ընկերության անվանումը
            $table->string('appraisal_report_number')->nullable(); // Գնահատման հաշվետվության համարը
            $table->date('appraisal_date')->nullable();         // Գնահատման ամսաթիվը
            $table->decimal('appraised_value', 15, 6)->nullable(); // Գնահատված արժեքը
            $table->string('unified_reference_number')->nullable(); // Միասնական տեղեկանքի համար
            $table->string('unified_reference_password')->nullable(); // Միասնական տեղեկանքի գաղտնաբառը
            $table->boolean('is_joint')->default(false);        // Համատեղ

            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_real_estates');
    }
};
