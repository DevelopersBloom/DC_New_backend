<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_period_locks', function (Blueprint $table) {
            $table->id();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('is_closed')->default(true);
            $table->string('reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['from_date', 'to_date', 'is_closed'], 'apl_from_to_closed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_locks');
    }
};
