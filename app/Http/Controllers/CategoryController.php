<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::where('pawnshop_id', auth()->user()->id)
            ->select('id', 'title')
            ->get();

        return response()->json($categories);
    }

    public function getCategoryDetails(int $categoryId)
    {
        $category = Category::select('interest_rate', 'penalty', 'lump_rate')
            ->where('id', $categoryId);
        if (!$category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        return response()->json([
            'rate' => $category->rate,
            'penalty' => $category->penalty,
            'lump_rate' => $category->lump_rate,
        ]);
    }
}
