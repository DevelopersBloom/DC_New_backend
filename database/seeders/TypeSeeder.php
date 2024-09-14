<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Evaluator;
use App\Models\HistoryType;
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
                'name' => 'electronics',  // Adding the new 'electronics' category
                'title' => 'Տեխնիկա',
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

        foreach ($types as $type) {
            Category::updateOrCreate(
                ['name' => $type['name']],  // Check if the category already exists
                ['title' => $type['title']] // If it exists, update; otherwise, insert
            );
        }

        // History types
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

        foreach ($history_types as $type) {
            HistoryType::updateOrCreate(
                ['name' => $type['name']],
                ['title' => $type['title']]
            );
        }

        // Evaluators
        $evaluators = [
            [
                'full_name' => 'Grigor',
                'pawnshop_id' => 1
            ],
        ];

        foreach ($evaluators as $evaluator) {
            Evaluator::updateOrCreate(
                ['full_name' => $evaluator['full_name']],
                ['pawnshop_id' => $evaluator['pawnshop_id']]
            );
        }
    }
}
