<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('
            UPDATE payments
            JOIN contracts ON payments.contract_id = contracts.id
            SET
                payments.name = contracts.name,
                payments.surname = contracts.surname,
                payments.passport = contracts.passport,
                payments.phone = contracts.phone1,
                payments.updated_at = ?
        ', [Carbon::now()]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('
            UPDATE payments
            SET
                name = NULL,
                surname = NULL,
                passport = NULL,
                phone = NULL,
                updated_at = updated_at
        ');
    }
};
