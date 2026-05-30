<?php

namespace App\Services;

class DealUpdatePreviewService
{
    public function __construct(
        private readonly DealUpdateService $dealUpdateService
    ) {
    }

    public function preview(int $dealId, array $proposed): array
    {
        return $this->dealUpdateService->previewUpdate($dealId, $proposed);
    }

    /**
     * @param  list<array<string, mixed>>  $deals
     */
    public function previewMany(array $deals): array
    {
        $previews = [];
        foreach ($deals as $dealData) {
            $dealId = (int) ($dealData['id'] ?? 0);
            if ($dealId <= 0) {
                continue;
            }
            $previews[] = $this->preview($dealId, $dealData);
        }

        return ['previews' => $previews];
    }
}
