<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $currencies = [
            ['code' => 'AMD', 'name' => 'Հայկական դրամ', 'symbol' => '֏'],
            ['code' => 'USD', 'name' => 'ԱՄՆ դոլար', 'symbol' => '$'],
            ['code' => 'EUR', 'name' => 'Եվրո', 'symbol' => '€'],
            ['code' => 'RUB', 'name' => 'Ռուսական ռուբլի', 'symbol' => '₽'],
        ];

        foreach ($currencies as $currency) {
            Currency::firstOrCreate(
                ['code' => $currency['code']],
                ['name' => $currency['name'], 'symbol' => $currency['symbol']]
            );
        }
    }
}
