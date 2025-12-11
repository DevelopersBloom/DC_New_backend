<?php

namespace Database\Seeders;

use App\Models\BusinessEvent;
use App\Models\ChartOfAccount;
use App\Models\PostingRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PostingRuleSeeder extends Seeder
{
    public function run()
    {
        $acc102101         = ChartOfAccount::idByCode('102101');
        $acc33512NV        = ChartOfAccount::idByCode('33512NV');
        $acc33512          = ChartOfAccount::idByCode('33512');
        $acc70315          = ChartOfAccount::idByCode('70315');
        $acc33513NI        = ChartOfAccount::idByCode('33513NI');
        $acc391021         = ChartOfAccount::idByCode('391021');

        $acc16200NV = ChartOfAccount::idByCode('16200NV') ;
        $acc10210 = ChartOfAccount::idByCode('10210');
        $acc16200 = ChartOfAccount::idByCode('16200');
        $acc60120 = ChartOfAccount::idByCode('60120');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');
        $acc73015 = CHARTOfAccount::idByCode('73015');
        $acc16605PC = ChartOfAccount::idByCode('16605PC');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');
        DB::table('posting_rules')->insert([
            [
                'business_event_filter' => 'attach_loan',
                'debit_account_id'  => $acc102101,
                'credit_account_id' => $acc33512NV,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'business_event_filter' => 'effective_interest_calculation',
                'debit_account_id'  => $acc70315,
                'credit_account_id' => $acc33512,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'business_event_filter' => 'interest_calculation',
                'debit_account_id'  => $acc33512,
                'credit_account_id' => $acc33513NI,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'business_event_filter' => 'interest_payment',
                'debit_account_id'  => $acc33513NI,
                'credit_account_id' => $acc102101,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'business_event_filter' => 'loan_payment',
                'debit_account_id'  => $acc33512NV,
                'credit_account_id' => $acc102101,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'business_event_filter' => 'tax_collection',
                'debit_account_id'  => $acc33513NI,
                'credit_account_id' => $acc391021,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'provide_contract_amount',
                'debit_account_id'  => $acc16200NV,
                'credit_account_id' => $acc10210,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'effective_rate_amount',
                'debit_account_id'  => $acc16200,
                'credit_account_id' => $acc60120,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'interest_rate_amount',
                'debit_account_id'  => $acc16201NI,
                'credit_account_id' => $acc16200,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'reserve_general_amount',
                'debit_account_id'  => $acc73015,
                'credit_account_id' => $acc16605PC,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'reserve_special_amount',
                'debit_account_id'  => $acc73015,
                'credit_account_id' => $acc16605PS,
                'created_at' => now(),
                'updated_at' => now(),
            ],


        ]);

    }
    }
