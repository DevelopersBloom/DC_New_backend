<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoanApplicationDecisionRequest;
use App\Http\Requests\LoanApplicationEstimateRequest;
use App\Http\Requests\LoanApplicationStoreRequest;
use App\Models\Client;
use App\Models\File;
use App\Models\LoanApplication;
use App\Models\LoanApplicationEstimate;
use App\Services\ClientService;
use App\Services\LoanApplicationConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanApplicationController extends Controller
{
    public function __construct(
        private ClientService $clientService,
        private LoanApplicationConversionService $conversionService,
    ) {
    }


    public function store(LoanApplicationStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pawnshopId = Auth::user()->pawnshop_id ?? 1;

        if (!empty($data['client_id'])) {
            $client = Client::findOrFail($data['client_id']);
        } else {
            $newClient = $data['new_client'];

            if (!$request->boolean('force')) {
                $candidates = $this->clientService->search(
                    $newClient['name'] ?? null,
                    $newClient['surname'] ?? null,
                );

                if ($candidates->isNotEmpty()) {
                    return response()->json([
                        'message'    => 'A client with a similar name already exists. Resubmit with force=true to create anyway, or pass client_id.',
                        'candidates' => $candidates->map(fn (Client $c) => [
                            'id'      => $c->id,
                            'name'    => $c->name,
                            'surname' => $c->surname,
                            'phone'   => $c->phone,
                        ])->values(),
                    ], 409);
                }
            }

            $client = $this->clientService->createNonClient($newClient);
        }

        $application = DB::transaction(function () use ($data, $client, $pawnshopId) {
            /** @var LoanApplication $application */
            $application = LoanApplication::create([
                'client_id'   => $client->id,
                'loan_type'   => $data['loan_type'],
                'status'      => LoanApplication::STATUS_SUBMITTED,
                'comments'    => $data['comments'] ?? null,
                'pawnshop_id' => $pawnshopId,
                'created_by'  => Auth::id(),
            ]);

            foreach ($data['items'] as $itemData) {
                $item = $application->items()->create([
                    'category_id'    => $itemData['category_id'],
                    'subcategory'    => $itemData['subcategory'] ?? null,
                    'model'          => $itemData['model'] ?? null,
                    'weight'         => $itemData['weight'] ?? null,
                    'clear_weight'   => $itemData['clear_weight'] ?? null,
                    'hallmark'       => $itemData['hallmark'] ?? null,
                    'car_make'       => $itemData['car_make'] ?? null,
                    'manufacture'    => $itemData['manufacture'] ?? null,
                    'power'          => $itemData['power'] ?? null,
                    'license_plate'  => $itemData['license_plate'] ?? null,
                    'color'          => $itemData['color'] ?? null,
                    'registration'   => $itemData['registration'] ?? null,
                    'identification' => $itemData['identification'] ?? null,
                    'ownership'      => $itemData['ownership'] ?? null,
                    'description'    => $itemData['description'] ?? null,
                ]);

                if ($data['loan_type'] === 'property' && !empty($itemData['real_estate'])) {
                    $realEstate = $itemData['real_estate'];
                    $item->realEstate()->create([
                        'certificate_number'   => $realEstate['certificate_number'] ?? null,
                        'certificate_password' => $realEstate['certificate_password'] ?? null,
                        'cadastral_code'       => $realEstate['cadastral_code'] ?? null,
                        'area_sqm'             => $realEstate['area_sqm'] ?? null,
                        'is_joint'             => $realEstate['is_joint'] ?? false,
                    ]);
                }
            }

            $this->attachFiles($application, $data);

            return $application;
        });

        return response()->json([
            'message' => 'Loan application submitted',
            'data'    => $application->load(['client', 'items.realEstate', 'files']),
        ], 201);
    }


    public function index(Request $request): JsonResponse
    {
        $applications = LoanApplication::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('loan_type'), fn ($q, $type) => $q->where('loan_type', $type))
            ->when(
                $request->query('pawnshop_id'),
                fn ($q, $pawnshopId) => $q->where('pawnshop_id', $pawnshopId)
            )
            ->with(['client', 'items', 'estimates.user'])
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json(['data' => $applications]);
    }


    public function show(Request $request, int $id): JsonResponse
    {
        $application = LoanApplication::with([
            'client',
            'items.realEstate',
            'estimates.user',
            'estimates.currency',
            'finalEstimate',
            'approver',
            'creator',
            'contract',
            'files',
        ])->findOrFail($id);

        if (!$request->user()->can('view_loan_application_admin_files')) {
            $application->setRelation(
                'files',
                $application->files->where('visibility', '!=', 'admin_only')->values()
            );
        }

        return response()->json(['data' => $application]);
    }


    public function storeEstimate(LoanApplicationEstimateRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->isDecided()) {
            return response()->json([
                'message' => 'This application is already ' . $application->status . '.',
            ], 409);
        }

        $data = $request->validated();
        $userId = Auth::id();

        $estimate = DB::transaction(function () use ($application, $data, $userId) {
            /** @var LoanApplicationEstimate|null $existing */
            $existing = $application->estimates()->where('user_id', $userId)->first();

            if ($existing) {
                $history = $existing->history ?? [];
                $history[] = [
                    'estimated_amount' => $existing->estimated_amount,
                    'currency_id'      => $existing->currency_id,
                    'note'             => $existing->note,
                    'changed_at'       => now()->toDateTimeString(),
                ];

                $existing->update([
                    'estimated_amount' => $data['estimated_amount'],
                    'currency_id'      => $data['currency_id'] ?? $existing->currency_id,
                    'note'             => $data['note'] ?? null,
                    'history'          => $history,
                ]);

                $estimate = $existing;
            } else {
                $estimate = $application->estimates()->create([
                    'user_id'          => $userId,
                    'estimated_amount' => $data['estimated_amount'],
                    'currency_id'      => $data['currency_id'] ?? null,
                    'note'             => $data['note'] ?? null,
                ]);
            }

            // First estimate for an application moves it into review.
            if ($application->status === LoanApplication::STATUS_SUBMITTED) {
                $application->update(['status' => LoanApplication::STATUS_IN_REVIEW]);
            }

            return $estimate;
        });

        return response()->json([
            'message' => 'Estimate saved',
            'data'    => $estimate->fresh('user'),
        ]);
    }


    public function decide(LoanApplicationDecisionRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->isDecided()) {
            return response()->json([
                'message' => 'This application is already ' . $application->status . '.',
            ], 409);
        }

        $data = $request->validated();

        if ($data['status'] === LoanApplication::STATUS_APPROVED) {
            $belongs = $application->estimates()
                ->whereKey($data['final_estimate_id'])
                ->exists();

            if (!$belongs) {
                return response()->json([
                    'message' => 'The chosen final estimate does not belong to this application.',
                ], 422);
            }
        }

        DB::transaction(function () use ($application, $data) {
            if ($data['status'] === LoanApplication::STATUS_APPROVED) {
                $application->update([
                    'status'            => LoanApplication::STATUS_APPROVED,
                    'final_estimate_id' => $data['final_estimate_id'],
                    'approved_by'       => Auth::id(),
                    'approved_at'       => now(),
                    'rejected_reason'   => null,
                ]);
            } else {
                $application->update([
                    'status'          => LoanApplication::STATUS_REJECTED,
                    'approved_by'     => Auth::id(),
                    'approved_at'     => now(),
                    'rejected_reason' => $data['rejected_reason'],
                ]);
            }
        });

        return response()->json([
            'message' => 'Application ' . $application->status,
            'data'    => $application->fresh(['finalEstimate', 'approver']),
        ]);
    }


    public function convert(int $id): JsonResponse
    {
        $application = LoanApplication::with('client')->findOrFail($id);

        if ($application->status !== LoanApplication::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Only approved applications can be converted.',
            ], 409);
        }

        $missing = $this->conversionService->profileIsComplete($application->client);
        if ($missing !== []) {
            return response()->json([
                'message'        => 'Client profile must be completed before conversion.',
                'missing_fields' => $missing,
            ], 422);
        }

        $contract = $this->conversionService->convert($application);

        return response()->json([
            'message' => 'Application converted',
            'data'    => [
                'contract_id' => $contract->id,
                'prefill'     => [
                    'client_id'        => $contract->client_id,
                    'loan_type'        => $contract->loan_type,
                    'estimated_amount' => $contract->estimated_amount,
                    'items'            => $contract->items()->with('realEstate')->get(),
                ],
            ],
        ]);
    }


    private function attachFiles(LoanApplication $application, array $data): void
    {
        if (empty($data['files'])) {
            return;
        }

        $visibilities = $data['file_visibilities'] ?? [];

        foreach ($data['files'] as $index => $uploadedFile) {
            if (!$uploadedFile || !$uploadedFile->isValid()) {
                continue;
            }

            $storedName = Str::uuid() . '.' . $uploadedFile->getClientOriginalExtension();
            $path = $uploadedFile->storeAs('files', $storedName, 'public');

            $application->files()->create([
                'file_type'     => $uploadedFile->getClientMimeType(),
                'client_id'     => $application->client_id,
                'name'          => $uploadedFile->getClientOriginalName(),
                'original_name' => $uploadedFile->getClientOriginalName(),
                'type'          => $uploadedFile->getClientOriginalExtension(),
                'doc_type'      => 'regular',
                'visibility'    => ($visibilities[$index] ?? 'public') === 'admin_only' ? 'admin_only' : 'public',
                'path'          => $path,
            ]);
        }
    }
}
