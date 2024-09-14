<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InterestRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('interest_rates')->insert([
            // Gold
            ['category_id' => 1, 'min_amount' => 5000, 'max_amount' => 20000, 'percentage' => 0.23],
            ['category_id' => 1, 'min_amount' => 21000, 'max_amount' => 50000, 'percentage' => 0.20],
            ['category_id' => 1, 'min_amount' => 51000, 'max_amount' => 100000, 'percentage' => 0.18],
            ['category_id' => 1, 'min_amount' => 101000, 'max_amount' => 200000, 'percentage' => 0.17],
            ['category_id' => 1, 'min_amount' => 201000, 'max_amount' => 500000, 'percentage' => 0.16],
            ['category_id' => 1, 'min_amount' => 501000, 'max_amount' => 750000, 'percentage' => 0.15],
            ['category_id' => 1, 'min_amount' => 751000, 'max_amount' => 1500000, 'percentage' => 0.14],
            ['category_id' => 1, 'min_amount' => 1501000, 'max_amount' => 2500000, 'percentage' => 0.13],
            ['category_id' => 1, 'min_amount' => 2501000, 'max_amount' => 3500000, 'percentage' => 0.12],
            ['category_id' => 1, 'min_amount' => 3501000, 'max_amount' => 5000000, 'percentage' => 0.11],
            ['category_id' => 1, 'min_amount' => 5001000, 'max_amount' => 100000000, 'percentage' => 0.10],
            // Car
            ['category_id' => 7, 'min_amount' => 0, 'max_amount' => 350000, 'percentage' => 0.30],
            ['category_id' => 7, 'min_amount' => 350001, 'max_amount' => 400000, 'percentage' => 0.28],
            ['category_id' => 7, 'min_amount' => 400001, 'max_amount' => 450000, 'percentage' => 0.26],
            ['category_id' => 7, 'min_amount' => 450001, 'max_amount' => 500000, 'percentage' => 0.24],
            ['category_id' => 7, 'min_amount' => 500001, 'max_amount' => 600000, 'percentage' => 0.22],
            ['category_id' => 7, 'min_amount' => 600001, 'max_amount' => 700000, 'percentage' => 0.20],
            ['category_id' => 7, 'min_amount' => 700001, 'max_amount' => 800000, 'percentage' => 0.19],
            ['category_id' => 7, 'min_amount' => 800001, 'max_amount' => 900000, 'percentage' => 0.18],
            ['category_id' => 7, 'min_amount' => 900001, 'max_amount' => 1000000, 'percentage' => 0.17],
            ['category_id' => 7, 'min_amount' => 1001000, 'max_amount' => 1500000, 'percentage' => 0.16],
            ['category_id' => 7, 'min_amount' => 1501000, 'max_amount' => 2500000, 'percentage' => 0.15],
            ['category_id' => 7, 'min_amount' => 2501000, 'max_amount' => 3500000, 'percentage' => 0.14],
            ['category_id' => 7, 'min_amount' => 3501000, 'max_amount' => 5000000, 'percentage' => 0.13],
            ['category_id' => 7, 'min_amount' => 5001000, 'max_amount' => 100000000, 'percentage' => 0.12],
            // Electronics
            ['category_id' => 9, 'min_amount' => 0, 'max_amount' => 100000000, 'percentage' => 0.40],

            // Add more ranges as needed
        ]);
    }
}
