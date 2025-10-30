<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\ClientClassification;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            CategoryAndRateSeeder::class
        ]);
        $this->call([
            LumpRateSeeder::class
        ]);
        $this->call([
            TypeSeeder::class
        ]);
        $this->call([
            UserSeeder::class
        ]);
        $this->call([
            PawnshopSeeder::class
        ]);
        $this->call([
            PawnshopConfigSeeder::class
        ]);
        $this->call(CurrencySeeder::class);
        $this->call(ClientClassificationSeeder::class);
        $this->call(ChartOfAccountsSeeder::class);

    }
}
