<?php

namespace App\Exports;


use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class QuarterSheet1Export implements FromView, WithStyles, WithEvents,WithColumnWidths
{
    use RegistersEventListeners;
    public function view(): View
    {
        $data = [
            [
                'index' => '1',
                'strong' => true,
                'title' => 'Ընդհանուր ակտիվներ',
                'v1' => '=G15+G18+G20+G21+G22',
                'v2' => '=H15+H18+H20+H21+H22'
            ],
            [
                'index' => '2',
                'strong' => true,
                'title' => 'Տրամադրված վարկերի ընդհանուր,ծավալ,այդ թվում՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '2.1',
                'strong' => false,
                'title' => 'Երկարաձգված վարկերի ընդհանուր ծավալ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '2.2',
                'strong' => false,
                'title' => 'Ժամկետանց վարկերի ընդհանուր ծավալը',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '3',
                'strong' => true,
                'title' => 'Տրամադրված վարկերի դիմաց հաշվեգրված տոկոսներ,այդ թվում՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '3.1',
                'strong' => false,
                'title' => 'Հաշվետու ժամանակաշրջանում տրամադրված վարկերի դիմաց հաշվեգրված տոկոսներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '4',
                'strong' => true,
                'title' => 'Դրամարկղի մնացորդ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '5',
                'strong' => true,
                'title' => 'Բանկային հաշիվներում դրամական միջոցների գումար',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '6',
                'strong' => true,
                'title' => 'Այլ ակտիվներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '7',
                'strong' => true,
                'title' => 'Վարկային պայմանագրերի ընդհանուր թիվը, այդ թվում՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '7.1',
                'strong' => false,
                'title' => 'Երկարաձգված վարկային պայմանագրերի թիվը',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '7.2',
                'strong' => false,
                'title' => 'Ժամկետանց վարկային պայմանագրերի թիվը',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '8',
                'strong' => true,
                'title' => 'Ընդհանուր պարտավրություններ',
                'v1' => '=G27+G31+G33',
                'v2' => '=H27+H31+H33'
            ],
            [
                'index' => '9',
                'strong' => true,
                'title' => 'Ներգրավված դրամական միջոցների ընդհանուր գումար, այդ թվում',
                'v1' => '=SUM(G28:G30)',
                'v2' => '=SUM(H28:H30)'
            ],
            [
                'index' => '9.1',
                'strong' => false,
                'title' => 'Բանկերից/վարկային կազմակերպություններից/ ներգրավված միջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '9.2',
                'strong' => false,
                'title' => 'Մանակիցներից ներգրավված միջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '9.3',
                'strong' => false,
                'title' => 'Այլ կազմակերպոիթյուններից ներգրավված միջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '10',
                'strong' => true,
                'title' => 'Ներգրավված դրամական միջոցների դիմաց հաշվեգրված տոկոսները, այդ թվում՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '10.1',
                'strong' => false,
                'title' => 'Հաշվետու ժամանակաշրջանում ներգրավված միջոցների դիմաց հաշվեգրված տոկոսներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '11',
                'strong' => true,
                'title' => 'Այլ պարտավորություններ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12',
                'strong' => true,
                'title' => 'Սեփական կապիտալ, այդ թվում՝',
                'v1' => '=G35+G36+G38+G39',
                'v2' => '=H35+H36+H38+H39'
            ],
            [
                'index' => '12.1',
                'strong' => false,
                'title' => 'Կանոնադրական կապիտալ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12.2',
                'strong' => false,
                'title' => 'Շահույթ/վնաս, այդ թվում՝',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12.2.1',
                'strong' => false,
                'title' => 'Հաշվետու ժամանակարջանում ստացած շահույթ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12.3',
                'strong' => false,
                'title' => 'Գլխավոր պահուստ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '12.4',
                'strong' => false,
                'title' => 'Սեփական կապիտալի այլ տարրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '13',
                'strong' => true,
                'title' => 'Գրավ ընդունված առարկանների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G41:G44)',
                'v2' => '=SUM(H41:H44)'
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
                'strong' => true,
                'title' => 'Ի պահ ընդունված գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G46:G49)',
                'v2' => '=SUM(H46:H49)'
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
            [
                'index' => '15',
                'strong' => true,
                'title' => 'Իրացման ենթակա գրավի առարկաների ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G51:G54)',
                'v2' => '=SUM(H51:H54)'
            ],
            [
                'index' => '15.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '15.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '15.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '15.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '16',
                'strong' => true,
                'title' => 'Իրացման ենթակա ի պահ վերցված գույք ընդհանուր արժեքը, այդ թվում՝',
                'v1' => '=SUM(G56:G59)',
                'v2' => '=SUM(H56:H59)'
            ],
            [
                'index' => '16.1',
                'strong' => false,
                'title' => 'ոսկերչական իրեր',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '16.2',
                'strong' => false,
                'title' => 'փոխադրամիջոցներ',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '16.3',
                'strong' => false,
                'title' => 'կենցաղային տեխնիկա',
                'v1' => '',
                'v2' => ''
            ],
            [
                'index' => '16.4',
                'strong' => false,
                'title' => 'այլ շարժական գույք',
                'v1' => '',
                'v2' => ''
            ],
        ];
        return view('excel.quarter_sheet1',[
            'company_name' => '«Դայմոնդ Կրեդիտ» ՍՊԸ',
            'data' => $data
            ]);
    }
    public function columnWidths(): array
    {
        return [
            'A' => 8,
            'B' => 10,
            'C' => 20,
            'D' => 15,
            'E' => 15,
            'F' => 26,
            'G' => 17,
            'H' => 17,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return array_merge_recursive(config('constants.sheet1_styles'),[
            'B1:B4' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                ],
            ],
            'C8:D9' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                ],
            ],
            'B5:B6' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                ],
            ],

            'B14:H59' => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DASHED,
                    ],
                ],
            ],
            'B12:H13' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                ],
            ],

            'C14:C59' => [
                'borders' => [
                    'left' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                    'right' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                ],
            ],
            'B12:H12' => [
                'borders' => [
                    'top' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B12:B59' => [
                'borders' => [
                    'left' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'B59:H59' => [
                'borders' => [
                    'bottom' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],
            'H12:H59' => [
                'borders' => [
                    'right' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    ],
                ],
            ],

            'B14:C59' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                ],
            ],
        ]);
    }
    public function beforeSheet(BeforeSheet $event){
        $event->sheet->getDelegate()->getParent()->getDefaultStyle()->applyFromArray([
            'font' => [
                'name' => 'Times Armenian',
                'size' => 11,
            ],
        ]);
        $event->sheet->getDelegate()->getRowDimension(2)->setRowHeight(30);
        $event->sheet->getDelegate()->getRowDimension(6)->setRowHeight(50);
        $event->sheet->getDelegate()->getRowDimension(12)->setRowHeight(70);
        $event->sheet->getStyle('12')->getAlignment()->setWrapText(true);
        $event->sheet->getStyle('2')->getAlignment()->setWrapText(true);
        $event->sheet->getStyle('6')->getAlignment()->setWrapText(true);
        $event->sheet->getStyle('2')->getFont()->setSize(10);

    }
}
