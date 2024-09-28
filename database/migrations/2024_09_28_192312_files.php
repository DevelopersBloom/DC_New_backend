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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('file_type_id');
            $table->unsignedBigInteger('fileable_id'); // ID of the related model
            $table->string('fileable_type'); // Class name of the related model

            // Additional file information
            $table->string('name'); // The stored file name
            $table->string('type'); // The MIME type of the file
            $table->string('original_name'); // The original file name uploaded by the user
            $table->string('doc_type')->default('regular'); // Optional document type, defaulting to 'regular'

            $table->timestamps();

            $table->foreign('file_type_id')->references('id')->on('file_types');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('files');
    }
};
