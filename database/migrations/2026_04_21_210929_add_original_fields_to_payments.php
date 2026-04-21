<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('original_amount', 25, 10)
                ->nullable()
                ->after('amount');

            $table->decimal('original_principal_payment', 25, 10)
                ->default(0)
                ->after('principal_payment');

            $table->decimal('original_interest_payment', 25, 10)
                ->default(0)
                ->after('interest_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'original_amount',
                'original_principal_payment',
                'original_interest_payment',
            ]);
        });
    }
};
