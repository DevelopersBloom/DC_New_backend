<?php

namespace App\Services;

use App\Models\Contract;
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
        $this->calculateClassificationData($contract);

        // 7. Տրամադրման/Մարման Օրեր
        $this->calculateDaysData($contract, $calcToday);


        return $contract;
    }


    /**
     * Հաշվարկում է Տոկոսը, Արդյունավետ Տոկոսը և Արդյունավետ Տոկոսադրույքը
     */
    protected function calculateInterestRates(Contract $contract, Carbon $calcToday): void
    {
        $startDate = Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay();
        $days = $calcToday->diffInDays($startDate);

        // Արդյունավետ Տոկոսադրույք
//        $effectiveRate = $contract->effective_daily_rate ?? 0;
//        if ($contract->payment_type == 'amortized') {
//            $contract->effectiveRate = $this->effectiveRateService->calculateEffectiveRate($contract, $calcToday);
//        }

        $calculatedInterest = null;
        $calculatedEffectiveInterest = null;
        if (!empty($contract->provided_amount) && !empty($contract->interest_rate)) {

            $calculatedInterest = $this->calcAmount(
                $contract->provided_amount,
                $days,
                $contract->interest_rate
            );

//            if ($contract->effectiveRate > 0) {
                $calculatedEffectiveInterest = $this->calcAmount(
                    $contract->provided_amount,
                    $days,
                    $contract->effective_daily_rate
                );
//                $calculatedEffectiveInterest = intval(ceil($contract->provided_amount *  $contract->effectiveRate / 100 * 0.01 / 10) * 10);

//            }
        }
        $contract->effectiveRate = $contract->effective_daily_rate;
        $contract->calculatedInterest = $calculatedInterest;
        $contract->calculatedEffectiveInterest = $calculatedEffectiveInterest;
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
                ->sum(fn($p) => max(0, (float)($p->interest_payment ?? 0)));
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

        if (($contract->client?->classification->name) === 'loss') {
            $writtenOff = max(0, (float)($contract->provided_amount ?? 0));
        }
        $contract->written_off_amount = $writtenOff !== null ? round($writtenOff, 2) : null;

        $writtenOffInterest = null;
        if (!empty($contract->written_off_amount) && !empty($contract->interest_rate)) {

            $writeOffDate = Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay();

            $days = 0;
            if ($calcToday->gt($writeOffDate)) {
                $days = $writeOffDate->diffInDays($calcToday);
            }

            $writtenOffInterest = $this->calcAmount(
                $contract->written_off_amount,
                $days,
                $contract->interest_rate
            );
        }
        $contract->written_off_interest = $writtenOffInterest;
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
    protected function calculateClassificationData(Contract $contract): void
    {
        $classificationData = $this->clientClassificationService->getClassificationData($contract);

        $contract->reserve     = $classificationData['reserve'];
        $contract->risk_weight = $classificationData['risk_weight'];
    }

    /**
     * Հաշվարկում է Տրամադրման Օրերը (contract->date-ից մինչև deadline) և
     * Մնացորդային Մարման Օրերը (contract->date-ից մինչև $calcToday)
     */
    protected function calculateDaysData(Contract $contract, Carbon $calcToday): void
    {
        $toDay = Carbon::now()->startOfDay();
        $startDate = $contract->date ? Carbon::parse($contract->date)->startOfDay() : null;

        $daysProvided = 0;

        $deadlineDate = $contract->deadline
            ?: ($contract->payments_max_to_date ?: $contract->payments->max('to_date'));

        if ($deadlineDate) {
            $deadline = Carbon::parse($deadlineDate)->startOfDay();

            if ($toDay->gt($deadline)) {
                $daysProvided = $deadline->diffInDays($toDay);
            }
        }
        $contract->days_provided = $daysProvided;


        $remainingRepaymentDays = 0;
        if ($calcToday) {
            if ($calcToday->gt($startDate)) {
                $remainingRepaymentDays = $startDate->diffInDays($calcToday);
            }
        }
        $contract->remaining_repayment_days = $remainingRepaymentDays;
    }
}
