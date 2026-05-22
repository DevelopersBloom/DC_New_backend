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
        $acc66301 = ChartOfAccount::idByCode('66301');
        $acc73015 = CHARTOfAccount::idByCode('73015');
        $acc16605PC = ChartOfAccount::idByCode('16605PC');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');
        $acc63015 = ChartOfAccount::idByCode('63015');
        $acc10000 =  ChartOfAccount::idByCode('10000');
        $acc86000 = ChartOfAccount::idByCode('86000');
        $acc86001 = ChartOfAccount::idByCode('86001');
        $acc39920 = ChartOfAccount::idByCode('39920');

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
                'credit_account_id' => $acc102101,
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
                'business_event_filter' => 'penalty_rate_amount',
                'debit_account_id'  => $acc16201NI,
                'credit_account_id' => $acc66301,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_penalty_amount',
                'debit_account_id'  => $acc102101,
                'credit_account_id' => $acc16201NI,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_penalty_amount_cash',
                'debit_account_id'  => $acc10000,
                'credit_account_id' => $acc16201NI,
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
            [
                'business_event_filter' => 'provide_general_amount_change',
                'debit_account_id'  => $acc16605PC,
                'credit_account_id' => $acc63015,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'provide_special_amount_change',
                'debit_account_id'  => $acc16605PS,
                'credit_account_id' => $acc63015,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'classification_general_to_special',
                'debit_account_id'  => $acc16605PC,
                'credit_account_id' => $acc16605PS,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'classification_special_to_general',
                'debit_account_id'  => $acc16605PS,
                'credit_account_id' => $acc16605PC,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'loss_reserve_amount',
                'debit_account_id'  => $acc16605PS,
                'credit_account_id' => $acc16200NV,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'loss_reserve_effective',
                'debit_account_id'  => $acc16605PS,
                'credit_account_id' => $acc16200,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'loss_reserve',
                'debit_account_id'  => $acc16605PS,
                'credit_account_id' => $acc16201NI,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_mother_amount',
                'debit_account_id'  => $acc102101,
                'credit_account_id' => $acc16200NV,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_mother_amount_cash',
                'debit_account_id'  => $acc10000,
                'credit_account_id' => $acc16200NV,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_mother_amount_loss',
                'debit_account_id'  => $acc10000,
                'credit_account_id' => $acc63015,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_interest_amount',
                'debit_account_id'  => $acc102101,
                'credit_account_id' => $acc16201NI,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_interest_amount_cash',
                'debit_account_id'  => $acc10000,
                'credit_account_id' => $acc16201NI,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_interest_amount_loss',
                'debit_account_id'  => $acc10000,
                'credit_account_id' => $acc60120,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'close_contract_rule',
                'debit_account_id'  => $acc16200,
                'credit_account_id' => $acc60120,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'loss_writeoff_net_transfer',
                'debit_account_id'  => $acc73015,
                'credit_account_id' => $acc16605PS,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'loss_writeoff_principal',
                'debit_account_id'  => $acc86000,
                'credit_account_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'loss_writeoff_interest',
                'debit_account_id'  => $acc86001,
                'credit_account_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'pay_penalty_amount_loss',
                'debit_account_id'  => $acc10000,
                'credit_account_id' => $acc60120,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'recovery_principal',
                'debit_account_id'  => null,
                'credit_account_id' => $acc86000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'recovery_interest',
                'debit_account_id'  => null,
                'credit_account_id' => $acc86001,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'prepayment_received',
                'debit_account_id'  => $acc102101,
                'credit_account_id' => $acc39920,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_event_filter' => 'prepayment_received_cash',
                'debit_account_id'  => $acc10000,
                'credit_account_id' => $acc39920,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

    }
    }
