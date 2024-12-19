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

class MonthlySheet2Export implements FromView, WithEvents, WithColumnWidths, ShouldAutoSize, WithStyles
{
    use RegistersEventListeners;

   public function view(): View
   {
       $data = [
           [
               'index'  => 1,
               'strong' => false,
               'title'  => 'Մեկ վարկային պայմանագրով տրամադրված վարկի առավելագույն ծավալ',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 2,
               'strong' => false,
               'title'  => 'Մեկ վարկային պայմանագրով տրամադրված վարկի նվազագույն ծավալ',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 3,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի առավելագույն տարեկան տոկոսադրույք',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 4,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի նվազագույն տարեկան տոկոսադրույք',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 5,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի առավելագույն ժամկետ',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 6,
               'strong' => false,
               'title'  => 'Տրամադրված վարկերի նվազագույն ժամկետ',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 7,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների առավելագույն տարեկան տոկոսադրույք',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 8,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների նվազագույն տարեկան տոկոսադրույք',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 9,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների առավելագույն ժամկետ',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index'  => 10,
               'strong' => false,
               'title'  => 'Ներգրավված դրամական միջոցների նվազագույն ժամկետ',
               'v1'     => '',
               'v2'     => ''
           ],
           [
               'index' => '10',
               'strong' => false,
               'title' => 'Գրավի առարկաների իրացումից ստացված հասույք,այդ թվում՝',
               'v1' => '',
               'v2' => ''
           ],
           [
               'index' => '10.1',
               'strong' => false,
               'title' => 'ոսկերչական իրեր',
               'v1' => '',
               'v2' => ''
           ],
           [
               'index' => '10.2',
               'strong' => false,
               'title' => 'փոխադրամիջոցներ',
               'v1' => '',
               'v2' => ''
           ],
           [
               'index' => '10.3',
               'strong' => false,
               'title' => 'կենցաղային տեխնիկա',
               'v1' => '',
               'v2' => ''
           ],
           [
               'index' => '10.4',
               'strong' => false,
               'title' => 'այլ անշարժ գույք',
               'v1' => '',
               'v2' => ''
           ],
           [
               'index' => '11',
               'strong' => false,
               'title' => 'Գրավի առարկաների իրացումից ստացված հասույթի-վարկերի(ներառյալ տոկոսագումարները-
                            տույժերը) մարմանն ուղղված գումարների դրական կամ բացասական տարբերությունը',
               'v1' => '',
               'v2' => ''
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
