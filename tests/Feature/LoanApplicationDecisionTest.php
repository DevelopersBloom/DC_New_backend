<?php

namespace Tests\Feature;

use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

class LoanApplicationDecisionTest extends TestCase
{
    use DatabaseTransactions;
    use LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
    }

    public function test_decide_is_gated_by_its_own_permission(): void
    {
        // An estimator is not automatically an approver.
        $estimator = $this->userWith(['estimate_loan_application_collateral']);
        $application = $this->makeApplication(LoanApplication::STATUS_IN_REVIEW, $this->makeClient());
        $estimate = $this->estimateFor($application, $estimator, 500000);

        $this->postJson("/api/loan-applications/{$application->id}/decide", [
            'status'            => 'approved',
            'final_estimate_id' => $estimate->id,
        ], $this->authHeaders($estimator))->assertStatus(403);
    }

    public function test_approver_can_approve_with_a_final_estimate(): void
    {
        $approver = $this->userWith(['decide_loan_application']);
        $estimator = $this->userWith(['estimate_loan_application_collateral']);
        $application = $this->makeApplication(LoanApplication::STATUS_IN_REVIEW, $this->makeClient());
        $estimate = $this->estimateFor($application, $estimator, 500000);

        $this->postJson("/api/loan-applications/{$application->id}/decide", [
            'status'            => 'approved',
            'final_estimate_id' => $estimate->id,
        ], $this->authHeaders($approver))->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(LoanApplication::STATUS_APPROVED, $fresh->status);
        $this->assertSame($estimate->id, $fresh->final_estimate_id);
        $this->assertSame($approver->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_final_estimate_must_belong_to_this_application(): void
    {
        $approver = $this->userWith(['decide_loan_application']);
        $estimator = $this->userWith(['estimate_loan_application_collateral']);

        $application = $this->makeApplication(LoanApplication::STATUS_IN_REVIEW, $this->makeClient());
        $other = $this->makeApplication(LoanApplication::STATUS_IN_REVIEW, $this->makeClient());
        $foreignEstimate = $this->estimateFor($other, $estimator, 777000);

        $this->postJson("/api/loan-applications/{$application->id}/decide", [
            'status'            => 'approved',
            'final_estimate_id' => $foreignEstimate->id,
        ], $this->authHeaders($approver))->assertStatus(422);

        $this->assertSame(LoanApplication::STATUS_IN_REVIEW, $application->fresh()->status);
    }

    public function test_reject_requires_a_reason_and_sets_it(): void
    {
        $approver = $this->userWith(['decide_loan_application']);
        $application = $this->makeApplication(LoanApplication::STATUS_IN_REVIEW, $this->makeClient());

        $this->postJson("/api/loan-applications/{$application->id}/decide", [
            'status' => 'rejected',
        ], $this->authHeaders($approver))->assertStatus(422)->assertJsonValidationErrors(['rejected_reason']);

        $this->postJson("/api/loan-applications/{$application->id}/decide", [
            'status'          => 'rejected',
            'rejected_reason' => 'Collateral overvalued by applicant.',
        ], $this->authHeaders($approver))->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(LoanApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Collateral overvalued by applicant.', $fresh->rejected_reason);
    }

    public function test_cannot_decide_an_already_decided_application(): void
    {
        $approver = $this->userWith(['decide_loan_application']);
        $application = $this->makeApplication(LoanApplication::STATUS_REJECTED, $this->makeClient());

        $this->postJson("/api/loan-applications/{$application->id}/decide", [
            'status'          => 'rejected',
            'rejected_reason' => 'again',
        ], $this->authHeaders($approver))->assertStatus(409);
    }
}
