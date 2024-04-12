<?php

namespace Database\Seeders;

use App\Models\PawnshopConfig;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PawnshopConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $pawnshopConfig = new PawnshopConfig();
        $pawnshopConfig->pawnshop_id = 1;
        $pawnshopConfig->save();

        $pawnshopConfig = new PawnshopConfig();
        $pawnshopConfig->pawnshop_id = 2;
        $pawnshopConfig->save();
    }
}
