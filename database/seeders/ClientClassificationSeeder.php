<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientClassificationSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('customer_classifications')->insert([
            [
                'title' => 'Ստանդարտ',
                'name' => 'standard',
                'order' => 1,
                'reserve_percent' => 1.0,
                'risk_weight' => 75.0,
            ],
            [
                'title' => 'Հսկվող',
                'name' => 'monitored',
                'order' => 2,
                'reserve_percent' => 10.0,
                'risk_weight' => 75.0,
            ],
            [
                'title' => 'Ոչ ստանդարտ',
                'name' => 'substandard',
                'order' => 3,
                'reserve_percent' => 20.0,
                'risk_weight' => 100.0,
            ],
            [
                'title' => 'Կասկածելի',
                'name' => 'suspicious',
                'order' => 4,
                'reserve_percent' => 50.0,
                'risk_weight' => 100.0,
            ],
            [
                'title' => 'Անհուսալի',
                'name' => 'loss',
                'order' => 5,
                'reserve_percent' => 100.0,
                'risk_weight' => 100.0,
            ],
        ]);
    }

}
