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
    public function up()
    {
        Schema::create('documents_journal', function (Blueprint $table) {
            $table->id();

            $table->date('date');
            $table->string('document_number')->nullable();
            $table->string('document_type');

            $table->decimal('amount_amd', 20, 2)->default(0);
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->decimal('amount_currency', 20, 2)->nullable();

            $table->foreignId('partner_id')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');

            $table->morphs('journalable');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('documents_journal');
    }
};
