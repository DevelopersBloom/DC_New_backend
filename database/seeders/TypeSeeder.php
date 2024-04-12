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
        $types = [
            [
                'name' => 'gold',
                'title' => 'Ոսկի',
            ],
            [
                'name' => 'phone',
                'title' => 'Հեռախոս',
            ],
            [
                'name' => 'laptop',
                'title' => 'Նոթբուք',
            ],
            [
                'name' => 'tablet',
                'title' => 'Պլանշետ',
            ],
            [
                'name' => 'pc',
                'title' => 'Համակարգիչ',
            ],
            [
                'name' => 'tv',
                'title' => 'Հեռուստացույց',
            ],
            [
                'name' => 'car',
                'title' => 'Ավտոմեքենա',
            ],
            [
                'name' => 'other',
                'title' => 'Այլ',
            ],
        ];
        foreach ($types as $type){
            Category::create([
                'name' => $type['name'],
                'title' => $type['title'],
            ]);
        }
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
                'title' => 'Վճարում',
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
