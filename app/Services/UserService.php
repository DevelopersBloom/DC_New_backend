<?php

namespace App\Services;

use App\Models\Pawnshop;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserService
{
    private const GUARD = 'api';
    private const ADMIN_ROLE = 'admin';

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->with(['roles:id,name', 'pawnshop:id,city'])
            ->when(($filters['status'] ?? 'active') === 'deleted', fn (Builder $q) => $q->onlyTrashed())
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%' . $search . '%';
                $q->where(function (Builder $q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('surname', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('tel', 'like', $like)
                        ->orWhere(DB::raw("CONCAT_WS(' ', name, surname)"), 'like', $like)
                        ->orWhere(DB::raw("CONCAT_WS(' ', surname, name)"), 'like', $like);
                });
            })
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => $q->whereHas(
                'roles',
                fn (Builder $r) => $r->where('name', $role)->where('guard_name', self::GUARD)
            ))
            ->when($filters['pawnshop_id'] ?? null, fn (Builder $q, $id) => $q->where('pawnshop_id', $id))
            ->orderBy('surname')
            ->orderBy('name');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function meta(): array
    {
        return [
            'roles' => Role::where('guard_name', self::GUARD)
                ->orderBy('id')
                ->pluck('name')
                ->values(),
            'pawnshops' => Pawnshop::orderBy('id')->get(['id', 'city']),
        ];
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $role = $data['role'];
            unset($data['role']);

            $data['password'] = Hash::make($data['password']);

            $user = User::create($data);
            $this->syncRole($user, $role);

            return $user->load(['roles:id,name', 'pawnshop:id,city']);
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        $this->assertCanManage($actor, $user);

        $newRole = $data['role'];
        unset($data['role']);
        $currentRole = $this->roleOf($user);

        if ($newRole !== $currentRole) {
            if ($actor->is($user)) {
                throw ValidationException::withMessages([
                    'role' => 'Չեք կարող փոխել ձեր սեփական դերը։',
                ]);
            }
            if ($newRole === self::ADMIN_ROLE && !$actor->hasRole(self::ADMIN_ROLE)) {
                throw ValidationException::withMessages([
                    'role' => 'Միայն ադմինը կարող է նշանակել ադմին դեր։',
                ]);
            }
            if ($currentRole === self::ADMIN_ROLE) {
                $this->assertNotLastAdmin($user, 'role');
            }
        }

        return DB::transaction(function () use ($user, $data, $newRole, $currentRole) {
            $user->update($data);

            if ($newRole !== $currentRole) {
                $this->syncRole($user, $newRole);
            }

            return $user->load(['roles:id,name', 'pawnshop:id,city']);
        });
    }

    public function resetPassword(User $user, string $password, User $actor): void
    {
        $this->assertCanManage($actor, $user);

        $user->forceFill(['password' => Hash::make($password)])->save();
    }

    public function delete(User $user, User $actor): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'Չեք կարող ջնջել ձեր սեփական հաշիվը։',
            ]);
        }

        $this->assertCanManage($actor, $user);

        if ($this->roleOf($user) === self::ADMIN_ROLE) {
            $this->assertNotLastAdmin($user, 'user');
        }

        $user->delete();
    }

    public function restore(int $id): User
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();

        return $user->load(['roles:id,name', 'pawnshop:id,city']);
    }

    public function roleOf(User $user): ?string
    {
        return $user->getRoleNames()->first();
    }

    /**
     * Keeps the legacy users.role column and the Spatie role in step.
     */
    private function syncRole(User $user, string $role): void
    {
        $user->syncRoles([Role::findByName($role, self::GUARD)]);
        $user->forceFill(['role' => $role])->save();
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if (
            !$actor->is($target)
            && $this->roleOf($target) === self::ADMIN_ROLE
            && !$actor->hasRole(self::ADMIN_ROLE)
        ) {
            abort(403, 'Միայն ադմինը կարող է փոփոխել ադմին օգտատիրոջը։');
        }
    }

    private function assertNotLastAdmin(User $user, string $field): void
    {
        $otherAdmins = User::whereKeyNot($user->getKey())
            ->whereHas('roles', fn (Builder $r) => $r
                ->where('name', self::ADMIN_ROLE)
                ->where('guard_name', self::GUARD))
            ->count();

        if ($otherAdmins === 0) {
            throw ValidationException::withMessages([
                $field => 'Համակարգում պետք է մնա առնվազն մեկ ադմին։',
            ]);
        }
    }
}
