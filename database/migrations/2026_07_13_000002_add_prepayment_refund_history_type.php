<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('history_types')->insert([
            'name'       => 'prepayment_refund',
            'title'      => 'Կանխավճարի ետվերադարձ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('history_types')->where('name', 'prepayment_refund')->delete();
    }
};
