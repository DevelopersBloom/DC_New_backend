<?php

namespace Tests\Feature;

use App\Models\LoanApplication;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

/** Collateral review -> loan review -> approved -> converted, plus admin edit/delete and typed files. */
class LoanApplicationTwoStageFlowTest extends TestCase
{
    use DatabaseTransactions, LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
        Storage::fake('public');
    }

    private function admin()
    {
        return $this->userWith([
            'create_loan_application', 'view_loan_applications', 'estimate_loan_application_collateral',
            'decide_loan_application', 'convert_loan_application', 'edit_loan_application',
            'delete_loan_application', 'finalize_loan_application_estimate',
        ]);
    }

    private function carPayload(array $over = []): array
    {
        return array_merge([
            'client_id' => $this->makeClient()->id,
            'loan_type' => 'car',
            'items'     => [[
                'category_id' => $this->category->id, 'car_make' => 'BMW',
                'manufacture' => 2015, 'license_plate' => '11AA111',
            ]],
            'files'      => [UploadedFile::fake()->create('tp.pdf', 10), UploadedFile::fake()->image('front.jpg')],
            'file_types' => ['tech_passport', 'photo'],
            'file_titles' => ['Tech passport', 'Front'],
        ], $over);
    }

    public function test_car_application_requires_tech_passport_and_photo(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/loan-applications', $this->carPayload(['files' => [], 'file_types' => []]), $this->authHeaders($admin))
            ->assertStatus(422)->assertJsonValidationErrors('files');

        $onlyPhoto = $this->carPayload([
            'files' => [UploadedFile::fake()->image('a.jpg')], 'file_types' => ['photo'], 'file_titles' => ['A'],
        ]);
        $this->postJson('/api/loan-applications', $onlyPhoto, $this->authHeaders($admin))
            ->assertStatus(422)->assertJsonValidationErrors('files');

        $res = $this->post('/api/loan-applications', $this->carPayload(), $this->authHeaders($admin) + ['Accept' => 'application/json']);
        $res->assertCreated();
        $this->assertSame(['photo', 'tech_passport'], collect($res->json('data.files'))->pluck('doc_type')->sort()->values()->all());
        $this->assertContains('Tech passport', collect($res->json('data.files'))->pluck('title')->all());
    }

    public function test_property_application_requires_ownership_certificate(): void
    {
        $admin = $this->admin();
        $payload = [
            'client_id' => $this->makeClient()->id, 'loan_type' => 'property',
            'items' => [['category_id' => $this->category->id, 'real_estate' => ['cadastral_code' => '01-001']]],
        ];

        $this->postJson('/api/loan-applications', $payload, $this->authHeaders($admin))
            ->assertStatus(422)->assertJsonValidationErrors('files');

        $payload['files'] = [UploadedFile::fake()->create('cert.pdf', 10)];
        $payload['file_types'] = ['ownership_certificate'];
        $this->post('/api/loan-applications', $payload, $this->authHeaders($admin) + ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_full_flow_with_provided_amount_above_estimate(): void
    {
        $admin = $this->admin();
        $app = $this->makeApplication('submitted', $this->makeFullClient());
        $h = $this->authHeaders($admin);

        // Stage 1: first estimate moves it into collateral review; estimates are immutable.
        $this->postJson("/api/loan-applications/{$app->id}/estimates", ['estimated_amount' => 500000], $h)->assertOk();
        $this->assertSame('collateral_review', $app->fresh()->status);
        $this->postJson("/api/loan-applications/{$app->id}/estimates", ['estimated_amount' => 600000], $h)->assertStatus(409);

        // Cannot decide before the estimate is finalized.
        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'approved', 'provided_amount' => 1], $h)->assertStatus(409);

        $estimate = $app->estimates()->first();
        $this->postJson("/api/loan-applications/{$app->id}/finalize-estimate", ['final_estimate_id' => $estimate->id], $h)->assertOk();
        $this->assertSame('loan_review', $app->fresh()->status);

        // Locked: no new estimates and no second finalize.
        $other = $this->admin();
        $this->postJson("/api/loan-applications/{$app->id}/estimates", ['estimated_amount' => 1], $this->authHeaders($other))->assertStatus(409);
        $this->postJson("/api/loan-applications/{$app->id}/finalize-estimate", ['final_estimate_id' => $estimate->id], $h)->assertStatus(409);

        // Stage 2: approval needs an amount, and it may exceed the estimate.
        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'approved'], $h)->assertStatus(422);
        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'approved', 'provided_amount' => 750000], $h)->assertOk();
        $this->assertSame('approved', $app->fresh()->status);

        // Convert seeds the contract from the provided amount.
        $res = $this->postJson("/api/loan-applications/{$app->id}/convert", [], $h)->assertOk();
        $contract = \App\Models\Contract::findOrFail($res->json('data.contract_id'));
        $this->assertEquals(750000, $contract->provided_amount);
        $this->assertEquals(500000, $contract->estimated_amount);
    }

    public function test_legacy_approved_application_converts_from_final_estimate(): void
    {
        $admin = $this->admin();
        $app = $this->makeApplication('approved', $this->makeFullClient());
        $estimate = $this->estimateFor($app, $admin, 300000);
        $app->update(['final_estimate_id' => $estimate->id]);

        $res = $this->postJson("/api/loan-applications/{$app->id}/convert", [], $this->authHeaders($admin))->assertOk();
        $this->assertEquals(300000, \App\Models\Contract::find($res->json('data.contract_id'))->provided_amount);
    }

    public function test_reject_requires_reason_and_only_in_loan_review(): void
    {
        $admin = $this->admin();
        $app = $this->makeApplication('loan_review', $this->makeClient());
        $h = $this->authHeaders($admin);

        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'rejected'], $h)->assertStatus(422);
        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'rejected', 'rejected_reason' => 'bad ACRA'], $h)->assertOk();
        $this->assertSame('rejected', $app->fresh()->status);
    }

    public function test_edit_and_delete_are_admin_only_and_never_touch_estimates(): void
    {
        $admin = $this->admin();
        $manager = $this->userWith(['view_loan_applications', 'estimate_loan_application_collateral']);
        $app = $this->makeApplication('collateral_review', $this->makeClient());
        $estimate = $this->estimateFor($app, $admin, 100000);
        $item = $app->items()->first();
        $edit = ['comments' => 'updated', 'items' => [[
            'id' => $item->id, 'category_id' => $this->category->id, 'subcategory' => 'Chain', 'weight' => 20, 'hallmark' => '750',
        ]]];

        $this->putJson("/api/loan-applications/{$app->id}", $edit, $this->authHeaders($manager))->assertStatus(403);
        $this->deleteJson("/api/loan-applications/{$app->id}", [], $this->authHeaders($manager))->assertStatus(403);

        $this->putJson("/api/loan-applications/{$app->id}", $edit, $this->authHeaders($admin))->assertOk();
        $this->assertSame('Chain', $item->fresh()->subcategory);
        $this->assertSame('updated', $app->fresh()->comments);
        $this->assertEquals(100000, $estimate->fresh()->estimated_amount);

        $this->deleteJson("/api/loan-applications/{$app->id}", [], $this->authHeaders($admin))->assertOk();
        $this->assertSoftDeleted('loan_applications', ['id' => $app->id]);
    }

    public function test_converted_application_cannot_be_edited_or_deleted(): void
    {
        $admin = $this->admin();
        $app = $this->makeApplication('converted', $this->makeClient());
        $h = $this->authHeaders($admin);

        $this->putJson("/api/loan-applications/{$app->id}", ['comments' => 'x'], $h)->assertStatus(409);
        $this->deleteJson("/api/loan-applications/{$app->id}", [], $h)->assertStatus(409);
    }

    public function test_acra_upload_rules_in_loan_review(): void
    {
        $decider = $this->userWith(['view_loan_applications', 'decide_loan_application']);
        $app = $this->makeApplication('loan_review', $this->makeClient());
        $h = $this->authHeaders($decider) + ['Accept' => 'application/json'];

        $this->post("/api/loan-applications/{$app->id}/files", [
            'files' => [UploadedFile::fake()->create('acra.pdf', 10)], 'file_types' => ['acra'], 'file_titles' => ['ACRA report'],
        ], $h)->assertCreated();
        $this->assertDatabaseHas('files', ['fileable_id' => $app->id, 'doc_type' => 'acra', 'title' => 'ACRA report']);

        // A decider without edit permission may not add other document types.
        $this->post("/api/loan-applications/{$app->id}/files", [
            'files' => [UploadedFile::fake()->create('x.pdf', 10)], 'file_types' => ['other'],
        ], $h)->assertStatus(403);
    }

    public function test_admin_can_delete_a_file(): void
    {
        $admin = $this->admin();
        $app = $this->makeApplication('submitted', $this->makeClient());
        $h = $this->authHeaders($admin) + ['Accept' => 'application/json'];

        $res = $this->post("/api/loan-applications/{$app->id}/files", [
            'files' => [UploadedFile::fake()->create('a.pdf', 10)], 'file_types' => ['other'],
        ], $h)->assertCreated();
        $fileId = $res->json('data.0.id');

        $this->deleteJson("/api/loan-applications/{$app->id}/files/{$fileId}", [], $h)->assertOk();
        $this->assertDatabaseMissing('files', ['id' => $fileId]);
    }
}
