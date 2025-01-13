<?php

namespace App\Exports;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Services\ExportSheet1Service;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MonthlySheet1Export implements FromView, WithEvents, WithColumnWidths, ShouldAutoSize, WithStyles
{
    use RegistersEventListeners;
    private $year;
    private $month;
    private $pawnshop_id;

    public function __construct($year,$month, $pawnshop_id)
    {
        $this->year = $year;
        $this->month = $month;
        $this->pawnshop_id = $pawnshop_id;
        $this->service = new ExportSheet1Service();

    }
    public function view(): View
    {
        $array = [];
        $month = $this->month;
        $year = $this->year;
        $pawnshop = Pawnshop::where('id', $this->pawnshop_id)->first();
        $lastDayOfMonth = Carbon::create($year, $month, 1)->endOfMonth()->format('d/m/Y');
        $lastDay_of_current_date = Carbon::createFromDate($year, $month)->endOfMonth()->toDateString();
        $lastDay_of_previous_date = Carbon::createFromDate($year, $month)->subMonthNoOverflow()->endOfMonth()->toDateString();
        $firstDayOfMonth = Carbon::create($year, $month, 1)->startOfMonth()->format('Y-m-d');
        $firstDayOfPreviousMonth = Carbon::create($year,$month,1)->subMonth()->startOfMonth()->format('Y-m-d');
        $current_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_current_date);
        $previous_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_previous_date);

        // Instantiate the service
        $exportService = new \App\Services\ExportSheet1Service();

        // Fetch contract stats
        $contractStats = $exportService->getContractStats($this->pawnshop_id, $current_date, $previous_date,$firstDayOfMonth,$firstDayOfPreviousMonth);
        // Fetch category breakdown (using category IDs like 1, 2, 3 for gold, electronics, cars)
        $categories = [1, 2, 3]; // gold, electronics, car
        $categoryBreakdown = $exportService->getCategoryBreakdown($contractStats, $categories);
        $gold_data = $categoryBreakdown[1];
        $electronics_data = $categoryBreakdown[2];
        $car_data = $categoryBreakdown[3];
        // Fetch interest payments for current and previous months
        $interestPayments = $exportService->getInterestPayments($contractStats['contract_ids'], $current_date, $previous_date,$firstDayOfMonth,$firstDayOfPreviousMonth);
        $dealStatsCurrent = $exportService->getDealStats($current_date, $pawnshop);
        $dealStatsPrevious = $exportService->getDealStats($previous_date, $pawnshop);
        $partial_payments_amount = Payment::where('type','partial')
            //->whereIn('contract_id',$contractStats['contract_ids'])
            //->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$current_date])
            ->where('date', '<=', $current_date)
            ->where('date', '>=', $firstDayOfMonth)
            ->sum('amount');
        $partial_previous_payments_amount = Payment::where('type','partial')
            //->whereIn('contract_id',$contractStats['contract_ids'])
            //->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$current_date])
            ->where('date', '<=', $previous_date)
            ->where('date', '>=', $firstDayOfPreviousMonth)
            ->sum('amount');
        $contractStats['current_given'] -= $partial_payments_amount;
        $contractStats['previous_given'] -= $partial_previous_payments_amount;
        // Fetch NDM stats
        $ndmCurrent = $exportService->getNDMStats($current_date,$firstDayOfMonth, Order::NDM_PURPOSE);
        $ndmPrevious = $exportService->getNDMStats($previous_date,$firstDayOfPreviousMonth, Order::NDM_PURPOSE);
        // Prepare data for the view

        $data = [
            [
                'index' => '1',
                'strong' => false,
                'title' => 'Տրամադրված վարկերի ընդհանուր,ծավալ,այդ թվում՝',
                'v1' => $contractStats['previous_given'],
                'v2' => $contractStats['current_given']
            ],
            [
                'index' => '1.1',
                'strong' => false,
                'title' => 'Երկարաձգված վարկերի ընդհանուր ծավալ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '1.2',
                'strong' => false,
                'title' => 'Ժամկետանց վարկերի ընդհանուր ծավալը',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '2',
                'strong' => false,
                'title' => 'Տրամադրված վարկերի դիմաց հաշվեգրված տոկոսներ,այդ թվում՝',
                'v1' => $interestPayments['interest_previous_month'],
                'v2' => $interestPayments['interest_current_month'],
            ],
            [
                'index' => '3',
                'strong' => false,
                'title' => 'Դրամարկղի մնացորդ',
                'v1' => $dealStatsPrevious['cashbox_sum'] ?? 0,
                'v2' => $dealStatsCurrent['cashbox_sum'] ?? 0,
            ],
            [
                'index' => '4',
                'strong' => false,
                'title' => 'Բանկային հաշիվներում դրամական միջոցների գումար',
                'v1' => $dealStatsPrevious['bank_cashbox_sum'] ?? 0,
                'v2' => $dealStatsCurrent['bank_cashbox_sum'] ?? 0
            ],
            [
                'index' => '5',
                'strong' => false,
                'title' => 'Անշարժ գույք',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '6',
                'strong' => false,
                'title' => 'Այլ հիմնական միջոցներ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '7',
                'strong' => false,
                'title' => 'Այլ ակտիվներ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '8',
                'strong' => false,
                'title' => 'Վարկային պայմանագրերի ընդհանուր թիվը՝',
                'v1' => $contractStats['previous_contract_count'],
                'v2' => $contractStats['current_contract_count']
            ],
            [
                'index' => '9',
                'strong' => false,
                'title' => 'Ներգրավված դրամական միջոցների ընդհանուր գումար',
                'v1' => '=SUM(G24:G26)',
                'v2' => '=SUM(H24:H26)'
            ],
            [
                'index' => '9.1',
                'strong' => false,
                'title' => 'Բանկերից/վարկային կազմակերպություններից/ ներգրավված միջոցներ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '9.2',
                'strong' => false,
                'title' => 'Մանակիցներից ներգրավված միջոցներ',
                'v1' => $ndmPrevious ?? 0,
                'v2' => $ndmCurrent ?? 0
            ],
            [
                'index' => '9.3',
                'strong' => false,
                'title' => 'Այլ կազմակերպոիթյուններից ներգրավված միջոցներ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '10',
                'strong' => false,
                'title' => 'Ներգրավված դրամական միջոցների դիմաց հաշվեգրված տոկոսներ՝',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '11',
                'strong' => false,
                'title' => 'Այլ պարտավորություններ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '12',
                'strong' => false,
                'title' => 'Սեփական կապիտալ, այդ թվում՝',
                'v1' => '=SUM(G30:G33)',
                'v2' => '=SUM(H30:H33)'
            ],
            [
                'index' => '12.1',
                'strong' => false,
                'title' => 'Կանոնադրական կապիտալ',
                'v1' => '10000',
                'v2' => '10000'
            ],
            [
                'index' => '12.2',
                'strong' => false,
                'title' => 'Շահույթ/վնաս',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '12․3',
                'strong' => false,
                'title' => 'Պահուստային կապիտալ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '12.4',
                'strong' => false,
                'title' => 'Սեփական կապիտալի այլ տարրեր',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '13',
                'strong' => false,
                'title' => 'Գրավ ընդունված առարկանների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G35:G39)',
                'v2' => '=SUM(H35:H39)'
            ],
            [
                'index' => '13.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => $gold_data['previous_estimated'],
                'v2' => $gold_data['current_estimated']
            ],
            [
                'index' => '13.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => $car_data['previous_estimated'],
                'v2' => $car_data['current_estimated']
            ],
            [
                'index' => '13.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => $electronics_data['previous_estimated'],
                'v2' => $electronics_data['current_estimated']
            ],
            [
                'index' => '13.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '14',
                'strong' => false,
                'title' => 'Ի պահ ընդունված գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G40:G43)',
                'v2' => '=SUM(H40:H43)'
            ],
            [
                'index' => '14.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '14.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '14.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '14.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '15',
                'strong' => false,
                'title' => 'Իրացման ենթակա գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G45:G48)',
                'v2' => '=SUM(H45:H48)'
            ],
            [
                'index' => '15.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => $gold_data['previous_taken'],
                'v2' => $gold_data['current_taken']
            ],
            [
                'index' => '15.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => $car_data['previous_taken'],
                'v2' => $car_data['current_taken']
            ],
            [
                'index' => '15.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => $electronics_data['previous_taken'],
                'v2' => $electronics_data['current_taken']
            ],
            [
                'index' => '15.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '16',
                'strong' => false,
                'title' => 'Իրացման ենթակա ի պահ վերցված գույք ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G50:G53)',
                'v2' => '=SUM(H50:H53)'
            ],
            [
                'index' => '16.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '16.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '16.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '16.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '0',
                'v2' => '0'
            ],
        ];
        return view('excel.monthly_sheet1', [
            'company_name' => '«Դայմոնդ Կրեդիտ» ՍՊԸ',
            'data' => $data,
            'date' => $lastDayOfMonth,
            'date_given' => Carbon::now()->format('d/m/Y'),
            'representative' => $pawnshop->representative
        ]);
    }
//    public function view1(): View
//    {
//        $array = [];
//        $month = $this->month;
//        $year = $this->year;
//        $days = Carbon::createFromFormat('Y',$year)->month($month)->daysInMonth;
//        $lastDayOfMonth = Carbon::create($year,$month,1)->endOfMonth()->format('d/m/Y');
//        $pawnshop = Pawnshop::where('id',$this->pawnshop_id)->first();
//        $lastDay_of_current_date = Carbon::createFromDate($year, $month)->endOfMonth()->toDateString();
//        $lastDay_of_previous_date = Carbon::createFromDate($year,$month)->subMonthNoOverflow()->endOfMonth()->toDateString();
//        $current_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_current_date);
//        $previous_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_previous_date);
//        $current_contracts = Contract::where('pawnshop_id', $this->pawnshop_id)
//            ->whereDate('date', '<=', $current_date)
//            ->where(function ($query) use ($current_date) {
//                $query->where('status', 'initial')
//                    ->orWhere(function ($query1) use ($current_date) {
//                        $query1->whereIn('status', ['completed', 'executed'])
//                            ->whereNotNull('deleted_at')
//                            ->whereDate('closed_at','>',$current_date);
//                    });
//            });
//        $previous_contracts = Contract::where('pawnshop_id', $this->pawnshop_id)
//            ->whereDate('date', '<=', $previous_date)
//            ->where(function ($query) use ($previous_date) {
//                $query->where('status', 'initial')
//                    ->orWhere(function ($query1) use ($previous_date) {
//                        $query1->whereIn('status', ['completed', 'executed'])
//                            ->whereNotNull('deleted_at')
//                            ->whereDate('closed_at','>',$previous_date);
//                    });
//            });
//        $current_contract_count = $current_contracts->count();
//        $previous_contract_count = $previous_contracts->count();
//        $current_worth = $current_contracts->sum('estimated_amount');
//        $current_given = $current_contracts->sum('provided_amount');
//        $previous_worth = $previous_contracts->sum('estimated_amount');
//        $previous_given = $previous_contracts->sum('provided_amount');
//        $contract_ids = $current_contracts->get()->pluck('id');
//        $current_gold_estimated = $current_contracts->where('category_id', 1)->sum('estimated_amount');
//        $current_electronics_estimated = $current_contracts->where('category_id', 2)->sum('estimated_amount');
//        $current_car_estimated = $current_contracts->where('category_id', 3)->sum('estimated_amount');
//        $previous_gold_estimated = $previous_contracts->where('category_id', 1)->sum('estimated_amount');
//        $previous_electronics_estimated = $previous_contracts->where('category_id', 2)->sum('estimated_amount');
//        $previous_car_estimated = $previous_contracts->where('category_id', 3)->sum('estimated_amount');
//
//        $current_taken_gold_estimated = $current_contracts->where('status',Contract::STATUS_TAKEN)->where('category_id', 1)->sum('estimated_amount');
//        $current_taken_electronics_estimated = $current_contracts->where('status',Contract::STATUS_TAKEN)->where('category_id', 2)->sum('estimated_amount');
//        $current_taken_car_estimated = $current_contracts->where('status',Contract::STATUS_TAKEN)->where('category_id', 3)->sum('estimated_amount');
//        $previous_taken_gold_estimated = $previous_contracts->where('status',Contract::STATUS_TAKEN)->where('category_id', 1)->sum('estimated_amount');
//        $previous_taken_electronics_estimated = $previous_contracts->where('status',Contract::STATUS_TAKEN)->where('category_id', 2)->sum('estimated_amount');
//        $previous_taken_car_estimated = $previous_contracts->where('status',Contract::STATUS_TAKEN)->where('category_id', 3)->sum('estimated_amount');
//
//
//
//
//        $interest_amount_current_month = Payment::where('type', 'regular')
//            ->whereIn('contract_id', $contract_ids)
//            ->where('date', '<=', $current_date)
//            ->sum('paid');
//        $interest_amount_previous_month = Payment::where('type', 'regular')
//            ->whereIn('contract_id', $contract_ids)
//            ->where('date', '<=', $previous_date)
//            ->sum('paid');
//        $partial_payments_amount =
//            Payment::where('type','partial')->whereIn('contract_id',$contract_ids)->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$current_date])->sum('amount');
//        $current_given -= $partial_payments_amount;
//        $current_cashbox_sum = 0;
//        $current_insurance = 0;
//        $current_funds = 0;
//        $current_bank_cashbox_sum = 0;
//
//        $previous_cashbox_sum = 0;
//        $previous_insurance = 0;
//        $previous_funds = 0;
//        $previous_bank_cashbox_sum = 0;
//        $current_deal =
//            Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$current_date])
//            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")
//            ->orderBy('id','DESC')->first();
//        $previous_deal = Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$previous_date])
//            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")
//            ->orderBy('id','DESC')->first();
//        if($current_deal){
//            $current_cashbox_sum = $current_deal->cashbox + $current_deal->bank_cashbox;
//            $current_insurance = $current_deal->insurance;
//            $current_funds = $current_deal->funds;
//            $current_bank_cashbox_sum = $current_deal->bank_cashbox;
//        }else{
//            $current_cashbox_sum = $pawnshop->cashbox + $pawnshop->bank_cashbox;
//            $current_insurance = $pawnshop->insurance;
//            $current_funds = $pawnshop->funds;
//            $current_bank_cashbox_sum = $pawnshop->bank_cashbox;
//        }
//        if ($previous_deal) {
//            $previous_cashbox_sum = $previous_deal->cashbox + $previous_deal->bank_cashbox;
//            $previous_insurance = $previous_deal->insurance;
//            $previous_funds = $previous_deal->funds;
//            $previous_bank_cashbox_sum = $previous_deal->bank_cashbox;
//
//        } else {
//            $previous_cashbox_sum = $pawnshop->cashbox + $pawnshop->bank_cashbox;
//            $previous_insurance = $pawnshop->insurance;
//            $previous_funds = $pawnshop->funds;
//            $previous_bank_cashbox_sum = $pawnshop->bank_cashbox;
//        }
//        $current_ndm = Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$current_date])
//            ->where('purpose', Order::NDM_PURPOSE)
//            ->selectRaw("SUM(CASE WHEN type = 'in' THEN amount WHEN type = 'cost_out' THEN -amount ELSE 0 END) as total")
//            ->value('total');
//
//        $previous_ndm = Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$previous_date])
//            ->where('purpose', Order::NDM_PURPOSE)
//            ->selectRaw("SUM(CASE WHEN type = 'in' THEN amount WHEN type = 'cost_out' THEN -amount ELSE 0 END) as total")
//            ->value('total');
//
//        $data = [
//            [
//                'index' => '1',
//                'strong' => false,
//                'title' => 'Տրամադրված վարկերի ընդհանուր,ծավալ,այդ թվում՝',
//                'v1' => $previous_given,
//                'v2' => $current_given
//            ],
//            [
//                'index' => '1.1',
//                'strong' => false,
//                'title' => 'Երկարաձգված վարկերի ընդհանուր ծավալ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '1.2',
//                'strong' => false,
//                'title' => 'Ժամկետանց վարկերի ընդհանուր ծավալը',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '2',
//                'strong' => false,
//                'title' => 'Տրամադրված վարկերի դիմաց հաշվեգրված տոկոսներ,այդ թվում՝',
//                'v1' => $interest_amount_previous_month,
//                'v2' => $interest_amount_current_month,
//            ],
//            [
//                'index' => '3',
//                'strong' => false,
//                'title' => 'Դրամարկղի մնացորդ',
//                'v1' => $previous_cashbox_sum,
//                'v2' => $current_cashbox_sum,
//            ],
//            [
//                'index' => '4',
//                'strong' => false,
//                'title' => 'Բանկային հաշիվներում դրամական միջոցների գումար',
//                'v1' => $previous_bank_cashbox_sum,
//                'v2' => $current_bank_cashbox_sum
//            ],
//            [
//                'index' => '5',
//                'strong' => false,
//                'title' => 'Անշարժ գույք',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '6',
//                'strong' => false,
//                'title' => 'Այլ հիմնական միջոցներ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '7',
//                'strong' => false,
//                'title' => 'Այլ ակտիվներ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '8',
//                'strong' => false,
//                'title' => 'Վարկային պայմանագրերի ընդհանուր թիվը՝',
//                'v1' => $previous_contract_count,
//                'v2' => $current_contract_count
//            ],
//            [
//                'index' => '9',
//                'strong' => false,
//                'title' => 'Ներգրավված դրամական միջոցների ընդհանուր գումար',
//                'v1' => '=SUM(G24:G26)',
//                'v2' => '=SUM(H24:H26)'
//            ],
//            [
//                'index' => '9.1',
//                'strong' => false,
//                'title' => 'Բանկերից/վարկային կազմակերպություններից/ ներգրավված միջոցներ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '9.2',
//                'strong' => false,
//                'title' => 'Մանակիցներից ներգրավված միջոցներ',
//                'v1' => $previous_ndm,
//                'v2' => $current_ndm
//            ],
//            [
//                'index' => '9.3',
//                'strong' => false,
//                'title' => 'Այլ կազմակերպոիթյուններից ներգրավված միջոցներ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '10',
//                'strong' => false,
//                'title' => 'Ներգրավված դրամական միջոցների դիմաց հաշվեգրված տոկոսներ՝',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '11',
//                'strong' => false,
//                'title' => 'Այլ պարտավորություններ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '12',
//                'strong' => false,
//                'title' => 'Սեփական կապիտալ, այդ թվում՝',
//                'v1' => '=SUM(G30:G33)',
//                'v2' => '=SUM(H30:H33)'
//            ],
//            [
//                'index' => '12.1',
//                'strong' => false,
//                'title' => 'Կանոնադրական կապիտալ',
//                'v1' => '10000',
//                'v2' => '10000'
//            ],
//            [
//                'index' => '12.2',
//                'strong' => false,
//                'title' => 'Շահույթ/վնաս',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '12․3',
//                'strong' => false,
//                'title' => 'Պահուստային կապիտալ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '12.4',
//                'strong' => false,
//                'title' => 'Սեփական կապիտալի այլ տարրեր',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '13',
//                'strong' => false,
//                'title' => 'Գրավ ընդունված առարկանների ընդհանուր արժեքը, այդ թվում՝',
//                'v1' => '=SUM(G35:G39)',
//                'v2' => '=SUM(H35:H39)'
//            ],
//            [
//                'index' => '13.1',
//                'strong' => false,
//                'title' => 'ոսկերչական իրեր',
//                'v1' => $previous_gold_estimated,
//                'v2' => $current_gold_estimated
//            ],
//            [
//                'index' => '13.2',
//                'strong' => false,
//                'title' => 'փոխադրամիջոցներ',
//                'v1' => $previous_car_estimated,
//                'v2' => $current_car_estimated
//            ],
//            [
//                'index' => '13.3',
//                'strong' => false,
//                'title' => 'կենցաղային տեխնիկա',
//                'v1' => $previous_electronics_estimated,
//                'v2' => $current_electronics_estimated
//            ],
//            [
//                'index' => '13.4',
//                'strong' => false,
//                'title' => 'այլ շարժական գույք',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '14',
//                'strong' => false,
//                'title' => 'Ի պահ ընդունված գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
//                'v1' => '=SUM(G40:G43)',
//                'v2' => '=SUM(H40:H43)'
//            ],
//            [
//                'index' => '14.1',
//                'strong' => false,
//                'title' => 'ոսկերչական իրեր',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '14.2',
//                'strong' => false,
//                'title' => 'փոխադրամիջոցներ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '14.3',
//                'strong' => false,
//                'title' => 'կենցաղային տեխնիկա',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '14.4',
//                'strong' => false,
//                'title' => 'այլ շարժական գույք',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '15',
//                'strong' => false,
//                'title' => 'Իրացման ենթակա գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
//                'v1' => '=SUM(G45:G48)',
//                'v2' => '=SUM(H45:H48)'
//            ],
//            [
//                'index' => '15.1',
//                'strong' => false,
//                'title' => 'ոսկերչական իրեր',
//                'v1' => $previous_taken_gold_estimated,
//                'v2' => $current_taken_gold_estimated
//            ],
//            [
//                'index' => '15.2',
//                'strong' => false,
//                'title' => 'փոխադրամիջոցներ',
//                'v1' => $previous_taken_car_estimated,
//                'v2' => $current_taken_car_estimated
//            ],
//            [
//                'index' => '15.3',
//                'strong' => false,
//                'title' => 'կենցաղային տեխնիկա',
//                'v1' => $previous_taken_electronics_estimated,
//                'v2' => $current_taken_electronics_estimated
//            ],
//            [
//                'index' => '15.4',
//                'strong' => false,
//                'title' => 'այլ շարժական գույք',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '16',
//                'strong' => false,
//                'title' => 'Իրացման ենթակա ի պահ վերցված գույք ընդհանուր արժեքը, այդ թվում՝',
//                'v1' => '=SUM(G50:G53)',
//                'v2' => '=SUM(H50:H53)'
//            ],
//            [
//                'index' => '16.1',
//                'strong' => false,
//                'title' => 'ոսկերչական իրեր',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '16.2',
//                'strong' => false,
//                'title' => 'փոխադրամիջոցներ',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '16.3',
//                'strong' => false,
//                'title' => 'կենցաղային տեխնիկա',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//            [
//                'index' => '16.4',
//                'strong' => false,
//                'title' => 'այլ շարժական գույք',
//                'v1' => '0',
//                'v2' => '0'
//            ],
//        ];
//        return view('excel.monthly_sheet1',[
//            'company_name' => '«Դայմոնդ Կրեդիտ» ՍՊԸ',
//            'data' => $data
//        ]);
//    }
    public function columnWidths(): array
    {
        return [
            'A' => 10,
            'B' => 20,
            'C' => 23,
            'D' => 23,
            'E' => 23,
            'F' => 23,
            'G' => 23,
            'H' => 23,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            'C14:H53' => [
                'numberFormat' => [
                    'formatCode' => '#,##0',
                ],
            ],
            'B12:H53' => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DASHED,
                    ],
                ],
            ],
            // Apply border style with specific width
            'B12:H12' => [
                'borders' => [
                    'top' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B12:B53' => [
                'borders' => [
                    'left' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B53:H53' => [
                'borders' => [
                    'bottom' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'H12:H53' => [
                'borders' => [
                    'right' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B13:H53' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                ],
            ],
        ];
    }
    public static function beforeSheet(BeforeSheet $event)
    {
        $event->sheet->getDelegate()->getParent()->getDefaultStyle()->applyFromArray([
            'font' => [
                'name' => 'Times Armenian',
                'size' => 11,
            ],
        ]);
        $event->sheet->getDelegate()->getRowDimension(3)->setRowHeight(100);
        $event->sheet->getDelegate()->getRowDimension(12)->setRowHeight(90);
        $event->sheet->getStyle('3')->getAlignment()->setWrapText(true);
        $event->sheet->getStyle('12')->getAlignment()->setWrapText(true);
        $event->sheet->getStyle('H1')->getAlignment()->setHorizontal('right');
        $event->sheet->getStyle('H2')->getAlignment()->setHorizontal('right');
        $event->sheet->getStyle('E6')->getAlignment()->setHorizontal('right');
        $event->sheet->getStyle('F9')->getAlignment()->setHorizontal('right');
        $event->sheet->getStyle('F10')->getAlignment()->setHorizontal('center');
        $event->sheet->getStyle('C3')->getAlignment()->setHorizontal('center')->setVertical('center');
        $event->sheet->getStyle('12')->getAlignment()->setHorizontal('center')->setVertical('center');
        $event->sheet->getStyle('C3')->getFont()->setSize(12);
    }
}
