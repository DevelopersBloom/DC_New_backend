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
            $table->unsignedBigInteger('client_id');
            $table->decimal('estimated_amount');
            $table->decimal('provided_amount');
            $table->decimal('interest_rate')->nullable();
            $table->decimal('penalty')->nullable();
            $table->date('deadline');
            $table->decimal('lump_rate')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['initial', 'completed', 'executed'])->default('initial');
            $table->unsignedBigInteger('pawnshop_id');
            $table->integer('mother')->nullable();
            $table->integer('left')->nullable();
            $table->integer('collected')->nullable();
            $table->integer('penalty_amount')->default(0);
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('pawnshop_id')->references('id')->on('pawnshops')->onDelete('cascade');

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
