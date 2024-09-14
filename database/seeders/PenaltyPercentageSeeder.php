<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PenaltyPercentageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('penalty_percentages')->insert([
            ['category_id' => 1, 'min_amount' => 0, 'max_amount' => 100000000, 'percentage' => 0.13], //gold
            ['category_id' => 7, 'min_amount' => 0, 'max_amount' => 100000000, 'percentage' => 0.13], //car
            ['category_id' => 9, 'min_amount' => 0, 'max_amount' => 100000000, 'percentage' => 0.13], //electronics
            // Add more ranges as needed
        ]);
    }
}
