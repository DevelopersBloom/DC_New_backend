<?php

namespace Tests\Feature\Admin;

use App\Models\Pawnshop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Covers the /api/admin/users endpoints. Runs inside DatabaseTransactions against
 * the project's dev MySQL DB (like the other feature tests), so nothing persists.
 */
class UserManagementTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_PERMISSIONS = ['view_users', 'create_user', 'update_user', 'delete_user'];

    private Pawnshop $pawnshop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pawnshop = Pawnshop::find(1) ?? tap(new Pawnshop(), function (Pawnshop $p) {
            $p->id = 1;
            $p->save();
        });

        foreach (self::USER_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
        foreach (['admin', 'manager', 'accountant'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
        Role::findByName('admin', 'api')->givePermissionTo(self::USER_PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'surname' => 'Test',
            'pawnshop_id' => $this->pawnshop->id,
            'role' => $role,
        ], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Արմինե',
            'surname' => 'Մինասյան',
            'middle_name' => 'Արամի',
            'email' => 'new.user.' . uniqid() . '@example.com',
            'tel' => '+37499000000',
            'position' => 'Մենեջեր',
            'role' => 'manager',
            'pawnshop_id' => $this->pawnshop->id,
            'start_work' => '2026-09-01',
            'password' => 'secret-pass-1',
        ], $overrides);
    }

    public function test_user_without_permission_cannot_list_users(): void
    {
        $manager = $this->makeUser('manager');

        $this->getJson('/api/admin/users', $this->headers($manager))->assertForbidden();
    }

    public function test_admin_can_list_and_search_users(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('accountant', ['name' => 'Zzfindme']);

        $this->getJson('/api/admin/users?search=Zzfindme&role=accountant', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.role', 'accountant')
            ->assertJsonPath('data.0.pawnshop.id', $this->pawnshop->id);
    }

    public function test_meta_returns_roles_and_pawnshops(): void
    {
        $admin = $this->makeUser('admin');

        $this->getJson('/api/admin/users/meta', $this->headers($admin))
            ->assertOk()
            ->assertJsonFragment(['roles' => Role::where('guard_name', 'api')->orderBy('id')->pluck('name')->all()])
            ->assertJsonStructure(['pawnshops' => [['id', 'city']]]);
    }

    public function test_admin_can_create_user_with_role_in_both_places(): void
    {
        $admin = $this->makeUser('admin');
        $payload = $this->payload();

        $response = $this->postJson('/api/admin/users', $payload, $this->headers($admin))
            ->assertCreated()
            ->assertJsonPath('user.role', 'manager')
            ->assertJsonPath('user.middle_name', 'Արամի')
            ->assertJsonPath('user.tel', '+37499000000')
            ->assertJsonMissingPath('user.password');

        $user = User::findOrFail($response->json('user.id'));
        $this->assertSame('manager', $user->role);
        $this->assertTrue($user->hasRole('manager'));
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));
    }

    public function test_create_validates_fields(): void
    {
        $admin = $this->makeUser('admin');

        $this->postJson('/api/admin/users', $this->payload([
            'name' => '',
            'email' => 'not-an-email',
            'role' => 'user',
            'password' => 'short',
        ]), $this->headers($admin))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'role', 'password']);
    }

    public function test_create_with_email_of_deleted_user_suggests_restore(): void
    {
        $admin = $this->makeUser('admin');
        $deleted = $this->makeUser('manager');
        $deleted->delete();

        $this->postJson('/api/admin/users', $this->payload(['email' => $deleted->email]), $this->headers($admin))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_update_user_and_change_role(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('manager');

        $this->putJson("/api/admin/users/{$target->id}", $this->payload([
            'email' => $target->email,
            'name' => 'Updated',
            'role' => 'accountant',
        ]), $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated')
            ->assertJsonPath('user.role', 'accountant');

        $target->refresh();
        $this->assertSame('accountant', $target->role);
        $this->assertTrue($target->hasRole('accountant'));
        $this->assertFalse($target->hasRole('manager'));
    }

    public function test_user_cannot_change_own_role(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('admin');

        $this->putJson("/api/admin/users/{$admin->id}", $this->payload([
            'email' => $admin->email,
            'role' => 'manager',
        ]), $this->headers($admin))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_non_admin_cannot_assign_admin_role(): void
    {
        $manager = $this->makeUser('manager');
        $manager->givePermissionTo(self::USER_PERMISSIONS);
        $target = $this->makeUser('accountant');

        $this->putJson("/api/admin/users/{$target->id}", $this->payload([
            'email' => $target->email,
            'role' => 'admin',
        ]), $this->headers($manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_user_cannot_delete_self(): void
    {
        $admin = $this->makeUser('admin');

        $this->deleteJson("/api/admin/users/{$admin->id}", [], $this->headers($admin))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);
    }

    public function test_non_admin_cannot_delete_admin(): void
    {
        $manager = $this->makeUser('manager');
        $manager->givePermissionTo(self::USER_PERMISSIONS);
        $admin = $this->makeUser('admin');

        $this->deleteJson("/api/admin/users/{$admin->id}", [], $this->headers($manager))
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_delete_and_restore_user(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('manager');

        $this->deleteJson("/api/admin/users/{$target->id}", [], $this->headers($admin))->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->getJson('/api/admin/users?status=deleted&search=' . urlencode($target->email), $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('data.0.id', $target->id);

        $this->postJson("/api/admin/users/{$target->id}/restore", [], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('user.id', $target->id);
        $this->assertNotSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_admin_can_reset_password(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('manager');

        $this->putJson("/api/admin/users/{$target->id}/password", ['password' => 'brand-new-pass'], $this->headers($admin))
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $target->fresh()->password));
    }
}
