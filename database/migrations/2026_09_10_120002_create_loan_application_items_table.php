<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_application_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_application_id')->constrained('loan_applications')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories');

            // Applicant-submitted subset of the items fields.
            // Gold
            $table->string('subcategory')->nullable();
            $table->string('model')->nullable();
            $table->float('weight')->nullable();
            $table->float('clear_weight')->nullable();
            $table->string('hallmark')->nullable();

            // Car
            $table->string('car_make')->nullable();
            $table->integer('manufacture')->nullable();
            $table->string('power')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('color')->nullable();
            $table->string('registration')->nullable();
            $table->string('identification')->nullable();
            $table->string('ownership')->nullable();

            // All types
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_application_items');
    }
};
