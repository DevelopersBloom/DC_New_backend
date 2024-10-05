<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
        $this->call([
            ContractSeeder::class
        ]);
    }
}
