<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Evaluator;
use App\Models\HistoryType;
use App\Models\Type;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
//        $types = [
//            [
//                'name' => 'gold',
//                'title' => 'Ոսկի',
//                'interest_rate' => 4,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//            [
//                'name' => 'phone',
//                'title' => 'Հեռախոս',
//                'interest_rate' => 3,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//            [
//                'name' => 'laptop',
//                'title' => 'Նոթբուք',
//                'interest_rate' => 4,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//            [
//                'name' => 'tablet',
//                'title' => 'Պլանշետ',
//                'interest_rate' => 4,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//            [
//                'name' => 'pc',
//                'title' => 'Համակարգիչ',
//                'interest_rate' => 4,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//            [
//                'name' => 'tv',
//                'title' => 'Հեռուստացույց',
//                'interest_rate' => 4,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//            [
//                'name' => 'car',
//                'title' => 'Ավտոմեքենա',
//                'interest_rate' => 4,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//            [
//                'name' => 'other',
//                'title' => 'Այլ',
//                'interest_rate' => 4,
//                'penalty' => 13,
//                'lump_rate' => 4,
//            ],
//        ];
//        foreach ($types as $type){
//            Category::create([
//                'name' => $type['name'],
//                'title' => $type['title'],
//                'interest_rate' => $type['interest_rate'],
//                'penalty' => $type['penalty'],
//                'lump_rate' => $type['lump_rate'],
//            ]);
//        }
        $history_types = [
            [
                'name' => 'opening',
                'title' => 'Պայմանագրի Բացում',
            ],
            [
                'name' => 'one_time_payment',
                'title' => 'Միանվագ Վճար',
            ],
            [
                'name' => 'one_time_payment_refund',
                'title' => 'Միանվագ Վճարի ետվերադաևձ',
            ],
            [
                'name' => 'mother_payment',
                'title' => 'ՄԳ տրամադրում'
            ],
            [
                'name' => 'edit',
                'title' => 'Պայմանագրի Փոփոխությոն',
            ],
            [
                'name' => 'extending',
                'title' => 'Պայմանագրի Երկարացում',
            ],
            [
                'name' => 'discount',
                'title' => 'Զեղչի կիրառում',
            ],
            [
                'name' => 'regular_payment',
                'title' => 'Հերթական Վճարում',
            ],
            [
                'name' => 'partial_payment',
                'title' => 'Մասնակի Վճարում',
            ],
            [
                'name' => 'full_payment',
                'title' => 'Ամբողջական Վճարում',
            ],
            [
                'name' => 'penalty_payment',
                'title' => 'Տուգանքի Վճարում',
            ],
            [
                'name' => 'execution',
                'title' => 'Իրացում',
            ],
        ];
        foreach ($history_types as $type){
            HistoryType::create([
                'name' => $type['name'],
                'title' => $type['title'],
            ]);
        }

        $evaluators = [
            [
                'full_name' => 'Grigor',
                'pawnshop_id' => 1
            ],
        ];
        foreach ($evaluators as $evaluator){
            Evaluator::create([
                'full_name' => $evaluator['full_name'],
                'pawnshop_id' => $evaluator['pawnshop_id'],
            ]);
        }
    }
}
