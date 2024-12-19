<?php

namespace App\Exports;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use \Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\BeforeSheet;
use \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
class MonthlySheet3Export implements FromView, WithEvents, WithColumnWidths, ShouldAutoSize, WithStyles
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
    }
    public function view(): View
    {
        $array = [];
        $month = $this->month;
        $year = $this->year;
        $days = Carbon::createFromFormat('Y',$year)->month($month)->daysInMonth;
        $lastDayOfMonth = Carbon::create($year,$month,1)->endOfMonth()->format('d/m/Y');
        $pawnshop = Pawnshop::where('id',$this->pawnshop_id)->first();
        for($i = 1; $i <= $days; $i++){
            $day = $i < 10 ? '0'.$i : $i;
//            $date = Carbon::parse($year.'-'.$month.'-'.$day);
            $date = Carbon::createFromFormat('d.m.Y', $day . '.' . $month . '.' . $year);

            $contracts_query = Contract::where('pawnshop_id', $this->pawnshop_id)
                ->whereDate('created_at', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->where('status', 'initial')
                        ->orWhere(function ($query1) use ($date) {
                            $query1->whereIn('status', ['completed', 'executed'])
                                ->whereNotNull('deleted_at')
                                ->whereDate('closed_at','>',$date);
                        });
                });

            $worth = $contracts_query->sum('estimated_amount');
            $given = $contracts_query->sum('provided_amount');
            $contract_ids = $contracts_query->get()->pluck('id');
            $partial_payments_amount = Payment::where('type','partial')->whereIn('contract_id',$contract_ids)->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$date])->sum('amount');
            $given -= $partial_payments_amount;
            $cashbox_sum = 0;
            $insurance = 0;
            $funds = 0;
            $deal = Deal::whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$date])->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")->orderBy('id','DESC')->first();
            if($deal){
                $cashbox_sum = $deal->cashbox + $deal->bank_cashbox;
                $insurance = $deal->insurance;
                $funds = $deal->funds;
            }else{
                $cashbox_sum = $pawnshop->cashbox + $pawnshop->bank_cashbox;
                $insurance = $pawnshop->insurance;
                $funds = $pawnshop->funds;
            }
            $array[$i] = [
                'worth' => intval(round($worth/1000)),
                'given' => intval(round($given/1000)),
                'insurance' => intval(round($insurance/1000)),
                'funds' => intval(round($funds/1000)),
                'cashbox_sum' => intval(round($cashbox_sum/1000))
            ];
        }
        return view('excel.monthly_sheet3',[
            'company_name' => '«Դայմոնդ Կրեդիտ» ՍՊԸ',
            'data' => $array,
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
            'C14:H44' => [
                'numberFormat' => [
                    'formatCode' => '#,##0',
                ],
            ],
            'B12:H44' => [
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
            'B12:B44' => [
                'borders' => [
                    'left' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B44:H44' => [
                'borders' => [
                    'bottom' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'H12:H44' => [
                'borders' => [
                    'right' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B13:H52' => [
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
