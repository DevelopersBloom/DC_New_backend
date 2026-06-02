<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
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
    public function calculateDailyRates(Contract $contract,$calcToday): void
    {
        $calcDay = Carbon::parse($calcToday, 'Asia/Yerevan')->startOfDay();
        $nominalDailyRate = (float)($contract->interest_rate ?? 0);
        $effectiveDailyRate = (float)($contract->effective_daily_rate ?? 0);
        $paidInterests = $this->calculatePaidInterestsToDate($contract, $calcDay);

        $accruedNominal = $this->calculateAccruedByProvidedAmountHistory($contract, $calcDay, $nominalDailyRate);
        $accruedEffective = $this->calculateAccruedByProvidedAmountHistory($contract, $calcDay, $effectiveDailyRate);

        $contract->daily_interest_sum = round(
            max(0, $accruedNominal - $paidInterests['nominal']),
            2
        );
        $contract->daily_effective_sum = round(
            max(0, $accruedEffective - $paidInterests['effective']),
            2
        );
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
        $accruedNominal = $this->calculateAccruedByProvidedAmountHistory(
            $contract,
            $calcToday,
            (float)($contract->interest_rate ?? 0)
        );
        $accruedEffective = $this->calculateAccruedByProvidedAmountHistory(
            $contract,
            $calcToday,
            (float)($contract->effective_daily_rate ?? 0)
        );
        $paidInterests = $this->calculatePaidInterestsToDate($contract, $calcToday);

        $calculatedInterest = max(0, $accruedNominal - $paidInterests['nominal']);
        $calculatedEffectiveInterest = max(0, $accruedEffective - $paidInterests['effective']);

        $contract->effectiveRate = $contract->effective_daily_rate;
        $contract->calculatedInterest = round($calculatedInterest, 2);
        $contract->calculatedEffectiveInterest = round($calculatedEffectiveInterest, 2);

    }

    /**
     * Calculates daily-rate accrual using only provided amount history up to calc day.
     * If no history exists, falls back to contract provided amount from contract date.
     */
    private function calculateAccruedByProvidedAmountHistory(Contract $contract, Carbon $calcDay, float $dailyRate): float
    {
        if ($dailyRate <= 0) {
            return 0.0;
        }

        $startDate = $contract->date
            ? Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay()
            : $calcDay->copy();

        if ($startDate->greaterThan($calcDay)) {
            return 0.0;
        }

        $providedAmountHistory = ContractAmountHistory::query()
            ->where('contract_id', $contract->id)
            ->where('amount_type', 'provided_amount')
            ->whereDate('date', '<=', $calcDay->toDateString())
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get(['amount', 'type', 'date']);

        if ($providedAmountHistory->isEmpty()) {
            $baseBalance = max(0, (float)($contract->provided_amount ?? 0));
            if ($baseBalance <= 0) {
                return 0.0;
            }

            // Inclusive calc day: "as of 26.05" includes interest for that calendar day.
            $days = $startDate->diffInDays($calcDay) + 1;
            return $days > 0 ? $baseBalance * ($dailyRate / 100) * $days : 0.0;
        }

        $changesByDate = $providedAmountHistory
            ->groupBy(function ($row) {
                return Carbon::parse($row->date, 'Asia/Yerevan')->toDateString();
            })
            ->map(function ($rows) {
                return $rows->sum(function ($row) {
                    $amount = (float)($row->amount ?? 0);
                    return $row->type === 'out' ? -$amount : $amount;
                });
            });

        $accrued = 0.0;
        $currentDate = $startDate->copy();
        $balance = 0.0;

        foreach ($changesByDate as $date => $delta) {
            $changeDate = Carbon::parse($date, 'Asia/Yerevan')->startOfDay();
            $effectiveDate = $changeDate->copy()->addDay();

            if ($effectiveDate->greaterThan($calcDay)) {
                break;
            }

            if ($effectiveDate->greaterThan($currentDate) && $balance > 0) {
                $days = $currentDate->diffInDays($effectiveDate);
                if ($days > 0) {
                    $accrued += $balance * ($dailyRate / 100) * $days;
                }
            }

            $balance = max(0, $balance + (float)$delta);

            if ($effectiveDate->greaterThan($currentDate)) {
                $currentDate = $effectiveDate->copy();
            }
        }

        // Inclusive calc day (e.g. as of 26.05 includes interest on 26.05 at prior-day principal).
        if ($balance > 0 && $currentDate->lessThanOrEqualTo($calcDay)) {
            while ($currentDate->lessThanOrEqualTo($calcDay)) {
                $accrued += $balance * ($dailyRate / 100);
                $currentDate->addDay();
            }
        }

        return $accrued;
    }

    /**
     * Non-journal paid interest totals up to calculation day.
     */
    private function calculatePaidInterestsToDate(Contract $contract, Carbon $calcDay): array
    {
        $paymentPurposes = [
            Contract::REGULAR_PAYMENT,
            Contract::PARTIAL_PAYMENT,
            Contract::FULL_PAYMENT,
        ];

        $paidNominal = Deal::query()
            ->where('contract_id', $contract->id)
            ->whereIn('purpose', $paymentPurposes)
            ->get(['date', 'interest_amount'])
            ->sum(function ($deal) use ($calcDay) {
                $dealDate = $this->parseDealDate($deal->date);
                if (!$dealDate || $dealDate->greaterThan($calcDay)) {
                    return 0.0;
                }

                return (float)($deal->interest_amount ?? 0);
            });

        return [
            'nominal' => (float)$paidNominal,
            // Deals table does not store paid effective interest separately.
            'effective' => 0.0,
        ];
    }

    private function parseDealDate(?string $dealDate): ?Carbon
    {
        if (!$dealDate) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dealDate) === 1) {
                return Carbon::createFromFormat('d.m.Y', $dealDate, 'Asia/Yerevan')->startOfDay();
            }

            return Carbon::parse($dealDate, 'Asia/Yerevan')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
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
