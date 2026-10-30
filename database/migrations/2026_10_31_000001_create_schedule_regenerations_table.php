<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_regenerations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('effective_date');
            $table->unsignedBigInteger('current_payment_id');
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->string('reason', 500)->nullable();
            $table->decimal('balance', 18, 2);
            $table->decimal('old_total_interest', 18, 2)->default(0);
            $table->decimal('new_total_interest', 18, 2)->default(0);
            $table->decimal('old_monthly', 18, 2)->default(0);
            $table->decimal('new_monthly', 18, 2)->default(0);
            $table->date('old_last_date')->nullable();
            $table->date('new_last_date')->nullable();
            $table->json('removed_payment_ids');
            $table->json('created_payment_ids');
            $table->json('moved_entries')->nullable();
            $table->json('current_row_before');
            $table->json('old_payment_schedule')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->unsignedBigInteger('reverted_by')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'reverted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_regenerations');
    }
};
