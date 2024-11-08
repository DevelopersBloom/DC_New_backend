<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $categories = Category::select('id', 'title','name')->get();
        return response()->json($categories);
    }

    /**
     * @param int $category_id
     * @return JsonResponse
     */
    public function show(int $category_id): JsonResponse
    {
        $category = Category::select('interest_rate', 'penalty', 'lump_rate')
            ->findOrFail($category_id);
        return response()->json($category);
    }
}
