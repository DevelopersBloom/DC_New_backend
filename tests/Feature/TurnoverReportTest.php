<?php

namespace Tests\Feature;

use Tests\TestCase;

class TurnoverReportTest extends TestCase
{
    /**
     * Turnover endpoint is at GET /api/admin/reports/turnover (admin + reports prefix).
     * We bypass middleware to test controller validation and response shape.
     */
    protected function getTurnoverUrl(array $params = []): string
    {
        $query = http_build_query($params);

        return '/api/admin/reports/turnover' . ($query ? '?' . $query : '');
    }

    public function test_valid_request_returns_200_and_json_array(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getTurnoverUrl([
            'startDate' => '2025-01-01',
            'endDate'   => '2025-12-31',
        ]));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertNotNull($data);
        // Each item must have the required shape if any
        foreach ($data as $row) {
            $this->assertArrayHasKey('code', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('openingDebit', $row);
            $this->assertArrayHasKey('openingCredit', $row);
            $this->assertArrayHasKey('periodDebit', $row);
            $this->assertArrayHasKey('periodCredit', $row);
            $this->assertArrayHasKey('closingDebit', $row);
            $this->assertArrayHasKey('closingCredit', $row);
        }
    }

    public function test_missing_start_date_returns_400(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getTurnoverUrl(['endDate' => '2025-12-31']));

        $response->assertStatus(400);
        $response->assertJsonStructure(['error']);
        $response->assertJsonFragment(['error' => 'startDate is required']);
    }

    public function test_missing_end_date_returns_400(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getTurnoverUrl(['startDate' => '2025-01-01']));

        $response->assertStatus(400);
        $response->assertJsonStructure(['error']);
        $response->assertJsonFragment(['error' => 'endDate is required']);
    }

    public function test_invalid_date_format_returns_400(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getTurnoverUrl([
            'startDate' => '01-01-2025',
            'endDate'   => '31-12-2025',
        ]));

        $response->assertStatus(400);
        $response->assertJsonStructure(['error']);
    }

    public function test_start_date_after_end_date_returns_400_with_expected_message(): void
    {
        $this->withoutMiddleware();

        $response = $this->getJson($this->getTurnoverUrl([
            'startDate' => '2025-12-31',
            'endDate'   => '2025-01-01',
        ]));

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'startDate must be before or equal to endDate']);
    }
}
