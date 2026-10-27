<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

class ClientFicoScoreTest extends TestCase
{
    use DatabaseTransactions, LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
    }

    public function test_sets_score_and_show_returns_it(): void
    {
        $h = $this->authHeaders($this->userWith(['update_client', 'view_clients']));
        $client = $this->makeClient();

        $this->putJson("/api/clients/{$client->id}/fico-score", ['fico_score' => 712, 'fico_score_date' => '2026-10-01'], $h)
            ->assertOk()
            ->assertJson(['fico_score' => 712, 'fico_score_date' => '2026-10-01']);

        $this->getJson("/api/clients/{$client->id}", $h)
            ->assertOk()
            ->assertJsonPath('client.fico_score', 712)
            ->assertJsonPath('client.fico_score_date', '2026-10-01');
    }

    public function test_date_defaults_to_today_and_null_score_clears(): void
    {
        $h = $this->authHeaders($this->userWith(['update_client']));
        $client = $this->makeClient();

        $this->putJson("/api/clients/{$client->id}/fico-score", ['fico_score' => 640], $h)->assertOk();
        $this->assertSame(now()->toDateString(), $client->fresh()->fico_score_date->toDateString());

        $this->putJson("/api/clients/{$client->id}/fico-score", ['fico_score' => null], $h)->assertOk();
        $fresh = $client->fresh();
        $this->assertNull($fresh->fico_score);
        $this->assertNull($fresh->fico_score_date);
    }

    public function test_rejects_out_of_range_and_future_date(): void
    {
        $h = $this->authHeaders($this->userWith(['update_client']));
        $client = $this->makeClient();
        $url = "/api/clients/{$client->id}/fico-score";

        $this->putJson($url, ['fico_score' => 299], $h)->assertStatus(422);
        $this->putJson($url, ['fico_score' => 851], $h)->assertStatus(422);
        $this->putJson($url, ['fico_score' => 700, 'fico_score_date' => now()->addDay()->toDateString()], $h)->assertStatus(422);
    }

    public function test_requires_update_client_permission(): void
    {
        $h = $this->authHeaders($this->userWith(['view_clients']));
        $client = $this->makeClient();

        $this->putJson("/api/clients/{$client->id}/fico-score", ['fico_score' => 700], $h)->assertForbidden();
    }
}
