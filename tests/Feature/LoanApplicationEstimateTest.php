<?php

namespace Tests\Feature;

use App\Models\LoanApplication;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

class LoanApplicationEstimateTest extends TestCase
{
    use DatabaseTransactions;
    use LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
    }

    public function test_first_estimate_moves_application_into_review(): void
    {
        $user = $this->userWith(['estimate_loan_application_collateral']);
        $application = $this->makeApplication(LoanApplication::STATUS_SUBMITTED, $this->makeClient());

        $this->postJson("/api/loan-applications/{$application->id}/estimates", [
            'estimated_amount' => 500000,
            'note'             => 'Clean 585 gold',
        ], $this->authHeaders($user))->assertOk();

        $this->assertSame(LoanApplication::STATUS_IN_REVIEW, $application->fresh()->status);
        $this->assertDatabaseHas('loan_application_estimates', [
            'loan_application_id' => $application->id,
            'user_id'             => $user->id,
            'estimated_amount'    => 500000,
        ]);
    }

    public function test_resubmitting_updates_in_place_and_pushes_prior_value_to_history(): void
    {
        $user = $this->userWith(['estimate_loan_application_collateral']);
        $application = $this->makeApplication(LoanApplication::STATUS_SUBMITTED, $this->makeClient());
        $headers = $this->authHeaders($user);

        $this->postJson("/api/loan-applications/{$application->id}/estimates", ['estimated_amount' => 400000], $headers)->assertOk();
        $this->postJson("/api/loan-applications/{$application->id}/estimates", ['estimated_amount' => 450000], $headers)->assertOk();

        // One-per-admin: still a single row.
        $this->assertSame(1, $application->estimates()->where('user_id', $user->id)->count());

        $estimate = $application->estimates()->where('user_id', $user->id)->first();
        $this->assertEquals(450000, (float) $estimate->estimated_amount);
        $this->assertNotEmpty($estimate->history);
        $this->assertEquals(400000, (float) $estimate->history[0]['estimated_amount']);
    }

    public function test_two_admins_get_two_independent_estimates(): void
    {
        $a = $this->userWith(['estimate_loan_application_collateral']);
        $b = $this->userWith(['estimate_loan_application_collateral']);
        $application = $this->makeApplication(LoanApplication::STATUS_SUBMITTED, $this->makeClient());

        $this->postJson("/api/loan-applications/{$application->id}/estimates", ['estimated_amount' => 300000], $this->authHeaders($a))->assertOk();
        $this->postJson("/api/loan-applications/{$application->id}/estimates", ['estimated_amount' => 350000], $this->authHeaders($b))->assertOk();

        $this->assertSame(2, $application->estimates()->count());
    }

    public function test_estimate_rejected_after_decision(): void
    {
        $user = $this->userWith(['estimate_loan_application_collateral']);
        $application = $this->makeApplication(LoanApplication::STATUS_APPROVED, $this->makeClient());

        $this->postJson("/api/loan-applications/{$application->id}/estimates", [
            'estimated_amount' => 500000,
        ], $this->authHeaders($user))->assertStatus(409);
    }

    public function test_estimate_forbidden_without_permission(): void
    {
        $user = $this->userWith(['view_loan_applications']);
        $application = $this->makeApplication(LoanApplication::STATUS_SUBMITTED, $this->makeClient());

        $this->postJson("/api/loan-applications/{$application->id}/estimates", [
            'estimated_amount' => 500000,
        ], $this->authHeaders($user))->assertStatus(403);
    }
}
