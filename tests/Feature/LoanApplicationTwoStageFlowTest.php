<?php

namespace Tests\Feature;

use App\Models\LoanApplication;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

/** Collateral review -> loan review -> approved -> contract created from it, plus admin edit/delete and typed files. */
class LoanApplicationTwoStageFlowTest extends TestCase
{
    use DatabaseTransactions, LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
        Storage::fake('public');
    }

    private function admin(array $extra = ['create_contract'])
    {
        return $this->userWith(array_merge([
            'create_loan_application', 'view_loan_applications', 'estimate_loan_application_collateral',
            'decide_loan_application', 'edit_loan_application',
            'delete_loan_application', 'finalize_loan_application_estimate',
        ], $extra));
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

    /** The New Contract wizard's submit, completing the missing client details and contract terms. */
    private function contractPayload(array $over = []): array
    {
        return array_merge([
            'type'               => 'individual',
            'name'               => 'Test',
            'surname'            => 'Applicant',
            'phone'              => '+37411000000',
            'gender'             => 'MALE',
            'document_type'      => 'ID_CARD',
            'passport_series'    => 'AN7654321',
            'passport_validity'  => '2030-01-01',
            'passport_issued'    => '2020-01-01 police',
            'date_of_birth'      => '1990-01-01',
            'residency_status'   => 'resident',
            'estimated_amount'   => 520000,
            'provided_amount'    => 750000,
            'deadline'           => 12,
            'interest_rate'      => 24,
            'penalty'            => 0.1,
            'lump_rate'          => 0,
            'payment_type'       => 'classic',
            'category_id'        => $this->category->id,
            'items'              => [[
                'category_id' => $this->category->id, 'subcategory' => 'Ring',
                'weight' => 10, 'clear_weight' => 9, 'hallmark' => '585',
            ]],
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

    public function test_property_application_requires_ownership_certificate_and_photo(): void
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
        $this->post('/api/loan-applications', $payload, $this->authHeaders($admin) + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('files');

        $payload['files'][] = UploadedFile::fake()->image('house.jpg');
        $payload['file_types'][] = 'photo';
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

        $other = $this->admin();
        $this->postJson("/api/loan-applications/{$app->id}/estimates", ['estimated_amount' => 600000], $this->authHeaders($other))->assertOk();

        // The average is shown as a hint only.
        $this->assertEquals(550000, $this->getJson("/api/loan-applications/{$app->id}", $h)->json('data.estimates_average'));

        // Cannot decide before the estimate is finalized.
        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'approved', 'provided_amount' => 1], $h)->assertStatus(409);

        // The admin settles on their own figure, not necessarily the average or any estimate.
        $this->postJson("/api/loan-applications/{$app->id}/finalize-estimate", [], $h)->assertStatus(422);
        $this->postJson("/api/loan-applications/{$app->id}/finalize-estimate", ['final_amount' => 520000], $h)->assertOk();
        $this->assertSame('loan_review', $app->fresh()->status);
        $this->assertEquals(520000, $app->fresh()->final_estimated_amount);

        // Locked: no new estimates and no second finalize.
        $third = $this->admin();
        $this->postJson("/api/loan-applications/{$app->id}/estimates", ['estimated_amount' => 1], $this->authHeaders($third))->assertStatus(409);
        $this->postJson("/api/loan-applications/{$app->id}/finalize-estimate", ['final_amount' => 1], $h)->assertStatus(409);

        // Stage 2: approval needs an amount and the signed ԱՔՌԱ consent; the amount may exceed the estimate.
        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'approved'], $h)->assertStatus(422);
        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'approved', 'provided_amount' => 750000], $h)->assertStatus(422);

        $upload = $this->post("/api/loan-applications/{$app->id}/files", [
            'files' => [UploadedFile::fake()->create('consent.zip', 10, 'application/zip')], 'file_types' => ['acra_consent'],
        ], $h + ['Accept' => 'application/json']);
        $upload->assertCreated();

        $this->postJson("/api/loan-applications/{$app->id}/decide", ['status' => 'approved', 'provided_amount' => 750000], $h)->assertOk();
        $this->assertSame('approved', $app->fresh()->status);

        // The wizard completes the missing client details and contract terms; it must
        // complete the application's own client instead of creating a duplicate.
        $clientsBefore = \App\Models\Client::count();
        $res = $this->postJson('/api/contracts', $this->contractPayload(['loan_application_id' => $app->id]), $h);
        $res->assertCreated();

        $app->refresh();
        $this->assertSame('converted', $app->status);
        $this->assertSame($res->json('contract_id'), $app->contract_id);
        $this->assertSame($clientsBefore, \App\Models\Client::count());

        $contract = \App\Models\Contract::findOrFail($app->contract_id);
        $this->assertSame($app->client_id, $contract->client_id);
        $this->assertSame('AN7654321', \App\Models\Client::find($app->client_id)->passport_series);
        $this->assertEquals(750000, $contract->provided_amount);
        $this->assertEquals(520000, $contract->estimated_amount);
    }

    public function test_contract_can_only_be_created_from_an_approved_application(): void
    {
        $admin = $this->admin(['create_contract']);
        $h = $this->authHeaders($admin);

        $notApproved = $this->makeApplication('loan_review', $this->makeClient());
        $this->postJson('/api/contracts', $this->contractPayload(['loan_application_id' => $notApproved->id]), $h)->assertStatus(409);
        $this->assertSame('loan_review', $notApproved->fresh()->status);

        $this->postJson('/api/contracts', $this->contractPayload(['loan_application_id' => 99999999]), $h)->assertStatus(422);
    }

    public function test_loan_review_fields_are_validated_and_cleared_when_branch_is_no(): void
    {
        $admin = $this->admin();
        $app = $this->makeApplication('loan_review', $this->makeClient());
        $h = $this->authHeaders($admin);
        $url = "/api/loan-applications/{$app->id}/loan-review";

        $this->putJson($url, [], $h)->assertStatus(422)->assertJsonValidationErrors(['is_employed', 'owns_property']);
        $this->putJson($url, ['is_employed' => true, 'owns_property' => true], $h)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employer_name', 'employer_position', 'employment_tenure_months', 'employment_salary', 'owned_property_info']);

        $this->putJson($url, [
            'is_employed' => true, 'employer_name' => 'ACME', 'employer_position' => 'Clerk',
            'employment_tenure_months' => 18, 'employment_salary' => 300000,
            'owns_property' => true, 'owned_property_info' => '11AA111',
            'loan_purpose' => 'Repairs', 'requested_amount' => 400000, 'acra_score' => 'B+',
        ], $h)->assertOk();
        $this->assertSame('ACME', $app->fresh()->employer_name);

        $this->putJson($url, ['is_employed' => false, 'owns_property' => false, 'employer_name' => 'stale'], $h)->assertOk();
        $fresh = $app->fresh();
        $this->assertNull($fresh->employer_name);
        $this->assertNull($fresh->employment_salary);
        $this->assertNull($fresh->owned_property_info);

        $collateral = $this->makeApplication('collateral_review', $this->makeClient());
        $this->putJson("/api/loan-applications/{$collateral->id}/loan-review", ['is_employed' => false, 'owns_property' => false], $h)->assertStatus(409);
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

    public function test_edit_updates_client_loan_review_fields_and_clears_optional_ones(): void
    {
        $admin = $this->admin();
        $client = $this->makeClient();
        $app = $this->makeApplication('loan_review', $client);
        $item = $app->items()->first();
        $item->update(['model' => 'Old model']);
        $h = $this->authHeaders($admin);

        $this->putJson("/api/loan-applications/{$app->id}", [
            'client' => ['name' => 'Anna', 'surname' => 'Petrosyan', 'phone' => '091123456'],
            'is_employed' => true, 'employer_name' => 'Acme', 'employer_position' => 'Dev',
            'employment_tenure_months' => 12, 'employment_salary' => 300000,
            'owns_property' => false, 'loan_purpose' => 'Repairs', 'requested_amount' => 500000, 'acra_score' => 'A',
            'items' => [[
                'id' => $item->id, 'category_id' => $this->category->id, 'subcategory' => 'Chain',
                'weight' => 20, 'hallmark' => '750', 'model' => null,
            ]],
        ], $h)->assertOk();

        $app = $app->fresh();
        $this->assertSame('Anna', $client->fresh()->name);
        $this->assertSame('Acme', $app->employer_name);
        $this->assertSame('Repairs', $app->loan_purpose);
        $this->assertNull($item->fresh()->model);

        $this->putJson("/api/loan-applications/{$app->id}", ['is_employed' => false], $h)->assertOk();
        $this->assertNull($app->fresh()->employer_name);
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

        $this->post("/api/loan-applications/{$app->id}/files", [
            'files' => [UploadedFile::fake()->create('consent.pdf', 10)], 'file_types' => ['acra_consent'],
        ], $h)->assertCreated();

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
