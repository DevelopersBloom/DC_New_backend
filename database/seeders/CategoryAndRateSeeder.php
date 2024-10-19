<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
                'name' => 'phone',
                'title' => 'Հեռախոս'
            ],
            [
                'name' => 'car',
                'title' => 'Ավտոմեքենա'
            ],
        ]);

        // Retrieve the category IDs
        $goldId = DB::table('categories')->where('name', 'gold')->value('id');
        $phoneId = DB::table('categories')->where('name', 'phone')->value('id');
        $carId = DB::table('categories')->where('name', 'car')->value('id');

        // Seed the category_rates table
        DB::table('category_rates')->insert([
            [
                'category_id' => $goldId,
                'interest_rate' => 0.4,
                'penalty' => 0.13,
                'min_amount' => 10000,
                'max_amount' => 100000,
                'lump_rate' => 2.5
            ],
            [
                'category_id' => $phoneId,
                'interest_rate' => 0.4,
                'penalty' => 0.13,
                'min_amount' => 10000,
                'max_amount' => 100000,
                'lump_rate' => 2.5
            ],
            [
                'category_id' => $carId,
                'interest_rate' => 0.4,
                'penalty' => 0.13,
                'min_amount' => 10000,
                'max_amount' => 100000,
                'lump_rate' => 2.5
            ],

        ]);
    }
}
