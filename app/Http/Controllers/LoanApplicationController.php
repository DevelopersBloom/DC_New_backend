<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoanApplicationDecisionRequest;
use App\Http\Requests\LoanApplicationEstimateRequest;
use App\Http\Requests\LoanApplicationFileRequest;
use App\Http\Requests\LoanApplicationFinalizeEstimateRequest;
use App\Http\Requests\LoanApplicationLoanReviewRequest;
use App\Http\Requests\LoanApplicationStoreRequest;
use App\Http\Requests\LoanApplicationUpdateRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\File;
use App\Models\LoanApplication;
use App\Models\LoanApplicationEstimate;
use App\Services\ClientService;
use App\Support\LoanApplicationDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LoanApplicationController extends Controller
{
    public function __construct(
        private ClientService $clientService,
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
                'client_age'    => $data['client_age'] ?? null,
                'client_income' => $data['client_income'] ?? null,
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

            $this->attachFiles(
                $application,
                $data['files'] ?? [],
                $data['file_types'] ?? [],
                $data['file_titles'] ?? [],
                $data['file_visibilities'] ?? [],
            );

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
            'providedCurrency',
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

        // Same shape the New Contract wizard's client search returns, so the wizard
        // can prefill (and the user complete) the client's profile.
        $data = $application->toArray();
        $data['client_profile'] = $application->client
            ? (new ClientResource($application->client))->resolve()
            : null;

        return response()->json(['data' => $data]);
    }


    public function storeEstimate(LoanApplicationEstimateRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->isEstimateFinalized() || $application->isDecided()
            || $application->status === LoanApplication::STATUS_LOAN_REVIEW) {
            return response()->json([
                'message' => 'The collateral estimation is already finished.',
            ], 409);
        }

        $data = $request->validated();
        $userId = Auth::id();

        // Estimates are not editable: one per user, final once submitted.
        if ($application->estimates()->where('user_id', $userId)->exists()) {
            return response()->json([
                'message' => 'You have already submitted an estimate for this application.',
            ], 409);
        }

        $estimate = DB::transaction(function () use ($application, $data, $userId) {
            $estimate = $application->estimates()->create([
                'user_id'          => $userId,
                'estimated_amount' => $data['estimated_amount'],
                'currency_id'      => $data['currency_id'] ?? null,
                'note'             => $data['note'] ?? null,
            ]);

            // First estimate moves the application into collateral review.
            if ($application->status === LoanApplication::STATUS_SUBMITTED) {
                $application->update(['status' => LoanApplication::STATUS_COLLATERAL_REVIEW]);
            }

            return $estimate;
        });

        return response()->json([
            'message' => 'Estimate saved',
            'data'    => $estimate->fresh('user'),
        ]);
    }


    /**
     * Ends the collateral stage. The admin settles on the final collateral amount
     * (the participants' average is only a hint), which locks the estimates and
     * moves the application into loan review.
     */
    public function finalizeEstimate(LoanApplicationFinalizeEstimateRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->status !== LoanApplication::STATUS_COLLATERAL_REVIEW) {
            return response()->json([
                'message' => 'Only an application under collateral review can be finalized.',
            ], 409);
        }

        $data = $request->validated();
        $estimateId = $data['final_estimate_id'] ?? null;

        if ($estimateId !== null && !$application->estimates()->whereKey($estimateId)->exists()) {
            return response()->json([
                'message' => 'The chosen final estimate does not belong to this application.',
            ], 422);
        }

        $application->update([
            'status'                 => LoanApplication::STATUS_LOAN_REVIEW,
            'final_estimate_id'      => $estimateId,
            'final_estimated_amount' => $data['final_amount'],
            'estimate_finalized_at'  => now(),
            'estimate_finalized_by'  => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Collateral estimation finished',
            'data'    => $application->fresh(['finalEstimate', 'estimates']),
        ]);
    }


    /** Admin's loan-review analysis: employment, property, purpose, requested amount, ԱՔՌԱ score. */
    public function saveLoanReview(LoanApplicationLoanReviewRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->status !== LoanApplication::STATUS_LOAN_REVIEW) {
            return response()->json([
                'message' => 'The loan review can only be filled while the application is under loan review.',
            ], 409);
        }

        $data = $request->validated();

        // Unanswered branches keep their inputs hidden, so clear anything left behind.
        if (!$request->boolean('is_employed')) {
            $data = array_merge($data, [
                'employer_name' => null, 'employer_position' => null,
                'employment_tenure_months' => null, 'employment_salary' => null,
            ]);
        }
        if (!$request->boolean('owns_property')) {
            $data['owned_property_info'] = null;
        }

        $application->update($data);

        return response()->json([
            'message' => 'Loan review saved',
            'data'    => $application->fresh(),
        ]);
    }


    /** Loan review decision: approve with the provided amount, or reject. */
    public function decide(LoanApplicationDecisionRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->status !== LoanApplication::STATUS_LOAN_REVIEW) {
            return response()->json([
                'message' => 'Only an application under loan review can be decided (current status: ' . $application->status . ').',
            ], 409);
        }

        $data = $request->validated();

        if ($data['status'] === LoanApplication::STATUS_APPROVED) {
            if (!$application->hasAcraConsent()) {
                return response()->json([
                    'message' => 'Upload the signed ԱՔՌԱ consent before approving.',
                ], 422);
            }

            $application->update([
                'status'               => LoanApplication::STATUS_APPROVED,
                'provided_amount'      => $data['provided_amount'],
                'provided_currency_id' => $data['provided_currency_id'] ?? null,
                'provided_note'        => $data['provided_note'] ?? null,
                'approved_by'          => Auth::id(),
                'approved_at'          => now(),
                'rejected_reason'      => null,
            ]);
        } else {
            $application->update([
                'status'          => LoanApplication::STATUS_REJECTED,
                'approved_by'     => Auth::id(),
                'approved_at'     => now(),
                'rejected_reason' => $data['rejected_reason'],
            ]);
        }

        return response()->json([
            'message' => 'Application ' . $application->status,
            'data'    => $application->fresh(['finalEstimate', 'approver']),
        ]);
    }


    /** Admin edit of inputs. Estimates and the final estimate are never touched. */
    public function update(LoanApplicationUpdateRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->status === LoanApplication::STATUS_CONVERTED) {
            return response()->json(['message' => 'A converted application cannot be edited.'], 409);
        }

        $data = $request->validated();

        DB::transaction(function () use ($application, $data) {
            $application->update(array_intersect_key($data, array_flip([
                'comments', 'client_age', 'client_income',
                'is_employed', 'employer_name', 'employer_position', 'employment_tenure_months', 'employment_salary',
                'owns_property', 'owned_property_info', 'loan_purpose', 'requested_amount', 'acra_score',
            ])));

            // Hidden yes/no branches keep no leftovers (same rule as the loan review).
            if (array_key_exists('is_employed', $data) && $data['is_employed'] === false) {
                $application->update([
                    'employer_name' => null, 'employer_position' => null,
                    'employment_tenure_months' => null, 'employment_salary' => null,
                ]);
            }
            if (array_key_exists('owns_property', $data) && $data['owns_property'] === false) {
                $application->update(['owned_property_info' => null]);
            }

            if (!empty($data['client']) && $application->client) {
                $application->client->update($data['client']);
            }

            foreach ($data['items'] ?? [] as $itemData) {
                $fields = collect($itemData)->except(['id', 'real_estate'])->all();

                if (!empty($itemData['id'])) {
                    $item = $application->items()->findOrFail($itemData['id']);
                    $item->update($fields);
                } else {
                    $item = $application->items()->create($fields);
                }

                if ($application->loan_type === 'property' && !empty($itemData['real_estate'])) {
                    $item->realEstate()->updateOrCreate([], [
                        'certificate_number'   => $itemData['real_estate']['certificate_number'] ?? null,
                        'certificate_password' => $itemData['real_estate']['certificate_password'] ?? null,
                        'cadastral_code'       => $itemData['real_estate']['cadastral_code'] ?? null,
                        'area_sqm'             => $itemData['real_estate']['area_sqm'] ?? null,
                        'is_joint'             => $itemData['real_estate']['is_joint'] ?? false,
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Application updated',
            'data'    => $application->fresh(['client', 'items.realEstate', 'files']),
        ]);
    }


    public function destroy(int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->status === LoanApplication::STATUS_CONVERTED) {
            return response()->json(['message' => 'A converted application cannot be deleted.'], 409);
        }

        $application->delete();

        return response()->json(['message' => 'Application deleted']);
    }


    /**
     * Adds named, typed documents. Admins (edit permission) may add any type; a
     * loan-review decider may add only ԱՔՌԱ documents while the loan is in review.
     */
    public function storeFiles(LoanApplicationFileRequest $request, int $id): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);
        $user = $request->user();
        $data = $request->validated();

        if ($application->status === LoanApplication::STATUS_CONVERTED) {
            return response()->json(['message' => 'A converted application cannot be changed.'], 409);
        }

        if (!$user->can('edit_loan_application')) {
            $types = array_map(
                fn ($index) => $data['file_types'][$index] ?? LoanApplicationDocument::OTHER,
                array_keys($data['files'])
            );
            $onlyAcra = $types !== [] && collect($types)->every(
                fn ($t) => in_array($t, [LoanApplicationDocument::ACRA, LoanApplicationDocument::ACRA_CONSENT], true)
            );

            if (!$user->can('decide_loan_application')
                || !$onlyAcra
                || $application->status !== LoanApplication::STATUS_LOAN_REVIEW) {
                abort(403, 'You are not allowed to add these documents.');
            }
        }

        $this->attachFiles(
            $application,
            $data['files'],
            $data['file_types'] ?? [],
            $data['file_titles'] ?? [],
            $data['file_visibilities'] ?? [],
        );

        return response()->json([
            'message' => 'Files added',
            'data'    => $application->files()->get(),
        ], 201);
    }


    public function destroyFile(int $id, int $fileId): JsonResponse
    {
        $application = LoanApplication::findOrFail($id);

        if ($application->status === LoanApplication::STATUS_CONVERTED) {
            return response()->json(['message' => 'A converted application cannot be changed.'], 409);
        }

        $file = $application->files()->findOrFail($fileId);

        if ($file->path) {
            Storage::disk('public')->delete($file->path);
        }
        $file->delete();

        return response()->json(['message' => 'File deleted']);
    }


    private function attachFiles(
        LoanApplication $application,
        array $files,
        array $types = [],
        array $titles = [],
        array $visibilities = [],
    ): void {
        foreach ($files as $index => $uploadedFile) {
            if (!$uploadedFile || !$uploadedFile->isValid()) {
                continue;
            }

            $storedName = Str::uuid() . '.' . $uploadedFile->getClientOriginalExtension();
            $path = $uploadedFile->storeAs('files', $storedName, 'public');

            $application->files()->create([
                'file_type'     => $uploadedFile->getClientMimeType(),
                'client_id'     => $application->client_id,
                'name'          => $uploadedFile->getClientOriginalName(),
                'title'         => ($titles[$index] ?? null) ?: $uploadedFile->getClientOriginalName(),
                'original_name' => $uploadedFile->getClientOriginalName(),
                'type'          => $uploadedFile->getClientOriginalExtension(),
                'doc_type'      => $types[$index] ?? LoanApplicationDocument::OTHER,
                'visibility'    => ($visibilities[$index] ?? 'public') === 'admin_only' ? 'admin_only' : 'public',
                'path'          => $path,
            ]);
        }
    }
}
