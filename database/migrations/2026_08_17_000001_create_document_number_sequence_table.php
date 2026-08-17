<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backing store for Transaction::getNextDocumentNumber(). A dedicated
     * auto_increment table lets MySQL hand out the next number with its
     * lightweight internal auto-inc lock instead of a `SELECT MAX(...) FOR UPDATE`
     * over the whole transactions table, which was serializing every document
     * creation in the app behind one table-wide lock and causing
     * "Lock wait timeout exceeded" errors.
     */
    public function up()
    {
        Schema::create('document_number_sequence', function (Blueprint $table) {
            $table->id();
        });

        $max = (int) DB::selectOne(
            'SELECT COALESCE(MAX(CAST(document_number AS UNSIGNED)), 0) AS m FROM transactions'
        )->m;

        DB::statement('ALTER TABLE document_number_sequence AUTO_INCREMENT = ' . ($max + 1));
    }

    public function down()
    {
        Schema::dropIfExists('document_number_sequence');
    }
};
