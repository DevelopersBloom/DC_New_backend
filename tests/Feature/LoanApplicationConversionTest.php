<?php

namespace Tests\Feature;

use App\Models\LoanApplication;
use App\Services\LoanApplicationConversionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

class LoanApplicationConversionTest extends TestCase
{
    use DatabaseTransactions;
    use LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
    }

    public function test_convert_endpoint_blocks_on_incomplete_profile_with_missing_fields(): void
    {
        $user = $this->userWith(['convert_loan_application']);
        // Bare client — no passport / gender / etc.
        $application = $this->makeApplication(LoanApplication::STATUS_APPROVED, $this->makeClient());

        $this->postJson("/api/loan-applications/{$application->id}/convert", [], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'missing_fields'])
            ->assertJsonPath('missing_fields', fn ($fields) => in_array('passport_series', $fields, true)
                && in_array('gender', $fields, true));

        $this->assertSame(LoanApplication::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_convert_endpoint_blocks_on_non_approved_status(): void
    {
        $user = $this->userWith(['convert_loan_application']);
        $application = $this->makeApplication(LoanApplication::STATUS_IN_REVIEW, $this->makeFullClient());

        $this->postJson("/api/loan-applications/{$application->id}/convert", [], $this->authHeaders($user))
            ->assertStatus(409);
    }

    public function test_convert_service_throws_on_non_approved_status(): void
    {
        $application = $this->makeApplication(LoanApplication::STATUS_SUBMITTED, $this->makeFullClient());

        $this->expectException(RuntimeException::class);
        app(LoanApplicationConversionService::class)->convert($application);
    }

    public function test_successful_conversion_creates_contract_and_items(): void
    {
        $user = $this->userWith(['convert_loan_application']);
        $client = $this->makeFullClient();
        $application = $this->makeApplication(LoanApplication::STATUS_APPROVED, $client);
        $estimate = $this->estimateFor($application, $user, 800000);
        $application->update(['final_estimate_id' => $estimate->id]);

        $response = $this->postJson("/api/loan-applications/{$application->id}/convert", [], $this->authHeaders($user));
        $response->assertOk();

        $contractId = $response->json('data.contract_id');
        $this->assertNotNull($contractId);

        $this->assertDatabaseHas('contracts', [
            'id'               => $contractId,
            'client_id'        => $client->id,
            'estimated_amount' => 800000,
        ]);
        $this->assertDatabaseHas('contract_item', ['contract_id' => $contractId]);

        $fresh = $application->fresh();
        $this->assertSame(LoanApplication::STATUS_CONVERTED, $fresh->status);
        $this->assertSame((int) $contractId, (int) $fresh->contract_id);
    }
}
