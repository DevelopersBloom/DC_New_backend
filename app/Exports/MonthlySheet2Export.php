<?php

namespace App\Exports;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Pawnshop;
use App\Services\ExportSheet2Service;
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

class MonthlySheet2Export implements FromView, WithEvents, WithColumnWidths, ShouldAutoSize, WithStyles
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
        $this->service = new ExportSheet2Service();

    }
   public function view1(): View
   {
       $array = [];
       $month = $this->month;
       $year = $this->year;
       $days = Carbon::createFromFormat('Y',$year)->month($month)->daysInMonth;
       $lastDayOfMonth = Carbon::create($year,$month,1)->endOfMonth()->format('d/m/Y');
       $pawnshop = Pawnshop::where('id',$this->pawnshop_id)->first();
       $lastDay_of_current_date = Carbon::createFromDate($year, $month)->endOfMonth()->toDateString();
       $lastDay_of_previous_date = Carbon::createFromDate($year,$month)->subMonthNoOverflow()->endOfMonth()->toDateString();
       $current_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_current_date);
       $previous_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_previous_date);
       $firstDayOfMonth = Carbon::create($year, $month, 1)->startOfMonth()->format('Y-m-d');
       $firstDayOfPreviousMonth = Carbon::create($year,$month,1)->subMonth()->startOfMonth()->format('Y-m-d');

       $current_contracts = Contract::where('pawnshop_id', $this->pawnshop_id)
           ->whereDate('date', '<=', $current_date)
           ->whereDate('date','>=',$firstDayOfMonth)
           ->where(function ($query) use ($current_date) {
               $query->where('status', 'initial')
                   ->orWhere(function ($query1) use ($current_date) {
                       $query1->whereIn('status', ['completed', 'executed'])
                           ->whereNotNull('deleted_at')
                           ->whereDate('closed_at','>',$current_date);
                   });
           });
       $previous_contracts = Contract::where('pawnshop_id', $this->pawnshop_id)
           ->whereDate('date', '<=', $previous_date)
           ->whereDate('date','>=',$firstDayOfPreviousMonth)
           ->where(function ($query) use ($previous_date) {
               $query->where('status', 'initial')
                   ->orWhere(function ($query1) use ($previous_date) {
                       $query1->whereIn('status', ['completed', 'executed'])
                           ->whereNotNull('deleted_at')
                           ->whereDate('closed_at','>',$previous_date);
                   });
           });
       $current_max_estimated_amount = $current_contracts->max('estimated_amount');
       $previous_max_estimated_amount = $previous_contracts->max('estimated_amount');
       $current_min_estimated_amount = $current_contracts->min('estimated_amount');
       $previous_min_estimated_amount = $previous_contracts->min('estimated_amount');

       $current_max_rate = $current_contracts->max('interest_rate');
       $previous_max_rate = $previous_contracts->max('interest_rate');
       $current_min_rate = $current_contracts->min('interest_rate');
       $previous_min_rate = $previous_contracts->min('interest_rate');

       $current_max_deadline = $current_contracts->max('deadline_days');
       $previous_max_deadline = $current_contracts->max('deadline_days');
       $current_min_deadline = $current_contracts->min('deadline_days');
       $previous_min_deadline = $current_contracts->min('deadline_days');

       $current_payment_month = Deal::where('purpose', Deal::TAKEN_PURPOSE)
           ->where('date', '<=', $current_date);
       $previous_payment_month = Deal::where('purpose', Deal::TAKEN_PURPOSE)
           ->where('date', '<=', $previous_date);
       $current_max_amount_gold = $current_payment_month->where('category_id', 1)->max('amount');
       $current_max_amount_electronics = $current_payment_month->where('category_id', 2)->max('amount');
       $current_max_amount_car = $current_payment_month->where('category_id', 3)->max('amount');

       $previous_max_amount_gold = $current_payment_month->where('category_id',1)->max('amount');
       $previous_max_amount_electronics = $current_payment_month->where('category_id',2)->max('amount');
       $previous_max_amount_car = $current_payment_month->where('category_id',3)->max('amount');

       $data = [
           [
               'index'  => 1,
               'strong' => false,
               'title'  => 'Մեկ վարկային պայմանագրով տրամադրված վարկի առավելագույն ծավալ',
               'v1'     => $previous_max_estimated_amount,
               'v2'     => $current_max_estimated_amount
           ],
           [
               'index'  => 2,
               'strong' => false,
               'title'  => 'Մեկ վարկային պայմանագրով տրամադրված վարկի նվազագույն ծավալ',
               'v1'     => $previous_min_estimated_amount,
               'v2'     => $current_min_estimated_amount
           ],
           [
               'index'  => 3,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի առավելագույն տարեկան տոկոսադրույք',
               'v1'     => $previous_max_rate,
               'v2'     => $current_max_rate
           ],
           [
               'index'  => 4,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի նվազագույն տարեկան տոկոսադրույք',
               'v1'     => $previous_min_rate,
               'v2'     => $current_min_rate
           ],
           [
               'index'  => 5,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի առավելագույն ժամկետ',
               'v1'     => $previous_max_deadline . ' օր',
               'v2'     => $current_max_deadline . ' օր'
           ],
           [
               'index'  => 6,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի նվազագույն ժամկետ',
               'v1'     => $previous_min_deadline . ' օր',
               'v2'     => $current_min_deadline . ' օր'
           ],
           [
               'index'  => 7,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների առավելագույն տարեկան տոկոսադրույք',
               'v1'     => '0',
               'v2'     => '0'
           ],
           [
               'index'  => 8,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների նվազագույն տարեկան տոկոսադրույք',
               'v1'     => '0',
               'v2'     => '0'
           ],
           [
               'index'  => 9,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների առավելագույն ժամկետ',
               'v1'     => 'Անժամկետ',
               'v2'     => 'Անժամկետ'
           ],
           [
               'index'  => 10,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների նվազագույն ժամկետ',
               'v1'     => 'Անժամկետ',
               'v2'     => 'Անժամկետ'
           ],
           [
               'index' => '10',
               'strong' => false,
               'title' => 'Գրավի առարկաների իրացումից ստացված հասույք,այդ թվում՝',
               'v1' => '=SUM(G24:G27)',
               'v2' => '=SUM(H24:H27)'
           ],
           [
               'index' => '10.1',
               'strong' => false,
               'title' => 'ոսկերչական իրեր',
               'v1' => $previous_max_amount_gold,
               'v2' => $current_max_amount_gold
           ],
           [
               'index' => '10.2',
               'strong' => false,
               'title' => 'փոխադրամիջոցներ',
               'v1' => $previous_max_amount_car,
               'v2' => $current_max_amount_car
           ],
           [
               'index' => '10.3',
               'strong' => false,
               'title' => 'կենցաղային տեխնիկա',
               'v1' => $previous_max_amount_electronics,
               'v2' => $current_max_amount_electronics
           ],
           [
               'index' => '10.4',
               'strong' => false,
               'title' => 'այլ անշարժ գույք',
               'v1' => '0',
               'v2' => '0'
           ],
           [
               'index' => '11',
               'strong' => false,
               'title' => 'Գրավի առարկաների իրացումից ստացված հասույթի-վարկերի(ներառյալ տոկոսագումարները-
                            տույժերը) մարմանն ուղղված գումարների դրական կամ բացասական տարբերությունը',
               'v1' => '?',
               'v2' => '?'
           ],
           [
               'index' => '12',
               'strong' => false,
               'title' => 'Տրամադրված վարկերի դիմաց փաստացի ստացված տոկոսները',
               'v1' => '',
               'v2' => ''
           ],
           [
               'index' => '13',
               'strong' => false,
               'title' => 'Ներգրավված դրամական միջոցների դիմաց փաստացի վճարված տոկոսները',
               'v1' => '',
               'v2' => ''
           ],
       ];

       return view('excel.monthly_sheet2',[
           'company_name' => '«Դայմոնդ Կրեդիտ» ՍՊԸ',
           'data' => $data
       ]);
   }
    public function view(): View
    {
        $array = [];
        $month = $this->month;
        $year = $this->year;
        $days = Carbon::createFromFormat('Y',$year)->month($month)->daysInMonth;
        $lastDayOfMonth = Carbon::create($year,$month,1)->endOfMonth()->format('d/m/Y');
        $pawnshop = Pawnshop::where('id',$this->pawnshop_id)->first();
        $lastDay_of_current_date = Carbon::createFromDate($year, $month)->endOfMonth()->toDateString();
        $lastDay_of_previous_date = Carbon::createFromDate($year,$month)->subMonthNoOverflow()->endOfMonth()->toDateString();
        $current_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_current_date);
        $previous_date = Carbon::createFromFormat('Y-m-d', $lastDay_of_previous_date);
        $firstDayOfMonth = Carbon::create($year, $month, 1)->startOfMonth()->format('Y-m-d');
        $firstDayOfPreviousMonth = Carbon::create($year,$month,1)->subMonth()->startOfMonth()->format('Y-m-d');

        $data = $this->service->getContractData($current_date,$previous_date,$firstDayOfMonth,$firstDayOfPreviousMonth,$this->pawnshop_id);

        $currentMaxAmounts = $this->service->getMaxAmountsByCategory($current_date,$firstDayOfMonth);
        $previousMaxAmounts = $this->service->getMaxAmountsByCategory($previous_date,$firstDayOfPreviousMonth);

        $data = [
            [
                'index'  => 1,
                'strong' => false,
                'title'  => 'Մեկ վարկային պայմանագրով տրամադրված վարկի առավելագույն ծավալ',
                'v1'     => $data['previousMaxEstimatedAmount'],
                'v2'     => $data['currentMaxEstimatedAmount']
            ],
            [
                'index'  => 2,
                'strong' => false,
                'title'  => 'Մեկ վարկային պայմանագրով տրամադրված վարկի նվազագույն ծավալ',
                'v1'     => $data['previousMinEstimatedAmount'],
                'v2'     => $data['currentMinEstimatedAmount']
            ],
            [
                'index'  => 3,
                'strong' => false,
                'title'  => 'Տրամադրված վարկերի առավելագույն տարեկան տոկոսադրույք',
                'v1'     => $data['previousMaxRate'],
                'v2'     => $data['currentMaxRate']
            ],
            [
                'index'  => 4,
                'strong' => false,
                'title'  => 'Տրամադրված վարկերի նվազագույն տարեկան տոկոսադրույք',
                'v1'     => $data['previousMinRate'],
                'v2'     => $data['currentMinRate']
            ],
            [
                'index'  => 5,
                'strong' => false,
                'title'  => 'Տրամադրված վարկերի առավելագույն ժամկետ',
                'v1'     => $data['previousMaxDeadline'] . ' օր',
                'v2'     => $data['currentMaxDeadline'] . ' օր'
            ],
            [
                'index'  => 6,
                'strong' => false,
                'title'  => 'Տրամադրված վարկերի նվազագույն ժամկետ',
                'v1'     => $data['previousMinDeadline'] . ' օր',
                'v2'     => $data['currentMinDeadline'] . ' օր'
            ],
            [
                'index'  => 7,
                'strong' => false,
                'title'  => 'Ներգրավված դրամական միջոցների առավելագույն տարեկան տոկոսադրույք',
                'v1'     => '0',
                'v2'     => '0'
            ],
            [
                'index'  => 8,
                'strong' => false,
                'title'  => 'Ներգրավված դրամական միջոցների նվազագույն տարեկան տոկոսադրույք',
                'v1'     => '0',
                'v2'     => '0'
            ],
            [
                'index'  => 9,
                'strong' => false,
                'title'  => 'Ներգրավված դրամական միջոցների առավելագույն ժամկետ',
                'v1'     => 'Անժամկետ',
                'v2'     => 'Անժամկետ'
            ],
            [
                'index'  => 10,
                'strong' => false,
                'title'  => 'Ներգրավված դրամական միջոցների նվազագույն ժամկետ',
                'v1'     => 'Անժամկետ',
                'v2'     => 'Անժամկետ'
            ],
            [
                'index' => '10',
                'strong' => false,
                'title' => 'Գրավի առարկաների իրացումից ստացված հասույք,այդ թվում՝',
                'v1' => '=SUM(G24:G27)',
                'v2' => '=SUM(H24:H27)'
            ],
            [
                'index' => '10.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => $previousMaxAmounts['gold'] ?? 0,
                'v2' => $currentMaxAmounts['gold'] ?? 0
            ],
            [
                'index' => '10.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => $previousMaxAmounts['car'] ?? 0,
                'v2' => $currentMaxAmounts['car'] ?? 0
            ],
            [
                'index' => '10.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => $previousMaxAmounts['electronics'],
                'v2' => $currentMaxAmounts['electronics']
            ],
            [
                'index' => '10.4',
                'strong' => false,
                'title' => 'այլ անշարժ գույք',
                'v1' => '0',
                'v2' => '0'
            ],
            [
                'index' => '11',
                'strong' => false,
                'title' => 'Գրավի առարկաների իրացումից ստացված հասույթի-վարկերի(ներառյալ տոկոսագումարները-
                            տույժերը) մարմանն ուղղված գումարների դրական կամ բացասական տարբերությունը',
                'v1' => '?',
                'v2' => '?'
            ],
            [
                'index' => '12',
                'strong' => false,
                'title' => 'Տրամադրված վարկերի դիմաց փաստացի ստացված տոկոսները',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '13',
                'strong' => false,
                'title' => 'Ներգրավված դրամական միջոցների դիմաց փաստացի վճարված տոկոսները',
                'v1' => '',
                'v2' => ''
            ],
        ];

        return view('excel.monthly_sheet2',[
            'company_name' => '«Դայմոնդ Կրեդիտ» ՍՊԸ',
            'data' => $data,
            'date' => $lastDayOfMonth,
            'date_given' => Carbon::now()->format('d/m/Y'),
            'representative' => $pawnshop->representative
        ]);
    }
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
           'C14:H30' => [
               'numberFormat' => [
                   'formatCode' => '#,##0',
               ],
           ],
           'B12:H30' => [
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
           'B12:B30' => [
               'borders' => [
                   'left' => [
                       'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                   ],
               ],
           ],
           'B30:H30' => [
               'borders' => [
                   'bottom' => [
                       'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                   ],
               ],
           ],
           'H12:H30' => [
               'borders' => [
                   'right' => [
                       'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                   ],
               ],
           ],
           'B13:H30' => [
               'alignment' => [
                   'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
               ],
           ],
       ];
   }

   public static function beforeSheet(BeforeSheet $event): void
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
