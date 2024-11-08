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
        $goldId = DB::table('categories')->where('name', 'gold')->value('id');
        $electronicsId = DB::table('categories')->where('name', 'electronics')->value('id');
        $carId = DB::table('categories')->where('name', 'car')->value('id');

        DB::table('lump_rates')->insert([
            [
                'category_id' => $goldId,
                'min_amount' => 1000,
                'max_amount' => 400000,
                'lump_rate' => 2.5,
            ],
            [
                'category_id' => $goldId,
                'min_amount' => 401000,
                'max_amount' => 1000000,
                'lump_rate' => 2.0,
            ],
            [
                'category' => $goldId,
                'min_amount' => 1001000,
                'max_amount' => 5000000,
                'lump_rate' => 1.5,
            ],
            [
                'category' => $goldId,
                'min_amount' => 5001000,
                'max_amount' => 10000000,
                'lump_rate' => 1.0,
            ],
            // Electronics lump rates (same structure)
            [
                'category_id' => $electronicsId,
                'min_amount' => 1000,
                'max_amount' => 400000,
                'lump_rate' => 2.5,
            ],
            [
                'category_id' => $electronicsId,
                'min_amount' => 401000,
                'max_amount' => 1000000,
                'lump_rate' => 2.0,
            ],
            [
                'category_id' => $electronicsId,
                'min_amount' => 1001000,
                'max_amount' => 5000000,
                'lump_rate' => 1.5,
            ],
            [
                'category_id' => $electronicsId,
                'min_amount' => 5001000,
                'max_amount' => 10000000,
                'lump_rate' => 1.0,
            ],

            // Car lump rates (same structure)
            [
                'category_id' => $carId,
                'min_amount' => 1000,
                'max_amount' => 400000,
                'lump_rate' => 2.5,
            ],
            [
                'category_id' => $carId,
                'min_amount' => 401000,
                'max_amount' => 1000000,
                'lump_rate' => 2.0,
            ],
            [
                'category_id' => $carId,
                'min_amount' => 1001000,
                'max_amount' => 5000000,
                'lump_rate' => 1.5,
            ],
            [
                'category_id' => $carId,
                'min_amount' => 5001000,
                'max_amount' => 10000000,
                'lump_rate' => 1.0,
            ],
        ]);
    }
}
