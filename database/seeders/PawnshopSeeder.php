<?php

namespace Database\Seeders;

use App\Models\Pawnshop;
use App\Models\PawnshopConfig;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PawnshopSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $pawnshop = new Pawnshop();
        $pawnshop->city = 'Երևան';
        $pawnshop->address = 'Արշակունյաց պ. 53/35';
        $pawnshop->license = 'ԳԳ-Ե 302';
        $pawnshop->representative = 'Վ. Սահակյան';
        $pawnshop->telephone = '(010) 49-07-77';
        $pawnshop->phone1 = '096 99 91 80';
        $pawnshop->phone2 = '094 97 19 51';
        $pawnshop->email = 'diamondcredit1@mail.ru';
        $pawnshop->bank = 'Ամերիաբանկ';
        $pawnshop->card_account_number = '1570073548790300';
        $pawnshop->worth = '0';
        $pawnshop->given = '0';
        $pawnshop->cashbox = '5000000';
        $pawnshop->bank_cashbox = '2000000';
        $pawnshop->order_in = 1;
        $pawnshop->order_out = 1;
        $pawnshop->bank_order_in = 1;
        $pawnshop->bank_order_out = 1;
        $pawnshop->save();

        $pawnshop = new Pawnshop();
        $pawnshop->city = 'Գյումրի';
        $pawnshop->address = 'Գորկու 68/3';
        $pawnshop->license = 'ԳԳ-Ե 246';
        $pawnshop->representative = 'Վ. Սահակյան';
        $pawnshop->telephone = '(0312) 4-33-43';
        $pawnshop->phone1 = '096 99 91 81';
        $pawnshop->phone2 = '098 95 19 91';
        $pawnshop->email = 'diamondcredit@mail.ru';
        $pawnshop->bank = 'Ամերիաբանկ';
        $pawnshop->card_account_number = '1570073548790400';
        $pawnshop->worth = '0';
        $pawnshop->given = '0';
        $pawnshop->cashbox = '5000000';
        $pawnshop->bank_cashbox = '2000000';
        $pawnshop->order_in = 1;
        $pawnshop->order_out = 1;
        $pawnshop->bank_order_in = 1;
        $pawnshop->bank_order_out = 1;
        $pawnshop->save();


    }
}
