<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ScheduleRegeneration;
use App\Services\PostingDatePolicy;
use App\Services\Schedule\ScheduleRegenerationException;
use App\Services\Schedule\ScheduleRegenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleRegenerationController extends Controller
{
    public function __construct(
        private ScheduleRegenerator $regenerator,
        private PostingDatePolicy $postingDatePolicy,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contract_id'    => 'required|integer|exists:contracts,id',
            'effective_date' => 'required|date',
            'payment_day'    => 'nullable|integer|min:1|max:28',
        ]);

        return $this->run(function () use ($data) {
            if ($error = $this->postingDatePolicy->validate($data['effective_date'])) {
                return $error;
            }

            return response()->json($this->regenerator->preview(
                Contract::findOrFail($data['contract_id']),
                $data['effective_date'],
                $data['payment_day'] ?? null
            ));
        });
    }

    public function apply(Request $request): JsonResponse
    {
        dd(2);
        $data = $request->validate([
            'contract_id'    => 'required|integer|exists:contracts,id',
            'effective_date' => 'required|date',
            'payment_day'    => 'nullable|integer|min:1|max:28',
            'reason'         => 'required|string|max:500',
            'state_hash'     => 'required|string|size:40',
        ]);

        return $this->run(function () use ($data) {
            if ($error = $this->postingDatePolicy->validate($data['effective_date'])) {
                return $error;
            }

            return response()->json($this->regenerator->apply(
                (int) $data['contract_id'],
                $data['effective_date'],
                $data['payment_day'] ?? null,
                $data['reason'],
                $data['state_hash'],
                auth()->id()
            ));
        });
    }

    public function revert(int $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $record = $this->regenerator->revert($id, auth()->id());

            return response()->json(['regeneration_id' => $record->id, 'reverted' => true]);
        });
    }

    public function index(int $contractId): JsonResponse
    {
        $latestActiveId = ScheduleRegeneration::where('contract_id', $contractId)
            ->whereNull('reverted_at')->max('id');

        $items = ScheduleRegeneration::with('user:id,name')
            ->where('contract_id', $contractId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ScheduleRegeneration $r) => [
                'id'                 => $r->id,
                'user'               => $r->user?->name,
                'effective_date'     => $r->effective_date?->toDateString(),
                'created_at'         => $r->created_at?->toDateTimeString(),
                'reason'             => $r->reason,
                'balance'            => (float) $r->balance,
                'old_monthly'        => (float) $r->old_monthly,
                'new_monthly'        => (float) $r->new_monthly,
                'old_total_interest' => (float) $r->old_total_interest,
                'new_total_interest' => (float) $r->new_total_interest,
                'old_last_date'      => $r->old_last_date?->toDateString(),
                'new_last_date'      => $r->new_last_date?->toDateString(),
                'reverted_at'        => $r->reverted_at?->toDateTimeString(),
                'can_revert'         => $r->isActive() && $r->id === $latestActiveId,
            ]);

        return response()->json(['data' => $items]);
    }

    private function run(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (ScheduleRegenerationException $e) {
            return response()->json(array_merge($e->context, [
                'success'    => false,
                'error_code' => $e->errorCode,
                'message'    => $e->getMessage(),
            ]), $e->status);
        }
    }
}
