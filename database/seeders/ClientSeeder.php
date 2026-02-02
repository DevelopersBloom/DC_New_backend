<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Client::create([
            'type' => 'legal',
            'company_name' => 'Diamond Credit',
            'legal_form' => 'ՍՊԸ',
            'tax_number' => '845128965298652',
            'state_register_number' => '865985698659856',
            'activity_field' => 'Գործունեության բնագավառ',
            'director_name' => 'Տնօրենի անուն',
            'accountant_info' => 'Հաշվապահի տեղեկություն',
            'internal_code' => '874512',

            'email' => 'diamond@mail.com',
            'phone' => '+374 85 201 10 20',

            'country' => 'Armenia',
            'city' => 'Երկիր',
            'street' => 'Փողոց',
            'building' => 'Շենք / Բնակարան',

            'website' => 'Կայք',

            'bank_name' => 'Ամերիա Բանկ',
            'account_number' => '121232654454545',
            'iban' => '784785875',
            'swift_code' => '1234566',

            'date' => now(),
        ]);
    }
}
