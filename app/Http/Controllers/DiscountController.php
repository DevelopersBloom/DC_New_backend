<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDiscountRequest;
use App\Models\Discount;
use App\Services\DiscountService;
use App\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    protected DiscountService $discountService;
    protected PaymentService $paymentService;
    public function __construct(PaymentService $paymentService,DiscountService $discountService)
    {
        $this->paymentService = $paymentService;
        $this->discountService = new DiscountService($this->paymentService);
    }
    public function getDiscountRequests()
    {
        return Discount::select('id', 'amount', 'user_id')
            ->with('user:id,name,surname') // Ensure relationship name is correct
            ->where('status', Discount::PENDING)
            ->where('pawnshop_id', auth()->user()->pawnshop_id)
            ->orderByDesc('created_at')
            ->get();
//            ->map(function ($discount) {
//                return [
//                    'id' => $discount->id,
//                    'amount' => $discount->amount,
//                    'name' => $discount->user?->name,  // Safe access with null check
//                    'surname' => $discount->user?->surname,
//                ];
//            });
    }


    public function requestDiscount(StoreDiscountRequest $request): JsonResponse
    {

        $validatedData = $request->validated();
        $this->discountService->requestDiscount($validatedData);
        $message = ($validatedData['amount'] > 5000)
            ? 'Discount request sent successfully!'
            : 'Discount applied successfully!';
        return response()->json([
            'message' => $message,
        ],201);
    }

    /**
     * @throws AuthorizationException
     */
    public function answerDiscount(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|exists:discounts,id',
            'status' => 'required|in:accept,reject'
        ]);
        $discount = Discount::findOrFail($request->id);
        $this->discountService->processDiscountResponse($discount,$request->status);
        return response()->json([
            'message' => 'Status updated correctly',
        ]);
    }

}
