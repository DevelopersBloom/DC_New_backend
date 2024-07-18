<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('initial', 'completed', 'executed', 'overdue') DEFAULT 'initial'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('initial', 'completed', 'executed') DEFAULT 'initial'");
    }
};
