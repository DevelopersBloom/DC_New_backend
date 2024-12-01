<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRateRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\Category;
use App\Models\CategoryRate;
use App\Models\File;
use App\Models\LumpRate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->download($filePath, $file->original_name);
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

}
