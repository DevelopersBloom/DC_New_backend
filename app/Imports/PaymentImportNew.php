<?php

namespace App\Imports;

use App\Models\Contract;
use App\Models\Order;
use App\Models\Payment;
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
                $pgi_id = $row[0];
                if (substr($pgi_id, -1) === '.') {
                    $pgi_id = rtrim($pgi_id, '.');
                }
                $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[2]));
                $amount = $row[3];
                $status = $row[4];
                $penalty = $row[5];
                $cash = $row[6] ?? null;

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

                        if ($penalty > 0) {
                            $payment = $contract->payments()->create([
                                'status' => 'completed',
                                'amount' => 0,
                                'paid' => $penalty,
                                'PGI_ID' => $pgi_id,
                                'date' => $date->format('Y.m.d'),
                                'pawnshop_id' => 1,
                                'type' => 'penalty'
                            ]);
                            $contract->collected += $penalty;
                            $purpose .= 'և' . Contract::PENALTY;
                        }
                        $order_id = $this->getOrder($cash,'in',1);

                        $res = [
                            'contract_id' => $contract->id,
                            'num' => $contract->num,
                            'type' => 'in',
                            'title' => 'Օրդեր',
                            'pawnshop_id' => 1,
                            'order' => $order_id,
                            'amount' => $amount + $penalty,
                            'rep_id' => '2211',
                            'date' => $date->format('Y.m.d'),
                            'client_name' => $contract->client['name'] . $contract->client['surname'],
                            'purpose' => $purpose,
                            'cash' => $cash
                        ];
                        $new_order = Order::create($res);
                        $request = (object)['date'=>$date,'contract_id' => $contract->id, 'amount' => $amount,'payments' => $contract->payments];
                        $history = $this->createHistory($request,$new_order->id,$amount,null,$penalty, null);
                        $this->createDeal($amount+$penalty,
                            $amount,null,$penalty,
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

                        if ($penalty > 0) {
                            $contract->penalty_amount = $contract->penalty_amount + $penalty;
                        }
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
