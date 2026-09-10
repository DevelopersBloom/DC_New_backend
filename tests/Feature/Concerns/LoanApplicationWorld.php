<?php

namespace Tests\Feature\Concerns;

use App\Models\Category;
use App\Models\Client;
use App\Models\LoanApplication;
use App\Models\LoanApplicationEstimate;
use App\Models\Pawnshop;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Shared scaffolding for the loan-application feature tests. All rows are created
 * inside the test's own DB transaction (DatabaseTransactions) and rolled back,
 * so nothing persists in the shared dev database.
 *
 * These tests need a reachable MySQL instance (the project's `lomb` dev DB) with
 * pawnshop #1 present — everything else is created here.
 */
trait LoanApplicationWorld
{
    protected Pawnshop $pawnshop;
    protected Category $category;

    protected function bootLoanApplicationWorld(): void
    {
        $this->pawnshop = Pawnshop::find(1) ?? tap(new Pawnshop(), function (Pawnshop $p) {
            $p->id = 1;
            $p->save();
        });

        $this->category = Category::firstOrCreate(
            ['name' => 'gold'],
            ['title' => 'Gold', 'pawnshop_id' => 1]
        );

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function userWith(array $permissions): User
    {
        $user = User::factory()->create(['pawnshop_id' => $this->pawnshop->id]);

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $user->givePermissionTo($permissions);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user;
    }

    protected function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
    }

    protected function makeClient(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'type'         => 'individual',
            'name'         => 'Test',
            'surname'      => 'Applicant',
            'phone'        => '+37411000000',
            'has_contract' => false,
        ], $overrides));
    }

    /** A client whose KYC profile is complete enough for conversion. */
    protected function makeFullClient(): Client
    {
        return $this->makeClient([
            'passport_series'   => 'AN1234567',
            'passport_validity' => '2030-01-01',
            'passport_issued'   => '2020-01-01 police',
            'date_of_birth'     => '1990-01-01',
            'gender'            => 'MALE',
            'document_type'     => 'ID_CARD',
            'residency_status'  => 'resident',
        ]);
    }

    protected function makeApplication(string $status, Client $client): LoanApplication
    {
        $application = LoanApplication::create([
            'client_id'   => $client->id,
            'loan_type'   => 'gold',
            'status'      => $status,
            'pawnshop_id' => $this->pawnshop->id,
            'created_by'  => User::factory()->create(['pawnshop_id' => $this->pawnshop->id])->id,
        ]);

        $application->items()->create([
            'category_id' => $this->category->id,
            'subcategory' => 'Ring',
            'weight'      => 10,
            'hallmark'    => '585',
        ]);

        return $application;
    }

    protected function estimateFor(LoanApplication $application, User $user, float $amount): LoanApplicationEstimate
    {
        return $application->estimates()->create([
            'user_id'          => $user->id,
            'estimated_amount' => $amount,
        ]);
    }
}
