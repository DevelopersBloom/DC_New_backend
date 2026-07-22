<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExecuteItemRequest;
use App\Http\Requests\PaymentRequest;
use App\Jobs\RecalculateContractRangeJob;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\IdempotencyKey;
use App\Models\Modification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Services\ActivityService;
use App\Services\Payments\PaymentService;
use App\Services\PostingDatePolicy;
use App\Traits\CalculatesAccountBalancesTrait;
use App\Traits\ContractTrait;
use App\Traits\CorrectReserveTrait;
use App\Traits\FileTrait;
use App\Traits\HistoryTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentControllerNew extends Controller
{
    use CorrectReserveTrait;
    use ContractTrait, HistoryTrait;
    use FileTrait;
    use CalculatesAccountBalancesTrait;

    protected PaymentService $paymentService;
    protected ActivityService $activityService;
    protected PostingDatePolicy $postingDatePolicy;

    public function __construct(
        PaymentService $paymentService,
        ActivityService $activityService,
        PostingDatePolicy $postingDatePolicy
    ) {
        $this->paymentService     = $paymentService;
        $this->activityService    = $activityService;
        $this->postingDatePolicy  = $postingDatePolicy;
    }

    public function makePayment(PaymentRequest $request): JsonResponse
    {
        $contract = Contract::findOrFail($request->contract_id);
        $date = $request->contract_created_date ?? now()->toDateString();


        if ($error = $this->postingDatePolicy->validate($date)) {
            return $error;
        }
        $current = $this->calculateCurrentPayment($contract, $date);
        $interestAmount = max(0.0, (float) ($current['interest_amount'] ?? 0));
        $fullThreshold = $interestAmount + $current['penalty_amount'] + (float) $contract->provided_amount;

        if ((float) $request->amount >= $fullThreshold) {
            return $this->makeFullPayment($request);
        }

        $earlyMode = $request->input('payment_mechanism', 'prepayment');

        DB::beginTransaction();

        try {
            $amount = $request->amount;
            $payer  = $request->payer;
            $cash   = $request->cash;

            // ===== Payments =====
            $paymentIds = collect($request->input('payment_ids', $request->input('payments', [])))
                ->map(fn($v) => is_array($v) ? ($v['id'] ?? null) : $v)
                ->filter()
                ->map(fn($id) => (int)$id)
                ->unique()
                ->values();

            $payments = Payment::query()
                ->where('contract_id', $contract->id)
                ->where('status', 'initial')
                ->when($paymentIds->isNotEmpty(),
                    fn($q) => $q->whereIn('id', $paymentIds),
                    fn($q) => $q->where('type', 'regular')
                )
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            if ($payments->isEmpty()) {
                throw new \RuntimeException('No payable rows found');
            }

            $request->merge(['date' => $date]);

            // ===== Order / Deal =====
            $orderId = $this->generateOrderInNew($request, $payments, Order::REGULAR_FILTER, $date)->id;
            $history = $this->createHistory($request, $orderId);

            $deal = $this->createDeal(
                $amount, null, null, null, null,
                'in',
                $contract->id,
                $contract->client->id,
                $orderId,
                $cash,
                null,
                Contract::REGULAR_PAYMENT,
                'payment',
                $history->id,
                null,
                null,
                1,
                $date
            );

            $journal = DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $contract->id)
                ->firstOrFail();

            // ===== Payment Logic =====
            $old = $this->calcPaidAmount($contract);
            $paymentMechanism = $earlyMode;
            $result = $this->paymentService->processPayments(
                $contract,
                $amount,
                $payer,
                $cash,
                $payments,
                $deal->id,
                $journal->id,
                $paymentIds->isNotEmpty(),
                $interestAmount,
                $paymentIds->isNotEmpty(),
                $date,
                $paymentMechanism
            );

            // ===== Update History & Deal =====
            foreach (['interest_amount','penalty','discount','delay_days'] as $field) {
                $history->$field = $result[$field] ?? 0;
                $deal->$field    = $result[$field] ?? 0;
            }
            $partial_amount = $result['partial_amount'] ?? 0;
            $principal = $result['principal_amount'] ?? 0;
            $interest             = $result['interest_amount'];
            $prepaymentPrincipal  = $result['prepayment_principal'] ?? 0;

            $deal->principal_amount = $principal + $partial_amount;
            $deal->prepayment       = $prepaymentPrincipal;

            $history->save();
            $deal->save();

            // ===== Posting =====
            $class    = $contract->client->classification->name;
            $clientId = $contract->client_id;
            $docNum   = Transaction::getNextDocumentNumber();


            // ---- Interest ----
            if ($interest > 0) {
                $rule = $this->getPostingRule($this->resolveEvent('pay_interest_amount', $class, $cash));

                $journalInterest = $this->postEntry(
                    $date,
                    $docNum,
                    DocumentJournal::PAY_INTEREST_AMOUNT,
                    $interest,
                    'interest_amount_payment',
                    $rule->debit_account_id,
                    $rule->credit_account_id,
                    $deal->id,
                    $journal->id,
                    $clientId,
                    $contract->id,
                    $rule,
                    $contract
                );
            }

            // ---- Principal ----
            if ($principal > 0) {
                $rule = $this->getPostingRule($this->resolveEvent('pay_mother_amount', $class, $cash));

                $journalPrincipal = $this->postEntry(
                    $date,
                    $docNum,
                    DocumentJournal::PAY_MOTHER_AMOUNT,
                    $principal + $partial_amount,
                    'mother_amount_payment',
                    $rule->debit_account_id,
                    $rule->credit_account_id,
                    $deal->id,
                    $journal->id,
                    $clientId,
                    $contract->id,
                    $rule,
                    $contract
                );
            }

            // ---- Prepayment principal (before due date) → Dr 10000/102101 / Cr 39920 ----
            if ($prepaymentPrincipal > 0) {
                $prepaymentEvent = $cash ? 'prepayment_received_cash' : 'prepayment_received';
                $rule = $this->getPostingRule($prepaymentEvent);

                $this->postEntry(
                    $date,
                    $docNum,
                    DocumentJournal::PREPAYMENT_RECEIVED,
                    $prepaymentPrincipal,
                    'prepayment_payment',
                    $rule->debit_account_id,
                    $rule->credit_account_id,
                    $deal->id,
                    $journal->id,
                    $clientId,
                    $contract->id,
                    $rule,
                    $contract
                );
            }

            // ---- Off Balance + Recovery (loss only) ----
            if ($class === 'loss') {
                $lossRules = PostingRule::whereIn('business_event_filter', [
                    'recovery_principal',
                    'recovery_interest',
                ])->get()->keyBy('business_event_filter');

                // Off-balance outgoing for interest
                if ($interest > 0) {

                    // Write-off reversal: Dr null / Cr 86001 (interest recovery)

                    $this->postEntry(
                        $date,
                        $docNum,
                        DocumentJournal::RECOVERY_INTEREST,
                        $interest,
                        'interest_recovery',
                        $lossRules['recovery_interest']->debit_account_id,
                        $lossRules['recovery_interest']->credit_account_id,
                        $deal->id,
                        $journal->id,
                        $clientId,
                        $contract->id,
                        $lossRules['recovery_interest'],
                        $contract
                    );
                }

                // Off-balance outgoing for principal
                if ($principal > 0) {
                    // Write-off reversal: Dr null / Cr 86000 (principal recovery)
                    $this->postEntry(
                        $date,
                        $docNum,
                        DocumentJournal::RECOVERY_PRINCIPAL,
                        $principal,
                        'principal_recovery',
                        $lossRules['recovery_principal']->debit_account_id,
                        $lossRules['recovery_principal']->credit_account_id,
                        $deal->id,
                        $journal->id,
                        $clientId,
                        $contract->id,
                        $lossRules['recovery_principal'],
                        $contract
                    );
                }
            }

            // ===== Modification =====
            $new = $old + $amount;

            Modification::create([
                'subject_type' => Contract::class,
                'subject_id'   => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'   => 'AmountsPaid',
                'element_code' => 'Amount',
                'old_value'    => (string)$old,
                'new_value'    => (string)$new,
                'effective_date'=> $date,
            ]);

            $this->activityService->log(
                'make_payment',
                "Payment: {$amount} AMD (contract #{$contract->id})",
                Contract::class,
                $contract->id
            );

            DB::commit();

            $successPayload = ['success' => true, 'message' => 'Successfully created payment...'];
//            IdempotencyKey::where('key', $request->header('Idempotency-Key'))->update([
//                'status_code' => 200,
//                'response'    => json_encode($successPayload),
//                'locked_at'   => null,
//            ]);

            if ($date < now()->toDateString()) {
                $lastCalculatedDate = DocumentJournal::where('journalable_id', $journal->id)
                    ->where('journalable_type', DocumentJournal::class)
                    ->whereIn('document_type', [
                        DocumentJournal::EFFECTIVE_RATE_AMOUNT,
                        DocumentJournal::INTEREST_RATE_AMOUNT,
                    ])
                    ->max('date');

                if ($lastCalculatedDate && $lastCalculatedDate >= $date) {
                    RecalculateContractRangeJob::dispatch($contract->id, $date, $lastCalculatedDate);
                }
            }

            return response()->json($successPayload);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Payment failed: '.$e->getMessage()
            ], 500);
        }
    }
    private function updateContractStatus($contract, $date = null)
    {
        $paymentsLeft = $contract->payments->where('status', 'initial');
        $justCompleted = false;

        if ($paymentsLeft->isEmpty()) {
            $contract->status = 'completed';
            $contract->closed_at = $date ?? Carbon::now()->toDateString();
            $contract->left = 0;
            $justCompleted = true;

            $modifications = [
                [
                    'subject_type' => Contract::class,
                    'subject_id' => $contract->id,
                    'modification_type' => 'Modificator',
                    'field_code' => 'LoanStatus',
                    'element_code' => 'YN',
                    'old_value' => 'Y',
                    'new_value' => 'N',
                    'effective_date' => $date ?? now()->toDateString(),
                ],
            ];
            Modification::insert($modifications);
        }

        $contract->save();

        if ($justCompleted) {
            try {
                $this->releaseReserveBalancesIfClientFullyClosed(
                    clientId:   $contract->client_id,
                    contractId: $contract->id,
                    date:       $date ?? now()->format('Y-m-d'),
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error(
                    "Reserve release after contract completion failed for contract #{$contract->id}: " . $e->getMessage()
                );
            }
        }
    }

    public function previewPayment(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id'       => 'required|exists:contracts,id',
            'amount'            => 'required|numeric|min:0.01',
            'date'              => 'nullable|date',
            'payment_mechanism' => 'nullable|in:early_split,prepayment,interest,principal',
            'payment_ids'       => 'nullable|array',
            'payment_ids.*'     => 'integer|exists:payments,id',
        ]);

        $contract = Contract::findOrFail($request->contract_id);
        $date     = $request->date ?? now()->toDateString();

        if ($error = $this->postingDatePolicy->validate($date)) {
            return $error;
        }
        $breakdown = $this->paymentService->previewPaymentBreakdown(
            $contract,
            (float) $request->amount,
            $date,
            $request->input('payment_mechanism', 'early_split'),
            $request->input('payment_ids', [])
        );
        return response()->json(array_merge(
            ['total_amount' => (float) $request->amount],
            $breakdown
        ));
    }

    /**
     * Recalculate late-payment interest for a contract's schedule without recording
     * a payment — lets the frontend refresh the table ahead of an actual payment.
     */
    public function recalculateSchedule(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|exists:contracts,id',
            'date'        => 'nullable|date',
        ]);

        $contract = Contract::findOrFail($request->contract_id);
        $date     = $request->date ?? now()->toDateString();

        if ($error = $this->postingDatePolicy->validate($date)) {
            return $error;
        }

        if ($contract->payment_type === 'amortized') {
            $this->paymentService->recalculateLatePaymentInterest($contract, $date);
        }

        return response()->json([
            'message' => 'Schedule recalculated successfully.',
        ]);
    }

    public function makeFullPayment(Request $request): JsonResponse
    {
            $idempotencyKey = $request->header('Idempotency-Key');
            $contract = Contract::findOrFail($request->contract_id);
            $totalAmount = $request->amount;
            $payer = $request->payer;
            $cash = $request->cash;
            $date = $request->contract_created_date ?? Carbon::now()->format('Y-m-d');

            if ($error = $this->postingDatePolicy->validate($date)) {
                return $error;
            }
            $currentPaymentData = $this->calculateCurrentPayment($contract);

            $interestAmount = is_array($currentPaymentData) ? ($currentPaymentData['current_amount'] ?? 0) : 0;
            $motherAmount = $contract->provided_amount;

            $type = HistoryType::where('name', 'full_payment')->first();
            $purpose = 'Վարկի մարում՝ տոկոսագումար և մայր գումար';

            $newOrder = $this->generateOrder($contract, $totalAmount, $purpose, 'in', $cash, Order::FULL_FILTER, $date);

            $history = History::create([
                'amount' => $totalAmount,
                'type_id' => $type->id,
                'user_id' => auth()->id(),
                'order_id' => $newOrder->id,
                'contract_id' => $contract->id,
                'date' => $date,
            ]);

            $deal = $this->createDeal($totalAmount, null, null, null, null, 'in', $contract->id, $contract->client->id, $newOrder->id, $cash, null, Contract::FULL_PAYMENT, 'full_payment', $history->id, null, null, null, $date);
            $oldPaymentAmount = $this->calcPaidAmount($contract);
            $fullPaymentResult = $this->paymentService->processFullPayment($contract, $totalAmount, $payer, $cash, $deal->id,$date);
            $paymentId = $fullPaymentResult['payment_id'];
            $prepaymentRefundAmount = $fullPaymentResult['refund_amount'];
            $newPaymentAmount = $oldPaymentAmount + $totalAmount;
            $deal->payment_id = $paymentId;
            $deal->save();
            if ($motherAmount > 0) {
                $ruleKey = $cash ? 'pay_mother_amount_cash' : 'pay_mother_amount';
                $docId = $this->createAccountingTransaction(
                    $contract, $motherAmount, $ruleKey, 'mother_amount_payment', $deal->id, $date
                );

                $classificationName = $contract->client->classification->name ?? 'standard';

                $eventFilter = ($classificationName === 'standard')
                    ? 'provide_general_amount_change'
                    : 'provide_special_amount_change';

                $ruleReserve = PostingRule::where('business_event_filter', $eventFilter)->first();
                if ($ruleReserve) {
                    $reservePercent = $contract->client->classification->reserve_percent ?? 0;
                    $reserveAmount = $motherAmount * $reservePercent / 100;

                    if ($reserveAmount > 0) {
                        $clientId = $contract->client->id;
                        $nextDocNum = Transaction::getNextDocumentNumber();
                        $documentType = ($classificationName === 'standard')
                            ? DocumentJournal::PROVIDED_AMOUNT_CHANGE
                            : DocumentJournal::RESERVE_SPECIAL_AMOUNT;
                        $journalDocRes = DocumentJournal::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => $documentType,
                            'amount_amd' => $reserveAmount,
                            'debit_partner_id' => $ruleReserve->resolveDebitPartnerId($contract) ?? $clientId,
                            'credit_partner_id' => $ruleReserve->resolveCreditPartnerId($contract) ?? null,
                            'comment' => 'reserve_payment',
                            'debit_account_id' => $ruleReserve->debit_account_id,
                            'credit_account_id' => $ruleReserve->credit_account_id,
                            'user_id' => auth()->id(),
                            'journalable_type' => DocumentJournal::class,
                            'journalable_id' => $docId,
                            'contract_id' => $contract->id,
                        ]);

                        Transaction::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => $documentType,
                            'debit_account_id' => $ruleReserve->debit_account_id,
                            'debit_partner_id' => $ruleReserve->resolveDebitPartnerId($contract) ?? $clientId,
                            'credit_account_id' => $ruleReserve->credit_account_id,
                            'credit_partner_id' => $ruleReserve->resolveCreditPartnerId($contract) ?? null,
                            'amount_amd' => $reserveAmount,
                            'comment' => 'reserve_amount',
                            'user_id' => auth()->id(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id' => $journalDocRes->id,
                            'contract_id' => $contract->id,
                        ]);
                    }
                }
            }
            if (($interestAmount) > 0) {
                $ruleKey = $cash ? 'pay_interest_amount_cash' : 'pay_interest_amount';
                $this->createAccountingTransaction(
                    $contract, ($interestAmount), $ruleKey, 'interest_payment', $deal->id, $date
                );
            }

            $balanceRow = $this->partnerAccountBalancesSubquery($date)
                ->where('u.partner_id', $contract->client_id)
                ->where('ca.code', '16200')
                ->first();

            $account16200Balance = $balanceRow ? $balanceRow->balance : 0;

            if (abs($account16200Balance) > 0) {
                $this->createAccountingTransaction(
                    $contract,
                    abs($account16200Balance),
                    'close_contract_rule',
                    'contract_closure',
                    $deal->id,
                    $date
                );
            }
            $contract->closed_at = $date;
            $contract->save();
            Modification::create([
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'modification_type' => 'Modificator',
                'field_code' =>'AmountsPaid',
                'element_code' => 'Amount',
                'old_value' => (string)$oldPaymentAmount,
                'new_value' => (string)($newPaymentAmount),
                'effective_date' => $date,
            ]);

            // ── B4: refund whatever bucket amount could not be offset against payoff ──
            if ($prepaymentRefundAmount > 0) {
                $prepaymentRefundOrder = $this->generateOrder(
                    $contract, $prepaymentRefundAmount, Order::REFUND_PREPAYMENT, 'out', $cash, Order::REFUND_PREPAYMENT_FILTER, $date
                );
                $prepaymentRefundType = HistoryType::where('name', 'prepayment_refund')->first();

                History::create([
                    'amount'      => $prepaymentRefundAmount,
                    'type_id'     => $prepaymentRefundType->id,
                    'user_id'     => auth()->user()->id,
                    'order_id'    => $prepaymentRefundOrder->id,
                    'contract_id' => $contract->id,
                    'date'        => $date,
                ]);

                $prepaymentRefundDeal = $this->createDeal(
                    $prepaymentRefundAmount, null, null, null, null, 'out', $contract->id, $contract->client->id,
                    $prepaymentRefundOrder->id, $cash, null, Order::REFUND_PREPAYMENT, Order::REFUND_PREPAYMENT_FILTER,
                    null, null, null, null, $date
                );
                DealAction::create([
                    'deal_id'         => $prepaymentRefundDeal->id,
                    'actionable_id'   => $paymentId,
                    'actionable_type' => Payment::class,
                    'amount'          => $prepaymentRefundAmount,
                    'type'            => 'refund',
                    'description'     => 'Prepayment bucket refund',
                    'date'            => $date,
                ]);
            }

            if (Carbon::now()->lessThan(Carbon::parse($contract->deadline))) {
                $refundAmount = $this->calculateRefundAmount($contract->mother,$contract->lump_rate,$contract->deadline,$contract->deadline_days);
                if ($refundAmount > 0) {
                    $refundOrder = $this->generateOrder($contract, $refundAmount, Order::REFUND_LUMP, 'out', $cash, Order::REFUND_LUMP_FILTER, $date);
                    $refund_type = HistoryType::where('name', 'one_time_payment_refund')->first();

                    History::create([
                        'amount' => $refundAmount,
                        'type_id' => $refund_type->id,
                        'user_id' => auth()->user()->id,
                        'order_id' => $refundOrder->id,
                        'contract_id' => $contract->id,
                        'date' => $date,
                    ]);

                    $deal = $this->createDeal($refundAmount, null, null, null, null, 'out', $contract->id, $contract->client->id, $refundOrder->id, $cash, null, Order::REFUND_LUMP, Order::REFUND_LUMP_FILTER, null, null, null, null, $date);
                    DealAction::create([
                        'deal_id' => $deal->id,
                        'actionable_id' => $paymentId,
                        'actionable_type' => Payment::class,
                        'amount' => $refundAmount,
                        'type' => 'refund',
                        'description' => 'Refund payment',
                        'date' => $date,
                    ]);
                    $payload = ['success' => 'success', 'message' => 'Full payment created successfully with a lump sum refund', 'refund_amount' => $refundAmount, 'prepayment_refund_amount' => $prepaymentRefundAmount];
                    if ($idempotencyKey) {
                        IdempotencyKey::where('key', $idempotencyKey)->update(['status_code' => 200, 'response' => json_encode($payload), 'locked_at' => null]);
                    }
                    return response()->json($payload);
                }
            }
            if ((isset($refundAmount) && $refundAmount > 0) || $prepaymentRefundAmount > 0) {
                $payload = ['success' => 'success', 'message' => 'Full payment created successfully with a refund', 'refund_amount' => $refundAmount ?? 0, 'prepayment_refund_amount' => $prepaymentRefundAmount];
                if ($idempotencyKey) {
                    IdempotencyKey::where('key', $idempotencyKey)->update(['status_code' => 200, 'response' => json_encode($payload), 'locked_at' => null]);
                }
                return response()->json($payload);
            }

            $payload = ['success' => 'success', 'message' => 'Full payment created successfully'];
            if ($idempotencyKey) {
                IdempotencyKey::where('key', $idempotencyKey)->update(['status_code' => 200, 'response' => json_encode($payload), 'locked_at' => null]);
            }
            return response()->json($payload);



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
        $date = $request->posting_date ?? now()->toDateString();

        if ($error = $this->postingDatePolicy->validate($date)) {
            return $error;
        }

        $has_penalty_amount = $this->countPenalty($request->contract_id);
        if ($has_penalty_amount['penalty_amount'] > 0) {
            return response()->json([
                'message' => 'You have an unpaid penalty amount! ',
            ], 404);
        }

        $contract_id = $request->contract_id;
        $contract = Contract::findOrFail($contract_id);

        $partialAmount = $request->amount;
        $is_recount = $request->is_recount;

        if ($contract->provided_amount <= 0) {
            return response()->json([
                'message' => 'You have already paid the principal amount.'
            ], 400);
        }

        if ($partialAmount > $contract->provided_amount) {
            return response()->json([
                'message' => 'The amount entered is greater than the remaining principal amount.',
                'max_allowed' => $contract->provided_amount
            ], 400);
        }


        $payer = $request->payer;
        $cash = $request->cash;

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
            'date' => $date,
            'client_name' => $client_name,
            'purpose' => 'Մասնակի մարում',
            'cash' => $cash,
            'filter' => Order::PARTIAL_FILTER,
            'user_id' => auth()->id(),
        ];
        $new_order = Order::create($res);
        $history = History::create([
            'amount' => $partialAmount,
            'user_id' => auth()->user()->id,
            'type_id' => $history_type->id,
            'order_id' => $new_order->id,
            'contract_id' => $contract->id,
            'date' => $date,
        ]);
        $deal = $this->createDeal($partialAmount, null,null, null,null,'in', $contract->id,$contract->client->id, $new_order->id, $cash,null, Contract::PARTIAL_PAYMENT,'partial_payment',$history->id);

        if ($is_recount) {
            $deal->is_recount = $is_recount;
            $deal->save();
        }
        $oldPaymentAmount = $this->calcPaidAmount($contract);
        $payment_id = $this->paymentService->payPartial($contract, $partialAmount, $payer, $cash,$deal->id,null,$is_recount);

        $deal->payment_id = $payment_id;
        $deal->save();

        $this->updateContractStatus($contract, $date);
        $this->activityService->log(
            'partial_payment',
            "Partial payment: {$partialAmount} AMD for contract #{$contract->id} and deal #{$deal->id}",
            Contract::class,
            $contract->id
        );
        $newPaymentAmount = $oldPaymentAmount + $partialAmount;
        Modification::create([
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
            'modification_type' => 'Modificator',
            'field_code' => 'AmountsPaid',
            'element_code' => 'Amount',
            'old_value' => (string)$oldPaymentAmount,
            'new_value' => (string)($newPaymentAmount),
            'effective_date' => $date,
        ]);
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
                'filter' => Order::EXPENSE_FILTER,
                'user_id' => auth()->id(),
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

    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'payments'                    => ['required', 'array', 'min:1'],
            'payments.*.id'               => ['required', 'integer', 'exists:payments,id'],
            'payments.*.date'             => ['sometimes', 'nullable', 'date'],
            'payments.*.amount'           => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payments.*.paid'             => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payments.*.interest_payment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payments.*.principal_payment'=> ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payments.*.penalty'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payments.*.status'           => ['sometimes', 'nullable', 'string', 'in:initial,completed,overdue'],
        ]);

        $allowed = ['date', 'amount', 'paid', 'interest_payment', 'principal_payment', 'penalty', 'status'];

        DB::beginTransaction();
        try {
            foreach ($request->input('payments') as $row) {
                $fields = array_intersect_key($row, array_flip($allowed));

                if (empty($fields)) {
                    continue;
                }

                Payment::where('id', $row['id'])->update($fields);
            }

            DB::commit();

            return response()->json(['message' => 'Վճարումները հաջողությամբ թարմացվեցին']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Թարմացումը ձախողվեց',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function calcPaidAmount(Contract $contract)
    {
        return PaymentEntry::where('contract_id', $contract->id)->sum('amount');
    }
}
