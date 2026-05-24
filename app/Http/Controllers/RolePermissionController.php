<?php

namespace App\Http\Controllers;

use App\Services\RolePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    public function __construct(
        private readonly RolePermissionService $rolePermissionService
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            $this->rolePermissionService->getRolePermissionsPayload()
        );
    }

    public function update(Request $request, int $roleId): JsonResponse
    {
        $validated = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer'],
        ]);

        $payload = $this->rolePermissionService->syncRolePermissions(
            $roleId,
            $validated['permission_ids']
        );

        return response()->json($payload);
    }
}
