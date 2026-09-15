<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\UserListResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'pawnshop_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'deleted'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return UserListResource::collection($this->userService->paginate($filters));
    }

    public function meta(): JsonResponse
    {
        return response()->json($this->userService->meta());
    }

    public function show(User $user): UserListResource
    {
        return new UserListResource($user->load(['roles:id,name', 'pawnshop:id,city']));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return response()->json([
            'message' => 'Օգտատերը ստեղծվեց։',
            'user' => new UserListResource($user),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Օգտատերը թարմացվեց։',
            'user' => new UserListResource($user),
        ]);
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $this->userService->resetPassword($user, $request->validated('password'), $request->user());

        return response()->json(['message' => 'Գաղտնաբառը փոխվեց։']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->userService->delete($user, $request->user());

        return response()->json(['message' => 'Օգտատերը ջնջվեց։']);
    }

    public function restore(int $id): JsonResponse
    {
        $user = $this->userService->restore($id);

        return response()->json([
            'message' => 'Օգտատերը վերականգնվեց։',
            'user' => new UserListResource($user),
        ]);
    }
}
