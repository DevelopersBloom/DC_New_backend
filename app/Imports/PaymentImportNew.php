<?php

namespace App\Imports;

use App\Models\Contract;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Traits\ContractTrait;
use App\Traits\FileTrait;
use App\Traits\HistoryTrait;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class PaymentImportNew implements ToCollection
{
    use ContractTrait,FileTrait,HistoryTrait;
    protected $paymentService;
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        foreach ($collection->skip(2) as $row) {
            $contract_num = $row[1];
            $contract = Contract::where('num', $contract_num)->first();
            if ($contract) {
                $pgi_id = $row[0];
//                if (isset($row[2]) && is_numeric(trim($row[2]))) {
                    $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[2]));
//                } else {
//                    dd("Invalid date value: ", $row[2]); // Debugging to check what value is causing the error
//                }
               // $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[2]));
                $amount = $row[3];
                $status = $row[4];
                $penalty = $row[5];
                $cash = $row[6] ?? null;

                if ($pgi_id == 'Մասնակի մարում') {
                    $user = User::where('id',1)->first();
                    $payment_id = $this->paymentService->payPartial($contract,$amount,$user,$cash);
                    $history_type = HistoryType::where('name','partial_payment')->first();
                    $client_name = $contract->client->name.' '.$contract->client->surname.' '.$contract->client->middle_name;
                    $order_id = $this->getOrder($cash,'in');
                    $res = [
                        'contract_id' => $contract->id,
                        'type' => 'in',
                        'title' => 'Օրդեր',
                        'pawnshop_id' => 1,
                        'order' => $order_id,
                        'amount' => $amount,
                        'rep_id' => '2211',
                        'date' => Carbon::now()->format('Y-m-d'),
                        'client_name' => $client_name,
                        'purpose' => 'Մասնակի մարում',
                        'cash' => $cash
                    ];
                    $new_order = Order::create($res);
                    $history = History::create([
                        'amount' => $amount,
                        'user_id' => $user->id,
                        'type_id' => $history_type->id,
                        'order_id' => $new_order->id,
                        'contract_id' => $contract->id,
                        'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
                    ]);
                    $this->createDeal($amount, null,null, null,null,'in', $contract->id,$contract->client->id, $new_order->id, $cash,null, Contract::PARTIAL_PAYMENT,'partial_payment',$history->id,$payment_id,null,1,$date);

                } else {
                    if (substr($pgi_id, -1) === '.') {
                        $pgi_id = rtrim($pgi_id, '.');
                    }
                    if ($pgi_id !== 'ՄԳ') {
                        if ( $status == 'Վճարված') {
                            $payment = $contract->payments()->create([
                                'status' => 'completed',
                                'amount' => 0,
                                'paid' => $amount,
                                'PGI_ID' => $pgi_id,
                                'date' => $date->format('Y.m.d'),
                                'pawnshop_id' => 1,
                                'type' => 'regular'
                            ]);
                            $contract->collected += $amount;
                            $purpose = Contract::REGULAR_PAYMENT;

//                            if ($penalty > 0) {
//                                $payment = $contract->payments()->create([
//                                    'status' => 'completed',
//                                    'amount' => 0,
//                                    'paid' => $penalty,
//                                    'PGI_ID' => $pgi_id,
//                                    'date' => $date->format('Y.m.d'),
//                                    'pawnshop_id' => 1,
//                                    'type' => 'penalty'
//                                ]);
//                                $contract->collected += $penalty;
//                                $purpose .= 'և' . Contract::PENALTY;
//                            }
                            $order_id = $this->getOrder($cash,'in',1);

                            $res = [
                                'contract_id' => $contract->id,
                                'num' => $contract->num,
                                'type' => 'in',
                                'title' => 'Օրդեր',
                                'pawnshop_id' => 1,
                                'order' => $order_id,
                                'amount' => $amount,
                                'rep_id' => '2211',
                                'date' => $date->format('Y.m.d'),
                                'client_name' => $contract->client['name'] . $contract->client['surname'],
                                'purpose' => $purpose,
                                'cash' => $cash
                            ];
                            $new_order = Order::create($res);
                            $request = (object)['date'=>$date,'contract_id' => $contract->id, 'amount' => $amount,'payments' => $contract->payments];
                            $history = $this->createHistory($request,$new_order->id,$amount,null,null, null);
                            $this->createDeal($amount,
                                $amount,null,null,
                                null, 'in', $contract->id,$contract->client->id,
                                $new_order->id, $cash,null,$purpose,'payment',null,$payment->id,null,1,$date);
                        } elseif($status == 'Չվճարված') {
                            $from_date = clone $date;
                            $from_date = $from_date->subMonth()->format('d.m.Y');
                            $contract->payments()->create([
                                'status' => 'initial',
                                'amount' => $amount,
                                'paid' => 0,
                                'PGI_ID' => $pgi_id,
                                'date' => $date->format('Y.m.d'),
                                'pawnshop_id' => 1,
                                'type' => 'regular',
                                'from_date' => $from_date,
                            ]);

//                            if ($penalty > 0) {
//                                $contract->penalty_amount = $contract->penalty_amount + $penalty;
//                            }
                        }
                    } else {
                        $lastPayment = Payment::where('contract_id',$contract->id)->where('type','regular')->orderBy('id','DESC')->first();
                        if ($lastPayment) {
                            $lastPayment->update([
                                'last_payment' => true,
                                'mother' => $amount
                            ]);
                            if ($status === 'Վճարված') {
                                $lastPayment->update([
                                    'paid' => $lastPayment->paid + $amount
                                ]);
                                $contract->left = $contract->left - $amount;
                                $contract->closed_at = $date->format('Y.m.d');
                            }
                        }
                    }
                    $contract->save();
                }
            }
        }
    }
}
