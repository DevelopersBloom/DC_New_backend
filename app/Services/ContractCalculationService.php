<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Traits\ContractTrait;
use Illuminate\Support\Carbon;

class ContractCalculationService
{
    use ContractTrait;

    protected PaymentService $paymentService;
    protected EffectiveRateService $effectiveRateService;
    protected ClientClassificationService $clientClassificationService;

    public function __construct(
        PaymentService $paymentService,
        EffectiveRateService $effectiveRateService,
        ClientClassificationService $clientClassificationService
    ) {
        $this->paymentService = $paymentService;
        $this->effectiveRateService = $effectiveRateService;
        $this->clientClassificationService = $clientClassificationService;
    }

    /**
     * Հիմնական ֆունկցիա՝ բոլոր չափանիշները հաշվարկելու համար
     *
     * @param Contract $contract
     * @param Carbon $calcToday Ընտրված հաշվարկային օրը
     * @return Contract
     */
    public function  calculateAllMetrics(Contract $contract, Carbon $calcToday): Contract
    {
        // 2. Տոկոսի և Արդյունավետ Տոկոսադրույքի Հաշվարկ (Մինչև $calcToday)
        $this->calculateInterestRates($contract, $calcToday);

        // 3. Չվաստակած Տոկոս
        $this->calculateUnearnedInterest($contract, $calcToday);
        // 4. Դուրս Գրված Գումար/Տոկոս
        $this->calculateWrittenOffData($contract, $calcToday);

        // 5. Ժամկետանց Գումար և Տոկոսներ
        $this->calculateOverdueData($contract, $calcToday);

        // 6. Պահուստ և Ռիսկի Կշիռ
        $this->calculateClassificationData($contract, $calcToday);

        // 7. Տրամադրման/Մարման Օրեր
        $this->calculateDaysData($contract, $calcToday);

        // 8. Տոկոսագումարը, որը հաշվարկվում է օրական
        $this->calculateDailyRates($contract,$calcToday);


        return $contract;
    }

    //    Տոկոսագումարը, որը հաշվարկվում է օրական
    public function calculateDailyRates(Contract $contract): float
    {
        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->first();

        if (!$journal) {
            return 0;
        }

        $dailyInterestSum = DocumentJournal::where('journalable_type', DocumentJournal::class)
            ->where('journalable_id', $journal->id)
            ->where('document_type',DocumentJournal::INTEREST_RATE_AMOUNT)
            ->sum('amount_amd');

        $dailyEffectiveSum = DocumentJournal::where('journalable_type', DocumentJournal::class)
            ->where('journalable_id', $journal->id)
            ->where('document_type',DocumentJournal::EFFECTIVE_RATE_AMOUNT)
            ->sum('amount_amd');

         $contract->daily_interest_sum = $dailyInterestSum;
         $contract->daily_effective_sum =  $dailyEffectiveSum;
    }


    /**
     * Հաշվարկում է Տոկոսը, Արդյունավետ Տոկոսը և Արդյունավետ Տոկոսադրույքը
     */
//    public function calculateInterestRates(Contract $contract, Carbon $calcToday): void
//    {
//        $startDate = Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay();
//        $days = $calcToday->diffInDays($startDate);
//
//        $calculatedInterest = null;
//        $calculatedEffectiveInterest = null;
//        $providedAmount = $contract->provided_amount;
//        if (!empty($contract->provided_amount) && !empty($contract->interest_rate)) {
//
////            $calculatedInterest = $this->calcAmount(
////                $contract->provided_amount,
////                $days,
////                $contract->interest_rate
////            );
//            $calculatedInterest = $providedAmount * $contract->interest_rate/100 * $days;
//
////            if ($contract->effectiveRate > 0) {
////                $calculatedEffectiveInterest = $this->calcAmount(
////                    $contract->provided_amount,
////                    $days,
////                    $contract->effective_daily_rate
////                );
//                $calculatedEffectiveInterest = $providedAmount * $contract->effective_daily_rate / 100 * $days;
////            }
//        }
//        $contract->effectiveRate = $contract->effective_daily_rate;
//        $contract->calculatedInterest = $calculatedInterest;
//        $contract->calculatedEffectiveInterest = $calculatedEffectiveInterest;
//    }
    public function calculateInterestRates(Contract $contract, Carbon $calcToday): void
    {
        $calcToday = $calcToday->copy()->setTimezone('Asia/Yerevan')->startOfDay();

        $providedAmount = (float) ($contract->provided_amount ?? 0);
        $calculatedInterest = 0.0;
        $calculatedEffectiveInterest = 0.0;

        $contractJournal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();

        $lastEffective = null;

        if ($contractJournal) {
            $lastEffective = DocumentJournal::where('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT)
                ->where('journalable_type', DocumentJournal::class)
                ->where('journalable_id', $contractJournal->id)
                ->orderBy('date', 'desc')
                ->first();
        }

        if ($lastEffective) {
            $startDate = Carbon::parse($lastEffective->date, 'Asia/Yerevan')->startOfDay();
        } else {
            $startDate = Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay();
        }

        if ($startDate->greaterThan($calcToday)) {
            $days = 0;
        } else {
            $days = $calcToday->diffInDays($startDate);
        }

        if ($providedAmount > 0 && !empty($contract->interest_rate) && $days > 0) {
            $calculatedInterest = $providedAmount * ($contract->interest_rate / 100.0) * $days;
        }

        if ($providedAmount > 0 && !empty($contract->effective_daily_rate) && $days > 0) {
            $calculatedEffectiveInterest = $providedAmount * ($contract->effective_daily_rate / 100.0) * $days;
        }

        $contract->effectiveRate = $contract->effective_daily_rate;
        $contract->calculatedInterest = round($calculatedInterest, 2);
        $contract->calculatedEffectiveInterest = round($calculatedEffectiveInterest, 2);

    }

    /**
     * Հաշվարկում է Չվաստակած Տոկոսը
     */
    protected function calculateUnearnedInterest(Contract $contract, Carbon $calcToday): void
    {
        $unearnedInterest = 0;

        $futureInitialPayments = $contract->payments
            ->where('status', 'initial')
            ->filter(fn($p) =>
            Carbon::parse($p->to_date)->startOfDay()->gt($calcToday)
            );

        if ($contract->payment_type === 'amortized') {
            $unearnedInterest = $futureInitialPayments
                ->sum(fn($p) => max(0, (float)$p->interest_payment - (float)$p->paid));
        } else {
            $unearnedInterest = $futureInitialPayments
                ->sum(fn($p) => max(0, (float)($p->amount ?? 0)));
        }
        $contract->unearned_interest = round($unearnedInterest, 2);
    }

    /**
     * Հաշվարկում է Դուրս Գրված Գումարը և Դուրս Գրված Տոկոսը
     */
    protected function calculateWrittenOffData(Contract $contract, Carbon $calcToday): void
    {
        $writtenOff = null;
        $writtenOffInterest = null;

        if (($contract->client?->classification?->name) === 'loss') {

            if ($contract->payment_type === 'amortized') {

                $writtenOff = $contract->payments()
                    ->where('status', 'initial')
                    ->sum('principal_payment');

                $writtenOffInterest = $contract->payments()
                    ->where('status', 'initial')
                    ->sum('interest_payment');

            } else {

                $writtenOff = max(0, (float)($contract->provided_amount ?? 0));

                $writtenOffInterest = $contract->payments()
                    ->where('status', 'initial')
                    ->sum('amount');
            }
        }

        $contract->written_off_amount = $writtenOff !== null ? round($writtenOff, 2) : null;
        $contract->written_off_interest = $writtenOffInterest !== null ? round($writtenOffInterest, 2) : null;

    }


    /**
     * Հաշվարկում է Ժամկետանց Գումարը, Ժամկետանց Տոկոսը և Ժամկետանց Գումարի Տոկոսը
     */
    protected function calculateOverdueData(Contract $contract, Carbon $calcToday): void
    {
        $overdueAmount = 0.0;
        $overdueInterest = 0.0;
        $overdueAmountInterest = null;

        $initialOverduePayments = $contract->payments
            ->where('status', 'initial')
            ->filter(fn($p) => Carbon::parse($p->to_date)->startOfDay()->lt($calcToday));

        if ($contract->payment_type === 'amortized') {
            $overdueAmount = $initialOverduePayments->sum(fn($p) => max(0, (float)($p->principal_payment ?? 0)));
            $overdueInterest = $initialOverduePayments->sum(fn($p) => max(0, (float)($p->interest_payment ?? 0)));

        } elseif ($contract->payment_type === 'classic') {
            $lastPaymentRow = $contract->payments->where('last_payment', 1)->first();

            if ($lastPaymentRow && Carbon::parse($lastPaymentRow->to_date)->startOfDay()->lt($calcToday)) {
                $overdueAmount = max(0, (float)($lastPaymentRow->mother ?? 0));
            }
            $overdueInterest = $initialOverduePayments->sum(fn($p) => max(0, (float)($p->amount ?? 0)));
        }

        $contract->overdue_amount   = round($overdueAmount, 2);
        $contract->overdue_interest = round($overdueInterest, 2);

        if (!empty($contract->overdue_amount) && !empty($contract->interest_rate)) {

            $lastDue = $contract->payment_type === 'classic'
                ? ($contract->payments->firstWhere('last_payment', 1)?->to_date)
                : ($contract->payments_max_to_date);

            $days = 0;
            if ($lastDue) {
                $due = Carbon::parse($lastDue, 'Asia/Yerevan')->startOfDay();
                if ($calcToday->gt($due)) {
                    $days = $due->diffInDays($calcToday);
                }
            }

            $overdueAmountInterest = $this->calcAmount(
                $contract->overdue_amount,
                $days,
                $contract->interest_rate
            );
        }

        $contract->overdue_amount_interest = $overdueAmountInterest;
    }

    /**
     * Հաշվարկում է Պահուստը և Ռիսկի Կշիռը
     */
    protected function calculateClassificationData(Contract $contract,$date = null): void
    {
        $classificationData = $this->clientClassificationService->getClassificationData($contract,$date);

        $contract->reserve     = $classificationData['reserve'];
        $contract->risk_weight = $classificationData['risk_weight'];
    }

    /**
     * Հաշվարկում է Տրամադրման Օրերը (contract->date-ից մինչև deadline) և
     * Մնացորդային Մարման Օրերը (contract->date-ից մինչև $calcToday)
     */
    protected function calculateDaysData(Contract $contract, Carbon $calcToday): void
    {
        $toDay = Carbon::now()->format('Y-m-d');
        $startDate = $contract->date ? Carbon::parse($contract->date)->startOfDay() : null;

        $daysProvided = 0;

        $deadlineDate = $contract->deadline;
//            ?: ($contract->payments_max_to_date ?: $contract->payments->max('to_date'));

        if ($deadlineDate) {
            $deadline = Carbon::parse($deadlineDate);
//            $deadline = Carbon::parse($deadlineDate)->startOfDay();

//            if ($toDay->gt($deadline)) {
            $daysProvided = $deadline->diffInDays($startDate);
//            }
        }
        $contract->days_provided = $daysProvided;


        $remainingRepaymentDays = 0;
        $remainingRepaymentDays = $deadline->diffInDays($toDay);

//        if ($calcToday) {
//            if ($calcToday->gt($deadline)) {
//                $remainingRepaymentDays = $startDate->diffInDays($calcToday->format('Y-m-d'));
//            }
//        }
        $contract->remaining_repayment_days = $remainingRepaymentDays;
    }
}
