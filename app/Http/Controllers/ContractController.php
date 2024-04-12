<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Evaluator;
use App\Models\File;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Item;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    use ContractTrait, OrderTrait;
    public function createClient($request){
        $client = Client::create([
            'name' => $request->name,
            'surname' => $request->surname,
            'middle_name' => $request->middle_name,
            'address' => $request->address,
            'passport' => $request->passport,
            'email' => $request->email,
            'bank' => $request->bank,
            'card' => $request->card,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'phone1' => $request->phone1,
            'phone2' => $request->phone2,
            'comment' => $request->comment,
            'dob' => $request->dob,
            'passport_given' => $request->passport_given,
        ]);
        return $client;
    }
    public function updateClient($request, $id){
        Client::where('id',$id)->update([
            'name' => $request->name,
            'surname' => $request->surname,
            'middle_name' => $request->middle_name,
            'address' => $request->address,
            'passport' => $request->passport,
            'email' => $request->email,
            'bank' => $request->bank,
            'card' => $request->card,
            'phone1' => '0'.$request->phone1,
            'phone2' => '0'.$request->phone2,
            'comment' => $request->comment,
            'dob' => $request->dob,
            'passport_given' => $request->passport_given,
        ]);
    }
    public function calcAmount($amount,$days,$rate){
        return intval(ceil($days * $rate * $amount * 0.01 /10) * 10);
    }
    public function createPayments($contract){
        $toDate = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y');
        $fromDate = Carbon::parse($contract->date)->setTimezone('Asia/Yerevan')->format('d.m.Y');
        $toDate = Carbon::parse($toDate);
        $fromDate = Carbon::parse($fromDate);
        $dateToCalc = $fromDate;
        $calcEnded = false;
        while(!$calcEnded){
            $payment = [
                'contract_id' => $contract->id,
            ];
            $paymentDate = clone $dateToCalc;
            $paymentDate->addMonth();
            $payment['from_date'] = Carbon::parse($dateToCalc)->setTimezone('Asia/Yerevan')->format('d.m.Y');
            if($toDate->gt($paymentDate)){
                $diffDays = $paymentDate->diffInDays($dateToCalc);
                $payment['date'] = Carbon::parse($paymentDate)->setTimezone('Asia/Yerevan')->format('d.m.Y');
            }else{
                $calcEnded = true;
                $payment['mother'] = $contract->given;
                $payment['last_payment'] = true;
                $diffDays = $toDate->diffInDays($dateToCalc);
                $payment['date'] = Carbon::parse($toDate)->setTimezone('Asia/Yerevan')->format('d.m.Y');
            }
            $amount = $this->calcAmount($contract->given,$diffDays,$contract->rate);
            $payment['days'] = $diffDays;
            $payment['amount'] = $amount;
            $payment['pawnshop_id'] = auth()->user()->pawnshop_id;
            $dateToCalc = $paymentDate;
            Payment::create($payment);
        }
    }

    public function create(Request $request)
    {
        $client = Client::where('passport', $request->passport)->first();
        if (!$client) {
            $client = $this->createClient($request);
        }else{
            $this->updateClient($request,$client->id);
        }
        $deadline = null;
        switch ($request->deadline_type){
            case 'days':
                $deadline = Carbon::parse($request->date)->setTimezone('Asia/Yerevan')->addDays($request->deadline_days)->format('d.m.Y');
                break;
            case 'months':
                $deadline = Carbon::parse($request->date)->setTimezone('Asia/Yerevan')->addMonths($request->deadline_months)->format('d.m.Y');
                break;
            case 'calendar':
                if($request->deadline){
                    $deadline = Carbon::parse($request->deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y');
                }else{
                    $deadline = Carbon::parse($request->date)->setTimezone('Asia/Yerevan')->addMonth()->format('d.m.Y');
                }

                break;
        }
        $info = 'ծնվ. '.$request->dob.'թ., '.$request->passport.' տրվ. '.$request->passport_given;
        $ADB_ID = Contract::where('pawnshop_id',auth()->user()->pawnshop_id)->max('ADB_ID');
        $ADB_ID++;
        $cash = $request->cash === 'true';
        $contract = Contract::create([
            'name' => $request->name,
            'surname' => $request->surname,
            'middle_name' => $request->middle_name,
            'passport' => $request->passport,
            'dob' => $request->dob,
            'passport_given' => $request->passport_given,
            'info' => $info,
            'address' => $request->address,
            'phone1' => '0'.$request->phone1,
            'phone2' => '0'.$request->phone2,
            'client_id' => $client->id,
            'email' => $request->email,
            'bank' => $request->bank,
            'card' => $request->card,
            'comment' => $request->comment,
            'worth' => $request->worth,
            'given' => $request->given,
            'left' => $request->given,
            'rate' => $request->rate,
            'penalty' => $request->penalty,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'one_time_payment' => $request->one_time_payment,
            'deadline' => $deadline,
            'date' => Carbon::parse($request->date)->setTimezone('Asia/Yerevan')->format('d.m.Y'),
            'category_id' => $request->items[0]['category_id'],
            'evaluator_id' => $request->evaluator_id,
            'user_id' => auth()->user()->id,
            'ADB_ID' => $ADB_ID,
            'cash' => $cash

        ]);
        foreach ($request->items as $item){
            Item::create([
                'contract_id' => $contract->id,
                'category_id' => $item['category_id'],
                'weight' => $item['weight'],
                'clear_weight' => $item['clear_weight'],
                'type' => $item['type'],
                'description' => $item['description'],
            ]);
        }
        auth()->user()->pawnshop->given = auth()->user()->pawnshop->given + $request->given;
        auth()->user()->pawnshop->worth = auth()->user()->pawnshop->worth + $request->worth;
        auth()->user()->pawnshop->save();
        $client_files = $request->file('clientFiles');
        if($client_files){
            $destinationPath = storage_path('/app/public/client/files');
            foreach ($client_files as $file){
                $image_name = time().'_'.$file->getClientOriginalName();
                $file->move($destinationPath, $image_name);
                $client->files()->create([
                    'name' => $image_name,
                    'type' => $file->getClientMimeType(),
                    'original_name' => $file->getClientOriginalName()
                ]);
            }
        }
        $contract_files = $request->file('contractFiles');
        if($contract_files){
            $destinationPath = storage_path('/app/public/contract/files');
            foreach ($contract_files as $file){
                $image_name = time().'_'.$file->getClientOriginalName();
                $file->move($destinationPath, $image_name);
                $contract->files()->create([
                    'name' => $image_name,
                    'type' => $file->getClientMimeType(),
                    'original_name' => $file->getClientOriginalName()
                ]);
            }
        }
        $historyType = HistoryType::where('name','opening')->first();
        $client_name = $contract->name.' '.$contract->surname.' '.$contract->middle_name;
        $order_id = $this->getOrder($cash,'out');
        $res = [
            'contract_id' => $contract->id,
            'type' => 'out',
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $contract->given,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('d.m.Y'),
            'client_name' => $client_name,
            'purpose' => 'վարկ',
        ];
        $new_order = Order::create($res);
        History::create([
            'type_id' => $historyType->id,
            'contract_id' => $contract->id,
            'user_id' => auth()->user()->id,
            'order_id' => $new_order->id,
            'date' => Carbon::parse($request->date)->setTimezone('Asia/Yerevan')->format('d.m.Y'),
            'amount' => $request->given
        ]);
        $this->createDeal($request->given,'out',$contract->id,$new_order->id,$cash,'Գրավ');
        $historyType = HistoryType::where('name','one_time_payment')->first();
        $client_name = $contract->name.' '.$contract->surname;
        if($contract->middle_name){
            $client_name.= ' '.$contract->middle_name;
        }
        $order_id = $this->getOrder($cash,'in');
        $res = [
            'contract_id' => $contract->id,
            'type' => 'in',
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $contract->one_time_payment,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('d.m.Y'),
            'client_name' => $client_name,
            'purpose' => 'Մինավագ վճար',
        ];
        $new_order = Order::create($res);
        History::create([
            'type_id' => $historyType->id,
            'contract_id' => $contract->id,
            'user_id' => auth()->user()->id,
            'order_id' => $new_order->id,
            'date' => Carbon::parse($request->date)->setTimezone('Asia/Yerevan')->format('d.m.Y'),
            'amount' => $request->one_time_payment
        ]);
        $this->createDeal($request->one_time_payment,'in',$contract->id,$new_order->id,$cash,'Միանվագ Վճար');
        $this->createPayments($contract);
        return response()->json([
            'contract' =>$contract,
            'all' => $request->all()
        ]);
    }
    public function extendPayments($contract,$deadline){
        $toDate = Carbon::parse($deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y');
        $fromDate = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y');
        $toDate = Carbon::parse($toDate);
        $fromDate = Carbon::parse($fromDate);
        $dateToCalc = $fromDate;
        $calcEnded = false;
        while(!$calcEnded){
            $payment = [
                'contract_id' => $contract->id,
            ];
            $paymentDate = clone $dateToCalc;
            $paymentDate->addMonth();
            $payment['from_date'] = Carbon::parse($dateToCalc)->setTimezone('Asia/Yerevan')->format('d.m.Y');
            if($toDate->gt($paymentDate)){
                $diffDays = $paymentDate->diffInDays($dateToCalc);
                $payment['date'] = Carbon::parse($paymentDate)->setTimezone('Asia/Yerevan')->format('d.m.Y');
            }else{
                $calcEnded = true;
                $payment['mother'] = $contract->left;
                $payment['last_payment'] = true;
                $diffDays = $toDate->diffInDays($dateToCalc);
                $payment['date'] = Carbon::parse($toDate)->setTimezone('Asia/Yerevan')->format('d.m.Y');
            }
            $amount = $this->calcAmount($contract->left,$diffDays,$contract->rate);
            $payment['days'] = $diffDays;
            $payment['amount'] = $amount;
            $payment['pawnshop_id'] = auth()->user()->pawnshop_id;
            $dateToCalc = $paymentDate;
            Payment::create($payment);
        }
    }
    public function extend(Request $request){
        $contract = Contract::where('id',$request->contract_id)->first();
        if(!$contract){
            return response()->json([
                'success' => 'error'
            ]);
        }
        $deadline = null;
        switch ($request->deadline_type){
            case 'days':
                $deadline = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan')->addDays($request->deadline_days)->format('d.m.Y');
                break;
            case 'months':
                $deadline = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan')->addMonths($request->deadline_months)->format('d.m.Y');
                break;
            case 'calendar':
                if($request->deadline){
                    $deadline = Carbon::parse($request->deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y');
                }else{
                    $deadline = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan')->addMonth()->format('d.m.Y');
                }
                break;
        }
        if(Carbon::parse($deadline)->gt(Carbon::parse($contract->deadline))){
            $last_payment = Payment::where('contract_id', $contract->id)->where('last_payment',true)->first();
            if($last_payment){
                $last_payment->mother = 0;
                $last_payment->last_payment = false;
                $last_payment->save();
            }
            $this->extendPayments($contract,$deadline);
            $contract->deadline = $deadline;
            $contract->extended = true;
            $contract->save();
            $historyType = HistoryType::where('name','extending')->first();
            History::create([
                'type_id' => $historyType->id,
                'contract_id' => $contract->id,
                'user_id' => auth()->user()->id,
                'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y'),
            ]);
        }
        $contract = $this->getFullContract($contract->id);
        return response() -> json([
            'all' => $request->all(),
            'success' => 'success',
            'contract' => $contract
        ]);
    }
    public function execute(Request $request){
        $contract = Contract::where('id',$request->contract_id)->first();
        $profit = $request->amount - $contract->left;
        auth()->user()->pawnshop->given = auth()->user()->pawnshop->given - $contract->left;
        auth()->user()->pawnshop->worth = auth()->user()->pawnshop->worth - $contract->worth;
        auth()->user()->pawnshop->save();
        $this->createDeal($contract->left,'in',$contract->id,null,true,'Իրացում');
        $this->createDeal($profit,'in',$contract->id,null,true,'Իրացման Շահույթ');
        $contract->status = 'executed';
        $contract->executed = $request->amount;
        $contract->left = 0;
        $contract->close_date = Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y');
        $contract->save();
        $historyType = HistoryType::where('name','execution')->first();
        History::create([
            'type_id' => $historyType->id,
            'contract_id' => $contract->id,
            'user_id' => auth()->user()->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y'),
            'amount' => $request->amount
        ]);
        $contract = $this->getFullContract($contract->id);
        return response() -> json([
            'all' => $request->all(),
            'success' => 'success',
            'contract' => $contract
        ]);
    }

    public function recalculateContractPayments($contract){
        Payment::where('contract_id',$contract->id)->forceDelete();
        $this->createPayments($contract);
    }
    public function hasDifference($contract,$request){
        $changed = false;
        $collection = collect(['given','rate']);
        $changed =  $collection->contains(function ($value, $key) use ($contract, $request) {
            return $contract[$value] !== $request[$value];
        });
        if($changed){
            return true;
        }
        if(Carbon::parse($request->deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y') !== $contract->deadline){
            $changed = true;
        }
        return $changed;

    }
    public function update(Request $request){
        $originalContract = Contract::where('id',$request->id)->first();
        $changedEssentialValue = $this->hasDifference($originalContract,$request);
        $changedClient = $request->passport !== $originalContract->passport;
        if($changedClient){
            $client = Client::where('passport', $request->passport)->first();
            if (!$client) {
                $client = $this->createClient($request);
            }
            $originalContract->client_id = $client->id;
            $originalContract->save();
        }
        $originalContract->update([
            'name' => $request->name,
            'surname' => $request->surname,
            'middle_name' => $request->middle_name,
            'passport' => $request->passport,
            'address' => $request->address,
            'phone1' => $request->phone1,
            'phone2' => $request->phone2,
            'dob' => $request->dob,
            'passport_given' => $request->passport_given,
            'email' => $request->email,
            'comment' => $request->comment,
            'worth' => $request->worth,
            'given' => $request->given,
            'left' => $request->given,
            'rate' => $request->rate,
            'penalty' => $request->penalty,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'one_time_payment' => $request->one_time_payment,
            'deadline' => Carbon::parse($request->deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y'),
            'category_id' => $request->category_id,
            'evaluator_id' => $request->evaluator_id,
            'user_id' => auth()->user()->id
        ]);
        foreach ($request->items as $item){
            if(array_key_exists('id',$item) && $item['id']){
                if(array_key_exists('deleted',$item) && $item['deleted']){
                    Item::where('id',$item['id'])->delete();
                }else{
                    Item::where('id',$item['id'])->update([
                        'category_id' => $item['category_id'],
                        'weight' => $item['weight'],
                        'clear_weight' => $item['clear_weight'],
                        'type' => $item['type'],
                        'description' => $item['description'],
                    ]);
                }
            }else{
                Item::create([
                    'contract_id' => $originalContract->id,
                    'category_id' => $item['category_id'],
                    'weight' => $item['weight'],
                    'clear_weight' => $item['clear_weight'],
                    'type' => $item['type'],
                    'description' => $item['description'],
                ]);
            }
        }
        if($changedEssentialValue){
            $this->recalculateContractPayments($originalContract);
        }
        if($changedEssentialValue || $changedClient){
            $type = HistoryType::where('name','edit')->first();
            History::create([
                'type_id' => $type->id,
                'contract_id' => $originalContract->id,
                'user_id' => auth()->user()->id,
                'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y'),
            ]);
        }
        return response()->json([
            'contract' => $originalContract,
        ]);
    }

    public function editContract($id){
        $categories = Category::get();
        $evaluators = Evaluator::where('pawnshop_id',auth()->user()->pawnshop_id)->get();
        $contract = Contract::where('id',$id)->where('status','initial')->with('items')->first();
        return response()->json([
            'contract' =>$contract,
            'categories' =>$categories,
            'evaluators' =>$evaluators,
        ]);
    }

    public function get(Request $request)
    {
        $contracts = Contract::where('pawnshop_id',auth()->user()->pawnshop_id)->orderBy('created_at', 'DESC')->with(['payments' => function($payment){
            $payment->orderBy('date');
        }])->with(['client' => function($query){
            $query->withCount('contracts');
        }])->paginate(10);
        foreach ($contracts as $contract){
            $contract->category_title = $contract->category->title;
            $contract->evaluator_title = $contract->evaluator->full_name;
        }
        $all = Contract::where('pawnshop_id',auth()->user()->pawnshop_id)->get()->count();
        return response()->json([
            'contracts' => $contracts,
            'all' => $all
        ]);
    }
    public function getFilters(){
        $users = User::where('pawnshop_id',auth()->user()->pawnshop_id)->get();
        $categories = Category::all();
        return response()->json([
            'categories' => $categories,
            'users' => $users
        ]);
    }
    public function filterContracts(Request $request)
    {
        $contracts = Contract::where('pawnshop_id',auth()->user()->pawnshop_id)->orderBy('id', 'DESC')
            ->with(['payments' => function($payment){
                $payment->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC");
            },'items' => function($item){
                $item->with('category');
            }])
            ->with(['client' => function($query){
                $query->withCount('contracts');
            }])
            ->with('category')
            ->when($request->id,function ($query) use ($request){
                $query->where('id', $request->id);
            })
            ->when($request->ADB_ID,function ($query) use ($request){
                $query->where('ADB_ID', $request->ADB_ID);
            })
            ->when($request->number,function ($query) use ($request){
                $query->where('phone1', $request->number)->orWhere('phone2',$request->number);
            })
            ->when($request->passport,function ($query) use ($request){
                $query->where('passport', 'LIKE', '%'.$request->passport.'%');
            })
            ->when($request->name,function ($query) use ($request){
                $query->where('name', 'LIKE', '%'.$request->name.'%');
            })
            ->when($request->surname,function ($query) use ($request){
                $query->where('surname', 'LIKE', '%'.$request->surname.'%');
            })
            ->when($request->status,function ($query) use ($request){
                $query->where('status',$request->status);
            })
            ->when($request->user,function ($query) use ($request){
                $query->where('user_id',$request->user);
            })
            ->when($request->category,function ($query) use ($request){
                $query->where('category_id',$request->category);
            })
            ->when($request->givenFrom,function ($query) use ($request){
                $query->where('given','>=',$request->givenFrom);
            })
            ->when($request->givenTo,function ($query) use ($request){
                $query->where('given','<=',$request->givenTo);
            })
            ->when($request->passed,function ($query) use ($request){
                $query->whereHas('payments',function ($payment) use ($request){
                    $payment->where('status','initial')
                        ->where('date',Carbon::now()->subDays($request->passed)->setTimezone('Asia/Yerevan')->format('d.m.Y'));
                });
            })
            ->when($request->dateFrom,function ($query) use ($request){
                $query->where(function ($query) use ($request) {
                    $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [Carbon::parse($request->dateFrom)->setTimezone('Asia/Yerevan')]);
                });
            })
            ->when($request->dateTo,function ($query) use ($request){
                $query->where(function ($query) use ($request) {
                    $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [Carbon::parse($request->dateTo)->setTimezone('Asia/Yerevan')]);
                });
            })->paginate(8);

        foreach ($contracts as $contract){
            if($contract->category){
                $contract->category_title = $contract->category->title;
            }
            if($contract->evaluator){
                $contract->evaluator_title = $contract->evaluator->full_name;
            }

        }
//        $all = Contract::where('pawnshop_id',auth()->user()->pawnshop_id)->get()->count();
        return response()->json([
            'all' => $request->all(),
            'contracts' => $contracts
        ]);
    }
    public function getTodaysContracts(Request $request)
    {
        $payments = Payment::where('pawnshop_id',auth()->user()->pawnshop_id)
            ->where('type','regular')
            ->where('status','initial')
            ->where('date',Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y'))
            ->whereHas('contract',function ($contract){
                $contract->where('status','initial');
            })
            ->with(['contract' => function($contract){
                $contract->with(['payments' => function($payment){
                    $payment->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC");
                },'client' => function($query){
                    $query->withCount('contracts');
                }]);
            }])
            ->orderBy('id','DESC')->paginate(8);
        return response()->json([
            'payments' => $payments,
        ]);
    }
    public function getCategories()
    {
       $categories = Category::get();
       $evaluators = Evaluator::where('pawnshop_id',auth()->user()->pawnshop_id)->get();
       $res = [
           'categories' => $categories,
           'evaluators' => $evaluators,
       ];
       if(request()->query('client_id')){
           $client = Client::where('id', request()->query('client_id'))->first();
           $res['client'] = $client;
       }
        return response()->json($res);
    }

    public function searchClient(Request $request){
        $clientQuery = Client::query();
        $keywords = explode(' ', $request->text);
        foreach ($keywords as $keyword) {
            $clientQuery->where(function ($query) use ($keyword) {
                $query->where('name', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('surname', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('passport', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('email', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('comment', 'LIKE', '%'.$keyword.'%');
            });
        }
        $clients = $clientQuery->where('pawnshop_id',auth()->user()->pawnshop_id)->limit(5)->get();
        return response()->json([
            'all' => $request->all(),
            'clients' => $clients
        ]);
    }
    public function mainSearch(Request $request){
        $clientQuery = Client::query();
        $contractQuery = Contract::query();
        $keywords = explode(' ', $request->text);
        foreach ($keywords as $keyword) {
            $clientQuery->where(function ($query) use ($keyword) {
                $query->where('name', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('surname', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('passport', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('email', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('comment', 'LIKE', '%'.$keyword.'%');
            });

            $contractQuery->where(function ($query) use ($keyword) {
                $query->where('name', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('surname', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('passport', 'LIKE', '%'.$keyword.'%');
            });
        }
        $clients = $clientQuery->where('pawnshop_id',auth()->user()->pawnshop_id)->limit(5)->get();
        $contracts = $contractQuery->where('pawnshop_id',auth()->user()->pawnshop_id)->limit(5)->get();

        return response()->json([
            'all' => $request->all(),
            'clients' => $clients,
            'contracts' => $contracts
        ]);
    }
}
