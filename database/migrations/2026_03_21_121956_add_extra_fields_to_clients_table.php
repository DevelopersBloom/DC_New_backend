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
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->enum('gender', ['MALE', 'FEMALE'])->after('type')->nullable();
            $table->enum('document_type', [
                'NON_BIOMETRIC_PASSPORT',
                'ID_CARD',
                'BIOMETRIC_PASSPORT',
                'BIRTH_CERTIFICATE',
                'RESIDENCE_CARD',
                'TRAVEL_DOCUMENT',
                'FOREIGN_PASSPORT',
                'OTHER'
            ])->after('social_card_number')->nullable();

            $table->string('legal_country')->nullable();
            $table->string('legal_province')->nullable(); // Մարզ
            $table->string('legal_community')->nullable();// Համայնք
            $table->string('legal_settlement')->nullable();// Շրջան/բնակավայր
            $table->string('legal_street_building')->nullable(); // Փողոց/շենք
            $table->string('legal_zip_code')->nullable(); // Ծածկագիր

            $table->string('actual_country')->nullable();
            $table->string('actual_province')->nullable();
            $table->string('actual_community')->nullable();
            $table->string('actual_settlement')->nullable();
            $table->string('actual_street_building')->nullable();
            $table->string('actual_zip_code')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'document_type',
                'legal_country', 'legal_province', 'legal_community',
                'legal_settlement', 'legal_street_building', 'legal_zip_code',
                'actual_country', 'actual_province', 'actual_community',
                'actual_settlement', 'actual_street_building', 'actual_zip_code'
            ]);
        });
    }
};
