<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExecuteItemRequest;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Services\ActivityService;
use App\Services\PaymentService;
use App\Traits\ContractTrait;
use App\Traits\FileTrait;
use App\Traits\HistoryTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentControllerNew extends Controller
{
    use ContractTrait, HistoryTrait;
    use FileTrait;

    protected PaymentService $paymentService;
    protected ActivityService $activityService;


    public function __construct(PaymentService $paymentService, ActivityService $activityService)
    {
        $this->paymentService = $paymentService;
        $this->activityService = $activityService;
    }
    public function makePayment(Request $request): JsonResponse
    {

        $contract =  Contract::findOrFail($request->contract_id);
        $amount = $request->amount;
        $payer = $request->payer;
        $cash = $request->cash;

        $paymentIds = $request->payments;

        $payments = Payment::whereIn('id', $paymentIds)->get();
        $order_id = $this->generateOrderInNew($request,$payments,Order::REGULAR_FILTER)->id;
        $history = $this->createHistory($request, $order_id);
        $deal = $this->createDeal($amount,null,null,null,null,
            'in', $contract->id,$contract->client->id,
            $order_id, $cash,null,Contract::REGULAR_PAYMENT,'payment',$history->id,null,null,1);

        $result = $this->paymentService->processPayments(
            $contract,$amount,$payer,$cash,$payments,$deal->id
        );
        $history->interest_amount = $result['interest_amount'];
        $history->penalty = $result['penalty'];
        $history->discount  = $result['discount'];
        $history->delay_days = $result['delay_days'];
        $history->save();
        $deal->interest_amount = $result['interest_amount'];
        $deal->penalty = $result['penalty'];
        $deal->discount = $result['discount'];
        $deal->delay_days = $result['delay_days'];
        $deal->save();

        $ruleInterestPayment = PostingRule::where('business_event_filter', 'pay_interest_amount')
            ->first();

        if (!$ruleInterestPayment) {
            throw new \RuntimeException('Posting rule for pay_interest_amount not found');
        }

        $debitInterestPayment = $ruleInterestPayment->debit_account_id;
        $creditInterestPayment=  $ruleInterestPayment->credit_account_id;


        $debetPartnerId = Client::where('company_name','Diamond Credit')->first()->id ?? 1;
        $creditPartnerId = $contract->client_id;

        $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;

        $document_type = DocumentJournal::PAY_INTEREST_AMOUNT;
        $date = Carbon::now()->format('Y-m-d');
        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();
        $interestAmount = $result['interest_amount'];
        $principalAmount = $result['principal_amount'];
        if ($interestAmount > 0) {
            $journalDoc = DocumentJournal::create([
                'date'               => $date,
                'document_number'    => $nextDocNum,
                'document_type'      => $document_type,
                'amount_amd'         => $result['interest_amount'],
                'partner_id'         => $debetPartnerId,
                'credit_partner_id'  => $creditPartnerId,
                'comment'            => 'interest_amount_payment',
                'debit_account_id'   => $debitInterestPayment,
                'credit_account_id'  => $creditInterestPayment,
                'user_id'            => auth()->id(),
                'journalable_type'   => DocumentJournal::class,
                'journalable_id'     => $journal->id,
            ]);


            Transaction::create([
                'date'               => $date,
                'document_number'    => $nextDocNum,
                'document_type'      => $document_type,

                'debit_account_id'   => $debitInterestPayment,
                'debit_partner_id'   => $debetPartnerId,
                'debit_currency_id'  => 1,

                'credit_account_id'  => $creditInterestPayment,
                'credit_currency_id' => 1,
                'credit_partner_id'  => $creditPartnerId,

                'amount_amd'         => $result['interest_amount'],

                'comment'            => 'interest_amount_payment',
                'user_id'            => auth()->id(),
                'is_system'          => false,

                'disbursement_date'    =>  $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $journalDoc->id,
            ]);
            $nextDocNum++;
        }
        if ($principalAmount > 0) {
            $ruleMotherAmount = PostingRule::where('business_event_filter', 'pay_mother_amount')
                ->first();

            if (!$ruleMotherAmount) {
                throw new \RuntimeException('Posting rule for pay_mother_amount not found');
            }

            $debitMother = $ruleMotherAmount->debit_account_id;
            $creditMother = $ruleMotherAmount->credit_account_id;
            $documentTypePrincipal = DocumentJournal::PAY_MOTHER_AMOUNT;

            $journalDocPrincipal = DocumentJournal::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => $documentTypePrincipal,
                'amount_amd' => $principalAmount,
                'partner_id' => $debetPartnerId,
                'credit_partner_id' => $creditPartnerId,
                'comment' => 'mother_amount_payment',
                'debit_account_id' => $debitMother,
                'credit_account_id' => $creditMother,
                'user_id' => auth()->id(),
                'journalable_type' => DocumentJournal::class,
                'journalable_id' => $journal->id,
            ]);
            Transaction::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => $documentTypePrincipal,

                'debit_account_id' => $debitMother,
                'debit_partner_id' => $debetPartnerId,
                'debit_currency_id' => 1,

                'credit_account_id' => $creditMother,
                'credit_currency_id' => 1,
                'credit_partner_id' => $creditPartnerId,

                'amount_amd' => $principalAmount,

                'comment' => 'mother_amount_payment',
                'user_id' => auth()->id(),
                'is_system' => false,

                'disbursement_date' => $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id' => $journalDocPrincipal->id
            ]);
        }


        $this->activityService->log(
            'make_payment',
            "Payment made: {$amount} AMD for contract #{$contract->id}, and deal #{$deal->id}",
            Contract::class,
            $contract->id
        );


       return response()->json([
           'success' => 'success',
           'message' => 'Successfully created payment!'
       ]);
    }

    private function updateContractStatus($contract)
    {
        $paymentsLeft = $contract->payments->where('status', 'initial');

        if ($paymentsLeft->isEmpty()) {
            $contract->status = 'completed';
            $contract->closed_at = Carbon::now();
            $contract->left = 0;
        }
        $contract->save();
    }
    public function makeFullPayment(Request $request): JsonResponse
    {
//        $has_penalty_amount = $this->countPenalty($request->contract_id);
//        if ($has_penalty_amount['penalty_amount'] > 0) {
//            return response()->json([
//                'message' => 'You have an unpaid penalty amount! ',
//            ], 404);
//        }
        $contract = Contract::findOrFail($request->contract_id);
        $amount = $request->amount;
        $payer = $request->payer;
        $cash = $request->cash;
        if (!$contract) {
            return response()->json([
                'success' => 'error',
                'message' => 'Contract not found',
            ], 404);
        }
        $type = HistoryType::where('name', 'full_payment')->first();
        $purpose = 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
        if ($request->hasPenalty) {
            $purpose .= ', տուգանք';
        }
        $newOrder = $this->generateOrder($contract, $amount, $purpose, 'in', $cash,Order::FULL_FILTER);

        $history = History::create([
            'amount' => $amount,
            'type_id' => $type->id,
            'user_id' => auth()->user()->id,
            'order_id' => $newOrder->id,
            'contract_id' => $contract->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d'),
        ]);

        $deal = $this->createDeal($amount, null,null,null,null,'in', $contract->id,$contract->client->id, $newOrder->id, $cash,null,Contract::FULL_PAYMENT,'full_payment',$history->id,null);

        $result = $this->paymentService->processFullPayment($contract, $amount, $payer, $cash,$deal->id);
        $deal->payment_id = $result['payment_id'];
        $deal->interest_amount = $result['interest_amount'];
        $deal->penalty = $result['penalty'];
        $deal->delay_days = $result['delay_days'];
        $deal->save();

        $history->interest_amount = $result['interest_amount'];
        $history->penalty = $result['penalty'];
        $history->delay_days = $result['delay_days'];
        $history->save();

        $contract->closed_at = now();
        $contract->save();
//        $acc1100 = ChartOfAccount::idByCode('1100');
//        $acc2220 = ChartOfAccount::idByCode('2220');
//       $deal->transactions()->create([
//            'date'              => $deal->date,
//            'document_type'     => Transaction::FULL_PAYMENT,
//            'document_number'   => Transaction::getNextDocumentNumber(),
//            'debit_account_id'  => $acc1100,
//            'credit_account_id' => $acc2220,
//            'currency_id'       => 1, //testing
//            'amount_amd'        => $amount,
//            'comment'           => 'full_payment',
//            'debit_partner_id' => 2,//testing
//            'credit_partner_id' => $contract->client_id,
//        ]);
        // Check if early payment is eligible for a refund
        if (Carbon::now()->lessThan(Carbon::parse($contract->deadline))) {
            $refundAmount = $this->calculateRefundAmount($contract->mother,$contract->lump_rate,$contract->deadline,$contract->deadline_days);
            if ($refundAmount > 0) {
                $refundOrder = $this->generateOrder($contract, $refundAmount,Order::REFUND_LUMP, 'out', $cash,Order::REFUND_LUMP_FILTER);
                $refund_type = HistoryType::where('name', 'one_time_payment_refund')->first();

                $history = History::create([
                    'amount' => $refundAmount,
                    'type_id' => $refund_type->id,
                    'user_id' => auth()->user()->id,
                    'order_id' => $refundOrder->id,
                    'contract_id' => $contract->id,
                    'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
                ]);
                $deal = $this->createDeal($refundAmount, null, null, null, null, 'out', $contract->id, $contract->client->id, $refundOrder->id, $cash, null, Order::REFUND_LUMP, Order::REFUND_LUMP_FILTER);
                DealAction::create([
                    'deal_id' => $deal->id,
                    'actionable_id' => $result['payment_id'],
                    'actionable_type' => Payment::class,
                    'amount' => $refundAmount,
                    'type' => 'refund',
                    'description' => 'Refund payment',
                    'date' => \Illuminate\Support\Carbon::now()->format('Y-m-d'),
                ]);
                $this->activityService->log(
                    'refund_lump',
                    "Refund lump sum: {$refundAmount} AMD for contract #{$contract->id} and deal #{$deal->id}",
                    Contract::class,
                    $contract->id
                );

                return response()->json([
                    'success' => 'success',
                    'message' => 'Full payment created successfully with a lump sum refund',
                    'refund_amount' => $refundAmount
                ]);
            }
        }
        $this->activityService->log(
            'full_payment',
            "Full payment of {$amount} AMD for contract #{$contract->id} and deal #{$deal->id}",
            Contract::class,
            $contract->id
        );

        return response()->json([
            'success' => 'success',
            'message' => 'Full payment created successfully',
        ]);
    }

    /**
     * Calculate the refund amount for early full payment
     */
    private function calculateRefundAmount($providedAmont,$lumpRate,$deadline,$deadlineDays)
    {
        $lump_amount_original = $providedAmont * $lumpRate/100;
        $lump_amount = round($lump_amount_original);

        $remainingDays = Carbon::parse($deadline)->diffInDays(Carbon::now()->startOfDay());

        $refund_amount_original = $lump_amount/$deadlineDays*$remainingDays;
        $refund_amount = round($refund_amount_original);

        return round($refund_amount/10)*10;
    }
    public function payPartial(Request $request): JsonResponse
    {
        $has_penalty_amount = $this->countPenalty($request->contract_id);
        if ($has_penalty_amount['penalty_amount'] > 0) {
            return response()->json([
                'message' => 'You have an unpaid penalty amount! ',
            ], 404);
        }
        $contract_id = $request->contract_id;
        $contract = Contract::findOrFail($contract_id);
        $partialAmount = $request->amount;
        $payer = $request->payer;
        $cash = $request->cash;

        // Call the payment service to handle the partial payment
        $history_type = HistoryType::where('name','partial_payment')->first();
        $client_name = $contract->client->name.' '.$contract->client->surname.' '.$contract->client->middle_name;
        $order_id = $this->getOrder($cash,'in');
        $res = [
            'contract_id' => $contract->id,
            'type' => 'in',
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $partialAmount,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('Y-m-d'),
            'client_name' => $client_name,
            'purpose' => 'Մասնակի մարում',
            'cash' => $cash,
            'filter' => Order::PARTIAL_FILTER
        ];
        $new_order = Order::create($res);
        $history = History::create([
            'amount' => $partialAmount,
            'user_id' => auth()->user()->id,
            'type_id' => $history_type->id,
            'order_id' => $new_order->id,
            'contract_id' => $contract->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
        ]);
        $deal = $this->createDeal($partialAmount, null,null, null,null,'in', $contract->id,$contract->client->id, $new_order->id, $cash,null, Contract::PARTIAL_PAYMENT,'partial_payment',$history->id);

        $payment_id = $this->paymentService->payPartial($contract, $partialAmount, $payer, $cash,$deal->id);

        $deal->payment_id = $payment_id;
        $deal->save();
//        $acc10210 = ChartOfAccount::idByCode('10210');
//        $acc16200NV = ChartOfAccount::idByCode('16200NV');
//        $deal->transactions()->create([
//            'date'              => $deal->date,
//            'document_type'     => Transaction::PARTIAL_PAYMENT,
//            'document_number'   => Transaction::getNextDocumentNumber(),
//            'debit_account_id'  => $acc10210,
//            'credit_account_id' => $acc16200NV,
//            'currency_id'       => 1, //testing
//            'amount_amd'        => $partialAmount,
//            'comment'           => 'partial_payment',
//            'debit_partner_id' => 2,//testing
//            'credit_partner_id' => $contract->client_id,
//        ]);
        $this->updateContractStatus($contract);
        $this->activityService->log(
            'partial_payment',
            "Partial payment: {$partialAmount} AMD for contract #{$contract->id} and deal #{$deal->id}",
            Contract::class,
            $contract->id
        );

        return response()->json([
            'success' => 'success',
            'message' => 'Partial payment processed successfully!'
        ]);
    }
    public function executeItem(ExecuteItemRequest $request)
    {
        DB::beginTransaction();
        try {
            $contractId = $request->contract_id;
            $executedAmount = $request->amount;
            $buyerInfo = $request->buyer_info;
            $num = $request->rep_id;
            $cash = false;


            $contract = Contract::findOrFail($contractId);
            $client_name = $contract->client->name.' '.$contract->client->surname.' '.$contract->client->middle_name;
            if ($contract->status === Contract::STATUS_EXECUTED) {
                throw new \Exception("This contract has already been executed.");
            }
            $contract->status = 'executed';
            $contract->executed = $request->amount;
            $contract->left = 0;
            $contract->closed_at = Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d');
            $contract->save();

            Payment::where('contract_id',$contractId)
                ->where('paid','<=',0)
                ->where('status','initial')
                ->delete();

            Payment::where('contract_id',$contractId)
                ->where('paid','>',0)
                ->where('status','initial')
                ->update([
                    'status' => 'completed'
                ]);
            $order_id = $this->getOrder($cash,'in');
            $res = [
                'contract_id' => $contract->id,
                'type' => 'in',
                'title' => 'Օրդեր',
                'pawnshop_id' => auth()->user()->pawnshop_id,
                'order' => $order_id,
                'amount' => $executedAmount,
                //'rep_id' => '2211',
                'rep_id' => $num,
                'date' => Carbon::now()->format('Y-m-d'),
                'receiver' => $buyerInfo,
                'purpose' => Order::EXECUTION_PURPOSE,
                'client_name' => $client_name,
                'num' => $contract->num,
                'cash' => $cash,
                'filter' => Order::EXPENSE_FILTER
            ];
            $order = Order::create($res);
            $type = HistoryType::where('name', 'execution')->first();

            $history = History::create([
                'amount' => $executedAmount,
                'user_id' => auth()->user()->id,
                'type_id' => $type->id,
                'order_id' => $order->id,
                'contract_id' => $contract->id,
                'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
            ]);
            $deal = $this->createDeal($executedAmount, null,null, null,null,'in', $contract->id,null, $order->id, $cash,$buyerInfo, Order::EXECUTION_PURPOSE,'execution',$history->id,null);
            ContractAmountHistory::create([
                'contract_id' => $contract->id,
                'amount' => $contract->estimated_amount,
                'amount_type' => 'estimated_amount',
                'type' => 'out',
                'date' => now()->format('Y-m-d'),
                'deal_id' => $deal->id ?? null,
                'category_id' => $contract->category_id ?? null,
                'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
            ]);
            ContractAmountHistory::create([
                'contract_id' => $contract->id,
                'amount' => $contract->provided_amount,
                'amount_type' => 'provided_amount',
                'type' => 'out',
                'date' => now()->format('Y-m-d'),
                'deal_id' => $deal->id ?? null,
                'category_id' => $contract->category_id ?? null,
                'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
            ]);
            $this->activityService->log(
                'execute_item',
                "Contract executed. Amount: {$executedAmount} AMD and deal #{$deal->id}",
                Contract::class,
                $contractId
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Execution processed successfully!'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Execution failed: ' . $e->getMessage()
            ], 500);
        }
    }

}
