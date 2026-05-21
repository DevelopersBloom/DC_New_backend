<?php

namespace Tests\Feature;

use Tests\TestCase;

class TPartnerReportTest extends TestCase
{
    protected function getUrl(array $params = []): string
    {
        $query = http_build_query($params);

        return '/api/admin/reports/t-partner' . ($query ? '?' . $query : '');
    }

    public function test_missing_partner_returns_400(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getUrl([
            'startDate' => '2025-01-01',
            'endDate'   => '2025-12-31',
        ]));

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'partnerId is required']);
    }

    public function test_unknown_partner_returns_404(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getUrl([
            'startDate'  => '2025-01-01',
            'endDate'    => '2025-12-31',
            'partnerId'  => 999999999,
        ]));

        $response->assertStatus(404);
    }
}
