<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientExtraSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $clients = Client::all();

        foreach ($clients as $client) {
            $client->update([
                'country' => 'Armenia',
                'city' => ['Երևան', 'Գյումրի'][array_rand(['Երևան', 'Գյումրի'])],
                'street' => 'Random Street', // You can generate random street names if needed
                'validity' => $this->randomValidityDate(),
            ]);
        }
    }

    private function randomValidityDate()
    {
        $startDate = strtotime('2024-12-24');
        $endDate = strtotime('2030-02-24');
        $randomDate = rand($startDate, $endDate);
        return date('d.m.Y', $randomDate);
    }
}
