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

        ]);

    }
    }
