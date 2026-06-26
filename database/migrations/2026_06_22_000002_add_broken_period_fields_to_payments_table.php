<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Marks the interest-only partial-period row that precedes installment #1
            // when a contract uses a fixed payment_day.
            $table->boolean('is_broken_period')->default(false)->after('type');

            // The raw calendar due date (always the client's chosen day, e.g. the 5th).
            // The existing `date`/`to_date` columns hold the adjusted business due date.
            $table->date('calendar_due_date')->nullable()->after('to_date');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['is_broken_period', 'calendar_due_date']);
        });
    }
};
