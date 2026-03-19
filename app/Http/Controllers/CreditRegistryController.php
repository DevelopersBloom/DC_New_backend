<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\CreditRegistryL001Service;
use App\Services\CreditRegistryL002Service;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditRegistryController extends Controller
{
    public function __construct(
        private CreditRegistryL001Service $l001Service,
        private CreditRegistryL002Service $l002Service
    ) {
    }

    /**
     * Generate and download L001 XML for a single contract (Credit Registry).
     */
    public function downloadL001(string $id): StreamedResponse|Response
    {
        $contract = Contract::find($id);

        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $xml = $this->l001Service->generateL001Xml($contract);
        $filename = 'L001_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            function () use ($xml) {
                echo $xml;
            },
            $filename,
            [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Generate and download L002 XML for a single contract (Credit Registry loan modification).
     */
    public function downloadL002(string $id): StreamedResponse|Response
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
dd(3);
        $xml = $this->l002Service->generateL002Xml((int) $contract->id);
        $filename = 'L002_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            function () use ($xml) {
                echo $xml;
            },
            $filename,
            [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }
}
