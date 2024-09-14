<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OneTimePercentageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('one_time_percentages')->insert([
            // Gold
            ['category_id' => 1, 'min_amount' => 1000, 'max_amount' => 400000, 'percentage' => 2.5],
            ['category_id' => 1, 'min_amount' => 401000, 'max_amount' => 1000000, 'percentage' => 2.0],
            ['category_id' => 1, 'min_amount' => 1001000, 'max_amount' => 5000000, 'percentage' => 1.5],
            ['category_id' => 1, 'min_amount' => 5001000, 'max_amount' => 10000000, 'percentage' => 1.0],
            // Car
            ['category_id' => 7, 'min_amount' => 1000, 'max_amount' => 400000, 'percentage' => 2.5],
            ['category_id' => 7, 'min_amount' => 401000, 'max_amount' => 1000000, 'percentage' => 2.0],
            ['category_id' => 7, 'min_amount' => 1001000, 'max_amount' => 5000000, 'percentage' => 1.5],
            ['category_id' => 7, 'min_amount' => 5001000, 'max_amount' => 10000000, 'percentage' => 1.0],
            // Electronics
            ['category_id' => 9, 'min_amount' => 1000, 'max_amount' => 400000, 'percentage' => 2.5],
            ['category_id' => 9, 'min_amount' => 401000, 'max_amount' => 1000000, 'percentage' => 2.0],
            ['category_id' => 9, 'min_amount' => 1001000, 'max_amount' => 5000000, 'percentage' => 1.5],
            ['category_id' => 9, 'min_amount' => 5001000, 'max_amount' => 10000000, 'percentage' => 1.0],

            // Add more ranges as needed
        ]);
    }
}
