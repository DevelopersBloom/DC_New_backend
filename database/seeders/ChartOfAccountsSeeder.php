<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        $accounts = [
            [
                'code' => '15300PC',
                'name' => 'Վարկերի հնարավոր կորուստների պահուստ-ընդհանուր',
                'type' => 'passive',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode(15300),
                'description' => null,
            ],
            [
                'code' => '16201NI',
                'name' => 'Ֆիզիկական անձանց տրված վարկերի և փոխառությունների ...',
                'type' => 'active',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('16201'),
                'description' => null,
            ],
            [
                'code' => '16200NV',
                'name' => 'Ֆիզիկական անձանց տրված վարկեր և փոխառություններ',
                'type' => 'active',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('16200'),
                'description' => null,
            ],
            [
                'code' => '16605PS',
                'name' => 'test nf',
                'type' => 'active',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('16605'),
                'description' => null,
            ],
            [
                'code' => '16605PC',
                'name' => 'test nf',
                'type' => 'active',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('16605'),
                'description' => null,
            ],
            [
                'code' => '16200EF',
                'name' => 'test nf',
                'type' => 'active',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('16200'),
                'description' => null,
            ],
            [
                'code' => '16201NF',
                'name' => 'test nf',
                'type' => 'active',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('16201'),
                'description' => null,
            ],
            [
                'code' => '33512NV',
                'name' => 'test',
                'type' => 'passive',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('33512'),
                'description' => null,
            ],
            [
                'code' => '33513NI',
                'name' => 'test',
                'type' => 'passive',
                'is_accumulative' => false,
                'currency_id' => null,
                'parent_id' => ChartOfAccount::idByCode('33513'),
                'description' => null,
            ],
        ];

        DB::table('chart_of_accounts')->insert($accounts);
    }
}
