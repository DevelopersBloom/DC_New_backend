<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanNdmRequest;
use App\Models\Client;
use App\Models\LoanNdm;
use App\Models\Order;
use App\Models\Transaction;
use App\Traits\OrderTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LoanNdmController extends Controller
{
    use OrderTrait;
    public function index(): JsonResponse
    {
            $loans = LoanNdm::with([
                'client:id,name,surname',
                'currency:id,code,name',
                'account:id,code,name',
                'interestAccount:id,code,name',
                'pawnshop:id,city',
            ])->get();

            return response()->json([
                'data'    => $loans,
            ]);
    }

    public function store(StoreLoanNdmRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            DB::beginTransaction();

            $amount = $data['amount'];
            $interestRate = $data['interest_rate'] ?? 0;

            $disbursementDate = Carbon::parse($data['disbursement_date']);
            $maturityDate     = Carbon::parse($data['maturity_date']);
            $days = $disbursementDate->diffInDays($maturityDate);

            switch ($data['day_count_convention'] ?? 'calendar_year') {
                case 'days_360':
                    $baseDays = 360;
                    break;

                case 'fixed_day':
                    // Օրինակ՝ 30 օր մեկ ամիս * 12 = 360
                $baseDays = 360;
                    break;

                case 'calendar_year':
                default:
                    $year = $disbursementDate->year;
                    $baseDays = Carbon::create($year)->isLeapYear() ? 366 : 365;
                    break;
            }

            $interestAmount = round($amount * ($interestRate / 100) * ($days / $baseDays), 2);

            $data['interest_amount'] = $interestAmount;

            $isPhysical = true;
            $data['income'] = $isPhysical
                ? $interestAmount - round($interestAmount * ($data['tax_rate'] / 100), 2)
                : $interestAmount;

            $loanNdm = LoanNdm::create($data);

//            $purpose = Order::NDM_PURPOSE;
//            $filter_type = Order::NDM_FILTER;
//            $type = 'cost_out';
//            $cash = true;
            $clientId = $data['client_id'];

            $client = Client::findOrFail($clientId);

            $partnerName = $client->type == 'legal' ? $client->company_name : $client->name . ' ' . $client->surname;

            $partnerCode = $client->type == 'individual' ? $client->social_card_number :
                $client->tax_number;

            Transaction::create([
                'date'               => $data['contract_date'],
                'document_number'    => $data['contract_number'],
                'document_type'      => Transaction::LOAN_NDM_TYPE,

//                'debit_account_id'   => 0,
                'debit_partner_code' => $partnerCode,
                'debit_partner_name' => $partnerName,
                'debit_currency_id'  => $data['currency_id'],
                'disbursement_date'  => $data['disbursement_date'],

//                'credit_account_id'   => 0,
//                'credit_partner_code' => $creditPartnerCode,
//                'credit_partner_name' => $creditPartnerName,
//                'credit_currency_id'  => $reminderOrder->currency_id,

                'amount_amd'       => $data['amount'],
                'amount_currency'  => 0,
                'amount_currency_id'=> null,

                'comment'   => $data['comment'],
                'user_id'   => auth()->id(),
                'is_system' => false,
            ]);


//            $order_id = $this->getOrder($cash, $type);
//            $this->createOrderAndDeal(
//                $order_id,
//                $type === 'out' ? 'cost_out' : 'in',
//                $name,
//                $amount,
//                $purpose,
//                null,
//                $cash,
//                $filter_type,
//                $interestAmount,
//                $clientId
//            );

            DB::commit();

            return response()->json(['message' => 'Loan ndm created successfully'], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
