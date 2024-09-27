<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function createClient()
    {


    }
    public function getInfo($id){
        $client = Client::where('pawnshop_id', auth()->user()->pawnshop_id)->where('id',$id)->with(['files','contracts' => function($contract){
            $contract->with('category');
        }])->first();
        if($client){
            $contracts = collect();
            foreach ($client->contracts as $contract){
                if($contract->category){
                    $contract->category_title = $contract->category->title;
                }
                $contracts->push($contract);
            }
            $client->contracts = $contracts;
        }
        return response()->json([
            'client' => $client
        ]);
    }

    public function getClients(){
        $clients = Client::where('pawnshop_id',auth()->user()->pawnshop_id)->orderBy('created_at', 'DESC')->withCount(['contracts', 'activeContracts'])->paginate(10);
        $all = Client::where('pawnshop_id',auth()->user()->pawnshop_id)->get()->count();
        return response()->json([
            'clients' => $clients,
            'all' => $all
        ]);
    }

    public function saveFiles(Request $request){
        $client = Client::where('id',$request->client_id)->first();
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
        $client = Client::where('pawnshop_id', auth()->user()->pawnshop_id)->where('id',$request->client_id)->with(['files','contracts' => function($contract){
            $contract->with('category');
        }])->first();
        if($client){
            $contracts = collect();
            foreach ($client->contracts as $contract){
                $contract->category_title = $contract->category->title;
                $contracts->push($contract);
            }
            $client->contracts = $contracts;
        }
        return response()->json([
            'client' => $client
        ]);
    }

}
