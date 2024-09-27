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
            $table->decimal('estimated_amount', 10, 2);
            $table->decimal('provided_amount', 10, 2);
            $table->unsignedBigInteger('item_id');
            $table->decimal('interest_rate', 5, 2);
            $table->decimal('penalty', 5, 2);
            $table->integer('deadline'); // In days, months, or years
            $table->decimal('lump_sum', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['initial', 'completed', 'executed'])->default('initial');
            $table->unsignedBigInteger('pawnshop_id');

            $table->foreign('item_id')->references('id')->on('items');
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
