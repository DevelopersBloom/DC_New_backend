<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LumpRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('lump_rates')->insert([
            [
                'min_amount' => 1000,
                'max_amount' => 400000,
                'lump_rate' => 2.5,
            ],
            [
                'min_amount' => 401000,
                'max_amount' => 1000000,
                'lump_rate' => 2.0,
            ],
            [
                'min_amount' => 1001000,
                'max_amount' => 5000000,
                'lump_rate' => 1.5,
            ],
            [
                'min_amount' => 5001000,
                'max_amount' => 10000000,
                'lump_rate' => 1.0,
            ],
        ]);
    }
}
