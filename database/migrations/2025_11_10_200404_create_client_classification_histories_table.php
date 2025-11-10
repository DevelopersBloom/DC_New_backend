<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_classification_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classification_id')->nullable()->constrained('client_classification')->nullOnDelete();

            $table->decimal('risk_weight',5,2)->nullable();
            $table->decimal('reserve_percent', 5, 2)->nullable();
            $table->text('comment')->nullable();
            $table->morphs('actionable');
            $table->json('meta')->nullable();
            $table->timestamp('date')->default(now());

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_classification_histories');
    }
};
