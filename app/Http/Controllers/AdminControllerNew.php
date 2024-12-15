<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRateRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\Category;
use App\Models\CategoryRate;
use App\Models\Deal;
use App\Models\File;
use App\Models\LumpRate;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Subcategory;
use App\Models\SubcategoryItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AdminControllerNew extends Controller
{
    /**
     * Get admin info
     * @return UserResource
     */
    public function get(): UserResource
    {
        $user = User::with('files')->findOrFail(auth()->id());

        return new UserResource($user);
    }

    /**
     * Update admin info
     * @param UpdateAdminRequest $request
     * @return JsonResponse
     */
    public function update(UpdateAdminRequest $request): JsonResponse
    {
        $user = User::findOrFail(auth()->id());
        $validated = $request->validated();
        $user->update($validated);

        return response()->json([
            'message' => 'User information updated successfully',
        ]);
    }

    /**
     * Upload or update files for admin user
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'nullable|file|mimes:jpg,png,pdf,docx',
            'file_type' => 'required|string|max:255',
            'file_id' => 'nullable|exists:files,id',
        ]);

        $file = null;

        if ($request->has('file_id')) {
            $file = File::findOrFail($validated['file_id']);
            // If a new file is uploaded, replace the old one
            if ($request->hasFile('file')) {
                Storage::delete('public/client/files/' . $file->name);

                $uploadedFile = $request->file('file');
                $fileName = time() . '_' . $uploadedFile->getClientOriginalName();
                $uploadedFile->move(storage_path('app/public/client/files'), $fileName);

                $file->name = $fileName;
                $file->type = $uploadedFile->getClientMimeType();
                $file->original_name = $uploadedFile->getClientOriginalName();
            }

            $file->file_type = $validated['file_type'];
            $file->save();
        } else {
            $uploadedFile = $request->file('file');
            $fileName = time() . '_' . $uploadedFile->getClientOriginalName();
            $uploadedFile->move(storage_path('app/public/client/files'), $fileName);

            $file = File::create([
                'name' => $fileName,
                'type' => $uploadedFile->getClientMimeType(),
                'original_name' => $uploadedFile->getClientOriginalName(),
                'file_type' => $validated['file_type'],
                'fileable_id' => auth()->id(),
                'fileable_type' => User::class,
            ]);
        }

        return response()->json([
            'message' => 'File processed successfully.',
            'file' => $file,
        ], 201);
    }
    public function downloadFile(int $id)
    {
        $file = File::findOrFail($id);

        if ($file->fileable_id !== auth()->id() || $file->fileable_type !== User::class) {
            return response()->json([
                'message' => 'Unauthorized to download this file.',
            ], 403);
        }

        $filePath = storage_path('app/public/client/files/' . $file->name);

        if (!file_exists($filePath)) {
            return response()->json([
                'message' => 'File not found.',
            ], 404);
        }
        $mimeType = mime_content_type($filePath);


        return response()->download($filePath, $file->original_name,[
            'Content-Type' => $mimeType,
        ]);
    }

    /**
     * @param CreateUserRequest $request
     * @return JsonResponse
     */
    public function createUser(CreateUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['password'] = bcrypt($validated['password']);

        User::create($validated);

        return response()->json([
            'message' => 'User created successfully'
        ],201);

    }

    public function updateUsers(Request $request)
    {
        $validated  = $request->validate([
            'users' => 'required|array',
            'users.*.id' => 'required|integer|exists:users,id',
            'users.*.role' => 'nullable|string|max:255',
            'users.*.position' => 'nullable|string|max:255',
            'deleted_user_ids' => 'nullable|array',
            'deleted_user_ids.*' => 'integer|exists:users,id',
        ]);

        DB::beginTransaction();

        try {
            foreach ($validated['users'] as $userData) {
                $user = User::findOrFail($userData['id']);
                $user->update([
                    'role' => $userData['role'],
                    'position' => $userData['position'],
                ]);
            }

            if (!empty($validated['deleted_user_ids'])) {
                User::whereIn('id', $validated['deleted_user_ids'])->delete();
            }
            DB::commit();

            return response()->json([
                'message' => 'Users updated successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'An error occurred while updating users.',
                'error' => $e->getMessage(),
            ], 500);
        }

    }
    /**
     * Admin can update user role and position
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $user->update([
            'role' => $validated['role'],
            'position' => $validated['position'],
        ]);

        return response()->json([
            'message' => 'User updated successfully.',
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteUser(int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        try {
            $user->delete();

            return response()->json([
                'message' => 'User deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while deleting the user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get users list
     * @return JsonResponse
     */
    public function getUsers(): JsonResponse
    {
        $users = User::select('id','name','surname','middle_name',
            'role','position','start_work')
            ->orderBy('id','asc')
            ->paginate(10);

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'users'   => $users
        ]);
    }

    /**
     * Get categories with rates
     * @return JsonResponse
     */
    public function getCategories(): JsonResponse
    {
        $categories = Category::whereNull('deleted_at')
            ->select('id','name', 'title')
            ->with(['categoryRates' => function ($query) {
                $query->select('category_id', 'interest_rate', 'penalty', 'min_amount', 'max_amount');
            }])
            ->get();
        return response()->json([
            'categories' => $categories
        ]);
    }

    /**
     * @param CategoryRateRequest $request
     * @return JsonResponse
     */
    public function createRate(CategoryRateRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        CategoryRate::create([
            'category_id'   => $validatedData['category_id'],
            'interest_rate' => $validatedData['interest_rate'] ?? null,
            'penalty'       => $validatedData['penalty'] ?? null,
            'min_amount'    => $validatedData['min_amount'] ?? null,
            'max_amount'    => $validatedData['max_amount'] ?? null,
        ]);

        return response()->json([
            'message' => 'Category rate created successfully.',
        ],201);
    }

    public function updateRate(CategoryRateRequest $request, int $id): JsonResponse
    {
        $validatedData = $request->validated();

        $categoryRates = CategoryRate::find($id);

        if (!$categoryRates) {
            return response()->json([
                'message' => 'Category rate not found.',
            ], 404);
        }
        $categoryRates->update([
            'category_id'   => $validatedData['category_id'],
            'interest_rate' => $validatedData['interest_rate'] ?? $categoryRates->interest_rate,
            'penalty'       => $validatedData['penalty'] ?? $categoryRates->penalty,
            'min_amount'    => $validatedData['min_amount'] ?? $categoryRates->min_amount,
            'max_amount'    => $validatedData['max_amount'] ?? $categoryRates->max_amount,
        ]);

        return response()->json([
            'message' => 'Category rate updated successfully.',
        ]);
    }

    public function deleteRate($id)
    {
        $categoryRate = CategoryRate::find($id);

        if (!$categoryRate) {
            return response()->json([
                'message' => 'Category rate not found.',
            ],404);
        }
        $categoryRate->delete();

        return response()->json([
            'message' => 'Category rates deleted successfully.',
        ]);
    }
    public function getLumpRates(): JsonResponse
    {
        $lump_rates = LumpRate::select('id','lump_rate','min_amount','max_amount')->get();
        return response()->json([
            'lump_rates' => $lump_rates
        ]);
    }

    public function createLumpRate(Request $request)
    {
        $validated = $request->validate([
            'lump_rate' => 'required|numeric',
            'min_amount' => 'nullable|integer|min:0',
            'max_amount' => 'nullable|integer|min:0',
        ]);

        LumpRate::create([
            'lump_rate'       => $validated['lump_rate'] ,
            'min_amount'    => $validated['min_amount'] ?? null,
            'max_amount'    => $validated['max_amount'] ?? null,
        ]);

        return response()->json([
            'message' => 'Lump rate created successfully!',
        ], 201);
    }


    public function getCategoryDuration(): JsonResponse
    {
        $category_duration = Category::select('id','name','title','duration')->get();

        return response()->json([
            'message' => 'Category durations retrieved successfully.',
            'category_duration' => $category_duration
        ]);
    }
    public function saveCategoryDuration(Request $request): JsonResponse
    {
        $validated_data = $request->validate([
            'durations' => 'required|array',
            'durations.*.category_id' => 'required|exists:categories,id',  // Category ID must exist
            'durations.*.duration' => 'required|integer|min:0',
        ]);
        foreach ($validated_data['durations'] as $duration) {
            $category = Category::find($duration['category_id']);

            if ($category) {
                $category->duration = $duration['duration'];
                $category->save();
            }
        }
        return response()->json([
            'message' => 'Durations for categories saved successfully.',
        ]);
    }

    public function updateLumpRate(Request $request,$id)
    {
        $validated = $request->validate([
            'lump_rate' => 'required|numeric',
            'min_amount' => 'nullable|integer|min:0',
            'max_amount' => 'nullable|integer|min:0',
        ]);

        $lumpRate = LumpRate::find($id);

        if (!$lumpRate) {
            return response()->json([
                'message' => 'Lump rate not found.',
            ], 404);
        }

        $lumpRate->lump_rate = $validated['lump_rate'];
        $lumpRate->min_amount = $validated['min_amount'] ?? $lumpRate->min_amount;
        $lumpRate->max_amount = $validated['max_amount'] ?? $lumpRate->max_amount;

        $lumpRate->save();

        return response()->json([
            'message' => 'Lump rate updated successfully!',
        ]);
    }

    public function deleteLumpRate($id)
    {
        $lumpRate = LumpRate::find($id);

        if (!$lumpRate) {
            return response()->json([
                'message' => 'Lump rate not found.',
            ], 404);
        }

        $lumpRate->delete();

        return response()->json([

            'message' => 'Lump rate deleted successfully.',
        ]);
    }

    public function getCategoriesWithSubcategories(): JsonResponse
    {
        $categories = Category::with('subcategories.items')->get();

        return response()->json([
            'categories' => $categories
        ]);
    }
    public function addSubcategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:255',
        ]);

        $existing_subcategory = Subcategory::where('category_id',$validated['category_id'])
            ->where('name',$validated['name'])
            ->first();

        if ($existing_subcategory) {
            return response()->json([
                'message' => 'Subcategory already exists for this category.',
                'subcategory' => $existing_subcategory
            ], 409);
        }

        Subcategory::create($validated);

        return response()->json([
            'message' => 'Subcategory added successfully.',
        ], 201);
    }
    public function addSubcategoryItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subcategory_id' => 'required|exists:subcategories,id',
            'model' => 'required|string|max:255',
        ]);

        $existingItem = SubcategoryItem::where('subcategory_id', $validated['subcategory_id'])
            ->where('model', $validated['model'])
            ->first();

        if ($existingItem) {
            return response()->json([
                'message' => 'Subcategory item already exists for this subcategory.',
            ], 409);
        }

        $subcategoryItem = SubcategoryItem::create($validated);

        return response()->json([
            'message' => 'Subcategory item added successfully.',
            'subcategory_item' => $subcategoryItem,
        ], 201);
    }

    public function deleteSubcategoryItem(int $id): JsonResponse
    {
        $subcategory_item = SubcategoryItem::find($id);
        if (!$subcategory_item) {
            return response()->json([
                'message' => 'Item not found.'
            ], 404);
        }

        $subcategory_item->delete();

        return response()->json([
            'message' => 'Item deleted successfully.'
        ]);
    }

    public function getPawnshops(): JsonResponse
    {
        $pawnshops = Pawnshop::select(['id', 'city', 'license', 'cashbox', 'bank_cashbox', 'assurance_money'])
            ->withCount('users')
            ->with(['users:id,name,surname,middle_name,role,pawnshop_id'])
            ->get()
            ->map(function ($pawnshop) {
                $enter = $pawnshop->orders()->where('type', 'in')->sum('amount') ?? 0;
                $exist = $pawnshop->orders()->where('type', 'cost_out')->sum('amount') ?? 0;
                $pawnshop->ndm = $enter - $exist;
                return $pawnshop;
            });

        return response()->json([
            "pawnshops" => $pawnshops,
        ]);
    }
    public function updatePawnshop(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'city' => 'required|string|max:255',
            'license' => 'required|string|max:255',
            'assurance_money' => 'nullable|numeric|min:0',
        ]);

        $pawnshop = Pawnshop::withTrashed()->find($id);

        if (!$pawnshop) {
            return response()->json([
                'message' => 'Pawnshop not found',
            ], 404);
        }

        $pawnshop->city = $validated['city'];
        $pawnshop->license = $validated['license'];
        if (isset($validated['assurance_money'])) {
            $pawnshop->assurance = $validated['assurance_money'];
        }

        $pawnshop->save();

        return response()->json([
            'message' => 'Pawnshop updated successfully',
            'pawnshop' => $pawnshop
        ]);
    }

    public function getDeals(Request $request): JsonResponse
    {

        $filter_type = $request->query('filter','history');

        $dealsQuery = Deal::select('id','client_id','order_id','cash','contract_id','delay_days',
        'interest_amount','purpose','penalty','discount','created_by')
            ->with('client:id,name,surname')
            ->with('contract:id,mother')
            ->with('createdBy:id,name,surname');

        switch ($filter_type) {
            case 'cost_in':
                $dealsQuery->where('type', 'in');
                break;

            case 'cost_out':
                $dealsQuery->whereIn('type', 'cost_out','out');
                break;

            case 'expense':
                $dealsQuery->where('type', 'cost_out')->where('filter_type', Order::EXPENSE_FILTER);
                break;

            case 'history':
            default:
                break;
        }

        $deals = $dealsQuery->get();

        return response()->json([
            'deals' => $deals
        ]);
    }
    public function updateDeal(Request $request, $id): JsonResponse
    {
        $deal = Deal::findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'cash'   => 'required|boolean',
            'type'   => 'required|string'
        ]);

        $pawnshop = $deal->pawnshop;

        if ($validated->type === 'payment') {

        } else {
            if ($deal->type === 'in') {
                $deal->cash ? $pawnshop->cashbox -= $deal->amount : $pawnshop->bank_cashbox -= $deal->amount;
                $validated['cash'] ? $pawnshop->cashbox += $validated['amount'] : $pawnshop->bank_cashbox += $validated['amount'];
            } else {
                $deal->cash ? $pawnshop->cashbox += $deal->amount : $pawnshop->bank_cashbox += $deal->amount;
                $validated['cash'] ? $pawnshop->cashbox -= $validated['amount'] : $pawnshop->bank_cashbox -= $validated['amount'];
            }

            $pawnshop->save();

            $deal->update([
                'amount' => $validated['amount'],
                'cash' => $validated['cash'],
            ]);
        }

        return response()->json(['message' => 'Deal updated successfully']);
    }
    public function deleteDeal($id): JsonResponse
    {
        $deal = Deal::findOrFail($id);

        $pawnshop = $deal->pawnshop;

        if ($deal->type === 'in') {
            $deal->cash ? $pawnshop->cashbox -= $deal->amount : $pawnshop->bank_cashbox -= $deal->amount;
        } else {
            $deal->cash ? $pawnshop->cashbox += $deal->amount : $pawnshop->bank_cashbox += $deal->amount;
        }

        $pawnshop->save();
        $deal->delete();

        return response()->json(['message' => 'Deal deleted successfully']);
    }



}
