<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoryAndRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Seed the categories table
        DB::table('categories')->insert([
            [
                'name' => 'gold',
                'title' => 'Ոսկի'
            ],
            [
                'name' => 'electronics',
                'title' => 'Տեխնիկա'
            ],
            [
                'name' => 'car',
                'title' => 'Ավտոմեքենա'
            ],
        ]);

        // Retrieve the category IDs
        $goldId = DB::table('categories')->where('name', 'gold')->value('id');
        $electronicsId = DB::table('categories')->where('name', 'electronics')->value('id');
        $carId = DB::table('categories')->where('name', 'car')->value('id');

        // Seed the category_rates table
        DB::table('category_rates')->insert([
            // Gold rates
            [
                'category_id' => $goldId,
                'interest_rate' => 0.23,
                'penalty' => 0.13,
                'min_amount' => 5000,
                'max_amount' => 20000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.20,
                'penalty' => 0.13,
                'min_amount' => 21000,
                'max_amount' => 50000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.18,
                'penalty' => 0.13,
                'min_amount' => 51000,
                'max_amount' => 100000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.17,
                'penalty' => 0.13,
                'min_amount' => 101000,
                'max_amount' => 200000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.16,
                'penalty' => 0.13,
                'min_amount' => 201000,
                'max_amount' => 500000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.15,
                'penalty' => 0.13,
                'min_amount' => 501000,
                'max_amount' => 750000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.14,
                'penalty' => 0.13,
                'min_amount' => 751000,
                'max_amount' => 1500000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.13,
                'penalty' => 0.13,
                'min_amount' => 1501000,
                'max_amount' => 2500000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.12,
                'penalty' => 0.13,
                'min_amount' => 2501000,
                'max_amount' => 3500000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.11,
                'penalty' => 0.13,
                'min_amount' => 3501000,
                'max_amount' => 5000000,
            ],
            [
                'category_id' => $goldId,
                'interest_rate' => 0.10,
                'penalty' => 0.13,
                'min_amount' => 5001000,
                'max_amount' => null,
            ],

            // Electronics rate
            [
                'category_id' => $electronicsId,
                'interest_rate' => 0.40,
                'penalty' => 0.13,
                'min_amount' => 0,
                'max_amount' => null,
            ],

            // Car rates
            [
                'category_id' => $carId,
                'interest_rate' => 0.30,
                'penalty' => 0.13,
                'min_amount' => 251000,
                'max_amount' => 300000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.28,
                'penalty' => 0.13,
                'min_amount' => 301000,
                'max_amount' => 350000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.26,
                'penalty' => 0.13,
                'min_amount' => 351000,
                'max_amount' => 400000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.24,
                'penalty' => 0.13,
                'min_amount' => 401000,
                'max_amount' => 450000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.22,
                'penalty' => 0.13,
                'min_amount' => 451000,
                'max_amount' => 500000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.21,
                'penalty' => 0.13,
                'min_amount' => 501000,
                'max_amount' => 600000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.20,
                'penalty' => 0.13,
                'min_amount' => 601000,
                'max_amount' => 700000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.19,
                'penalty' => 0.13,
                'min_amount' => 701000,
                'max_amount' => 800000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.18,
                'penalty' => 0.13,
                'min_amount' => 801000,
                'max_amount' => 900000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.17,
                'penalty' => 0.13,
                'min_amount' => 901000,
                'max_amount' => 1000000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.16,
                'penalty' => 0.13,
                'min_amount' => 1001000,
                'max_amount' => 1500000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.15,
                'penalty' => 0.13,
                'min_amount' => 1501000,
                'max_amount' => 2500000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.14,
                'penalty' => 0.13,
                'min_amount' => 2501000,
                'max_amount' => 3500000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.13,
                'penalty' => 0.13,
                'min_amount' => 3501000,
                'max_amount' => 5000000,
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.12,
                'penalty' => 0.13,
                'min_amount' => 5001000,
                'max_amount' => 10000000,
            ],
        ]);
    }
}
