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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('client_id');
            $table->decimal('estimated_amount', 15, 2); // Adjust precision and scale for decimal
            $table->decimal('provided_amount', 15, 2); // Adjust precision and scale for decimal
            $table->decimal('interest_rate', 5, 2)->nullable(); // Adjust precision and scale for decimal
            $table->decimal('penalty', 15, 2)->nullable(); // Adjust precision and scale for decimal
            $table->decimal('discount', 15, 2)->nullable(); // Adjust precision and scale for decimal
            $table->date('deadline');
            $table->string('deadline_days')->nullable();
            $table->decimal('lump_rate', 15, 2)->nullable(); // Adjust precision and scale for decimal
            $table->text('description')->nullable();
            $table->enum('status', ['initial', 'completed', 'executed'])->default('initial');
            $table->unsignedBigInteger('pawnshop_id');
            $table->decimal('mother', 15, 2)->nullable(); // Adjust precision and scale for decimal
            $table->decimal('left', 15, 2)->nullable(); // Adjust precision and scale for decimal
            $table->decimal('collected', 15, 2)->nullable(); // Adjust precision and scale for decimal
            $table->integer('penalty_amount')->default(0);
            $table->date('closed_at')->nullable();
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('pawnshop_id')->references('id')->on('pawnshops')->onDelete('cascade');
            $table->date('date')->nullable();
            $table->integer('category_id')->nullable();
            $table->softDeletes();
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
        Schema::dropIfExists('contracts');
    }
};
