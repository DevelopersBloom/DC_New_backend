<?php

namespace App\Http\Controllers;

use App\Events\Discuss;
use App\Models\Discussion;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InnerController extends Controller
{
    public function addComment(Request $request){
        Discussion::create([
            'user_id' => auth()->user()->id,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'text' => $request->text
        ]);
        event(new Discuss(auth()->user()->id, auth()->user()->pawnshop_id));
        return response()->json([
            'success' => 'success',
            'all' => $request->all(),
            'user' => auth()->user()
        ]);
    }
    public function getComments(Request $request){
        $discussions = Discussion::where('pawnshop_id',auth()->user()->pawnshop_id)->orderBy('id','desc')->with('user')->get();
        foreach ($discussions as $discussion){
            $discussion->date = Carbon::parse($discussion->created_at)->setTimezone('Asia/Yerevan')->format('m-d H:i:s');
        }
        return response()->json([
            'success' => 'success',
            'discussions' => $discussions,
        ]);
    }
}
