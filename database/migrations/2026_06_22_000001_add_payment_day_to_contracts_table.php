<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Day of month (1-28) the client wants their recurring payment to fall on.
            // When set, createAnnuityPayment generates a broken-period row #0 and
            // anchors every subsequent installment to this day of the month.
            $table->tinyInteger('payment_day')->unsigned()->nullable()->after('payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('payment_day');
        });
    }
};
