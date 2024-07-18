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

        DB::table('contracts')
            ->where(function ($query) {
                $query->whereNull('close_date')->where('status', '!=', 'executed');
            })
            ->orWhere('status', 'initial')
            ->whereDate(DB::raw("STR_TO_DATE(deadline, '%d.%m.%Y')"), '<', now()->format('Y-m-d'))
            ->update(['status' => 'overdue']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('contracts')
            ->where(function ($query) {
                $query->where('status', 'overdue')
                    ->whereNull('close_date');
            })
            ->update(['status' => 'initial']);

        DB::table('contracts')
            ->where(function ($query) {
                $query->where('status', 'overdue')
                    ->whereNotNull('close_date');
            })
            ->update(['status' => 'completed']);
    }
};
