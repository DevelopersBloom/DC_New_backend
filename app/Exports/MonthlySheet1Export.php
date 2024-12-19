<?php

namespace App\Exports;

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
    public function view(): View
    {
        $data = [
            [
                'index' => '1',
                'strong' => false,
                'title' => 'Տրամադրված վարկերի ընդհանուր,ծավալ,այդ թվում՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '1.1',
                'strong' => false,
                'title' => 'Երկարաձգված վարկերի ընդհանուր ծավալ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '1.2',
                'strong' => false,
                'title' => 'Ժամկետանց վարկերի ընդհանուր ծավալը',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '2',
                'strong' => false,
                'title' => 'Տրամադրված վարկերի դիմաց հաշվեգրված տոկոսներ,այդ թվում՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '3',
                'strong' => false,
                'title' => 'Դրամարկղի մնացորդ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '4',
                'strong' => false,
                'title' => 'Բանկային հաշիվներում դրամական միջոցների գումար',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '5',
                'strong' => false,
                'title' => 'Այլ ակտիվներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '6',
                'strong' => false,
                'title' => 'Վարկային պայմանագրերի ընդհանուր թիվը՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '7',
                'strong' => false,
                'title' => 'Ներգրավված դրամական միջոցների ընդհանուր գումար',
                'v1' => '=SUM(G28:G30)',
                'v2' => '=SUM(H28:H30)'
            ],
            [
                'index' => '7.1',
                'strong' => false,
                'title' => 'Բանկերից/վարկային կազմակերպություններից/ ներգրավված միջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '7.2',
                'strong' => false,
                'title' => 'Մանակիցներից ներգրավված միջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '7.3',
                'strong' => false,
                'title' => 'Այլ կազմակերպոիթյուններից ներգրավված միջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '8',
                'strong' => false,
                'title' => 'Ներգրավված դրամական միջոցների դիմաց հաշվեգրված տոկոսներ՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '9',
                'strong' => false,
                'title' => 'Այլ պարտավորություններ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '10',
                'strong' => false,
                'title' => 'Սեփական կապիտալ, այդ թվում՝',
                'v1' => '=G35+G36+G38+G39',
                'v2' => '=H35+H36+H38+H39'
            ],
            [
                'index' => '10.1',
                'strong' => false,
                'title' => 'Կանոնադրական կապիտալ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '10.2',
                'strong' => false,
                'title' => 'Շահույթ/վնաս',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '10․3',
                'strong' => false,
                'title' => 'Պահուստային կապիտալ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '10.4',
                'strong' => false,
                'title' => 'Սեփական կապիտալի այլ տարրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '11',
                'strong' => false,
                'title' => 'Գրավ ընդունված առարկանների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G41:G44)',
                'v2' => '=SUM(H41:H44)'
            ],
            [
                'index' => '11.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '11.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '11.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '11.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12',
                'strong' => false,
                'title' => 'Ի պահ ընդունված գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G46:G49)',
                'v2' => '=SUM(H46:H49)'
            ],
            [
                'index' => '12.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '13',
                'strong' => false,
                'title' => 'Իրացման ենթակա գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G51:G54)',
                'v2' => '=SUM(H51:H54)'
            ],
            [
                'index' => '13.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '13.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '13.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '13.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '14',
                'strong' => false,
                'title' => 'Իրացման ենթակա ի պահ վերցված գույք ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G56:G59)',
                'v2' => '=SUM(H56:H52)'
            ],
            [
                'index' => '14.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '14.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '14.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '14.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '',
                'v2' => ''
            ],
        ];
        return view('excel.monthly_sheet1',[
            'company_name' => '«Դայմոնդ Կրեդիտ» ՍՊԸ',
            'data' => $data
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
            'C14:H51' => [
                'numberFormat' => [
                    'formatCode' => '#,##0',
                ],
            ],
            'B12:H51' => [
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
            'B12:B51' => [
                'borders' => [
                    'left' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B51:H51' => [
                'borders' => [
                    'bottom' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'H12:H51' => [
                'borders' => [
                    'right' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B13:H51' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                ],
            ],
        ];
    }
//    public function beforeSheet(BeforeSheet $event){
//        $event->sheet->getDelegate()->getParent()->getDefaultStyle()->applyFromArray([
//            'font' => [
//                'name' => 'Times Armenian',
//                'size' => 11,
//            ],
//        ]);
////        $event->sheet->getDelegate()->getRowDimension(2)->setRowHeight(30);
////        $event->sheet->getDelegate()->getRowDimension(6)->setRowHeight(50);
////        $event->sheet->getDelegate()->getRowDimension(12)->setRowHeight(70);
////        $event->sheet->getStyle('12')->getAlignment()->setWrapText(true);
////        $event->sheet->getStyle('2')->getAlignment()->setWrapText(true);
////        $event->sheet->getStyle('6')->getAlignment()->setWrapText(true);
////        $event->sheet->getStyle('2')->getFont()->setSize(10);
//
//    }
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
