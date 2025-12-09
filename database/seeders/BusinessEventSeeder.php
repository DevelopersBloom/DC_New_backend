<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessEventSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('business_events')->insert([
            [
                'filter' => 'attach_loan',
                'name'   => 'Վարկի ներգրավում',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'filter' => 'effective_interest_calculation',
                'name'   => 'Արդյունավետ տոկոսի հաշվարկում',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'filter' => 'interest_calculation',
                'name'   => 'Տոկոսի հաշվարկում',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'filter' => 'interest_payment',
                'name'   => 'Տոկոսի մարում',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'filter' => 'loan_payment',
                'name'   => 'Վարկի մարում',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'filter' => 'tax_collection',
                'name'   => 'Հարկի գանձում տոկոսի մարումից',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
