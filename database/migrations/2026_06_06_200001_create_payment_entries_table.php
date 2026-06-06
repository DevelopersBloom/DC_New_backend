<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->onDelete('restrict');

            $table->foreignId('contract_id')
                ->constrained('contracts')
                ->onDelete('restrict');

            $table->foreignId('deal_id')
                ->nullable()
                ->constrained('deals')
                ->nullOnDelete();

            $table->foreignId('pawnshop_id')
                ->constrained('pawnshops')
                ->onDelete('restrict');

            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('restrict');

            $table->string('reference')->unique();

            $table->decimal('amount', 18, 2);
            $table->decimal('principal_amount', 18, 2)->default(0);
            $table->decimal('interest_amount', 18, 2)->default(0);
            $table->decimal('penalty_amount', 18, 2)->default(0);

            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);

            $table->string('document_number')->nullable();
            $table->string('document_type')->nullable();

            $table->date('date');
            $table->boolean('cash')->default(false);

            $table->json('meta_data')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_entries');
    }
};
