<?php

namespace Tests\Feature;

use Tests\TestCase;

class TAccountReportTest extends TestCase
{
    protected function getUrl(array $params = []): string
    {
        $query = http_build_query($params);

        return '/api/admin/reports/t-account' . ($query ? '?' . $query : '');
    }

    public function test_missing_account_returns_400(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getUrl([
            'startDate' => '2025-01-01',
            'endDate'   => '2025-12-31',
        ]));

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'accountId or accountCode is required']);
    }

    public function test_unknown_account_returns_404(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getUrl([
            'startDate'     => '2025-01-01',
            'endDate'       => '2025-12-31',
            'accountCode'   => 'nonexistent-account-xyz',
        ]));

        $response->assertStatus(404);
    }

    public function test_valid_request_returns_expected_shape(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getUrl([
            'startDate'   => '2025-01-01',
            'endDate'     => '2025-12-31',
            'accountCode' => '1',
        ]));

        if ($response->status() === 404) {
            $this->markTestSkipped('No chart account with code 1 in test DB');
        }

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'account' => ['id', 'code', 'name'],
            'startDate',
            'endDate',
            'openingBalance' => ['debit', 'credit'],
            'rows',
            'turnover' => ['debit', 'credit'],
            'closingBalance' => ['debit', 'credit'],
        ]);
    }
}
