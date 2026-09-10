<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

class LoanApplicationIntakeTest extends TestCase
{
    use DatabaseTransactions;
    use LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
    }

    public function test_quick_intake_only_requires_name_surname_phone(): void
    {
        $user = $this->userWith(['create_client']);

        // Missing phone -> 422, and passport/gender are NOT required.
        $this->postJson('/api/clients/store-non-client', [
            'name'    => 'Anush',
            'surname' => 'Petrosyan',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone'])
            ->assertJsonMissingValidationErrors(['passport_series', 'gender', 'document_type', 'residency_status']);

        $response = $this->postJson('/api/clients/store-non-client', [
            'name'    => 'Anush',
            'surname' => 'Petrosyan',
            'phone'   => '+37455123456',
        ], $this->authHeaders($user));

        $response->assertStatus(201);
        $this->assertDatabaseHas('clients', [
            'name'         => 'Anush',
            'surname'      => 'Petrosyan',
            'has_contract' => false,
        ]);
    }

    public function test_application_store_blocks_likely_duplicate_non_client(): void
    {
        $user = $this->userWith(['create_loan_application']);
        $this->makeClient(['name' => 'Duplicate', 'surname' => 'Person']);

        $payload = [
            'loan_type'  => 'gold',
            'new_client' => ['name' => 'Duplicate', 'surname' => 'Person', 'phone' => '+37455000111'],
            'items'      => [[
                'category_id' => $this->category->id,
                'subcategory' => 'Chain',
                'weight'      => 5,
                'hallmark'    => '585',
            ]],
        ];

        $this->postJson('/api/loan-applications', $payload, $this->authHeaders($user))
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'candidates']);

        // force=true bypasses the guard and creates the application.
        $this->postJson('/api/loan-applications', $payload + ['force' => true], $this->authHeaders($user))
            ->assertStatus(201);

        $this->assertDatabaseHas('loan_applications', [
            'loan_type' => 'gold',
            'status'    => 'submitted',
        ]);
    }

    public function test_application_store_for_existing_client_persists_items(): void
    {
        $user = $this->userWith(['create_loan_application']);
        $client = $this->makeClient();

        $response = $this->postJson('/api/loan-applications', [
            'client_id' => $client->id,
            'loan_type' => 'gold',
            'items'     => [[
                'category_id' => $this->category->id,
                'subcategory' => 'Bracelet',
                'weight'      => 12.5,
                'hallmark'    => '750',
            ]],
        ], $this->authHeaders($user));

        $response->assertStatus(201);
        $applicationId = $response->json('data.id');

        $this->assertDatabaseHas('loan_application_items', [
            'loan_application_id' => $applicationId,
            'subcategory'         => 'Bracelet',
        ]);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = $this->userWith([]); // no permissions
        $client = $this->makeClient();

        $this->postJson('/api/loan-applications', [
            'client_id' => $client->id,
            'loan_type' => 'gold',
            'items'     => [['category_id' => $this->category->id, 'subcategory' => 'X', 'weight' => 1, 'hallmark' => '585']],
        ], $this->authHeaders($user))->assertStatus(403);
    }
}
