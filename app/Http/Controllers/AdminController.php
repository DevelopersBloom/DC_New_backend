<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Discount;
use App\Models\Evaluator;
use App\Models\Pawnshop;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function getUsers(){
        $users = User::with('pawnshop')->withCount('contracts')->get();
        return response()->json([
            'users' => $users
        ]);
    }
    public function getDiscounts(){
        $discounts = Discount::where('status','initial')->with(['contract','user','pawnshop'])->orderBy('id','desc')->paginate(8);
        return response()->json([
            'discounts' => $discounts
        ]);
    }
    public function getEvaluators(){
        $evaluators = Evaluator::with('pawnshop')->withCount('contracts')->get();
        return response()->json([
            'evaluators' => $evaluators
        ]);
    }
    public function editUser($id){
        $user = User::where('id',$id)->first();
        $pawnshops = Pawnshop::get();
        return response()->json([
            'user' => $user,
            'pawnshops' => $pawnshops
        ]);
    }
    public function editEvaluator($id){
        $evaluator = Evaluator::where('id',$id)->first();
        $pawnshops = Pawnshop::get();
        return response()->json([
            'evaluator' => $evaluator,
            'pawnshops' => $pawnshops
        ]);
    }
    public function editPawnshop($id){
        $pawnshop = Pawnshop::where('id',$id)->first();
        return response()->json([
            'pawnshop' => $pawnshop
        ]);
    }
    public function editCategory($id){
        $category = Category::where('id',$id)->first();
        return response()->json([
            'category' => $category
        ]);
    }
    public function getCategories(){
        $categories = Category::withCount('contracts')->get();
        return response()->json([
            'categories' => $categories
        ]);
    }
    public function getPawnshops(){
        $pawnshops = Pawnshop::withCount(['contracts','users'])->get();
        return response()->json([
            'pawnshops' => $pawnshops
        ]);
    }
    public function getUserConfig(){
        $pawnshops = Pawnshop::get();
        return response()->json([
            'pawnshops' => $pawnshops
        ]);
    }
    public function createUser(Request $request){
        $user = User::create([
            'name' => $request->name,
            'surname' => $request->surname,
            'password' => bcrypt($request->password),
            'email' => $request->email,
            'pawnshop_id' => $request->pawnshop_id
        ]);
        if($user){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
    public function createEvaluator(Request $request){
        $user = Evaluator::create([
            'full_name' => $request->full_name,
            'pawnshop_id' => $request->pawnshop_id
        ]);
        if($user){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
    public function updateUser(Request $request){
        $user = User::where('id',$request->id)->with(['pawnshop','config'])->first();
        if($user){
            $user->name = $request->name;
            $user->surname = $request->surname;
            $user->email = $request->email;
            $user->pawnshop_id = $request->pawnshop_id;
            $user->role = $request->role;
            if($request->changePassword){
                $user->password = bcrypt($request->newPassword);
            }
            $user->save();
            return response()->json([
                'success' => 'success',
                'user' => $user
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }


    }
    public function deleteUser(Request $request){
        User::where('id',$request->id)->delete();
        return response()->json([
            'success' => 'success',
        ]);

    }
    public function updateEvaluator(Request $request){
        $evaluator = Evaluator::where('id',$request->id)->update([
            'full_name' => $request->full_name,
            'pawnshop_id' => $request->pawnshop_id
        ]);
        if($evaluator){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
    public function createPawnshop(Request $request){
        $pawnshop = Pawnshop::create([
            'city' => $request->city,
            'address' => $request->address,
            'license' => $request->license,
            'representative' => $request->representative,
            'telephone' => $request->telephone,
            'phone1' => $request->phone1,
            'phone2' => $request->phone2,
            'email' => $request->email,
            'bank' => $request->bank,
            'card_account_number' => $request->card_account_number,
        ]);
        if($pawnshop){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
    public function updatePawnshop(Request $request){
        $pawnshop = Pawnshop::where('id',$request->id)->update([
            'city' => $request->city,
            'address' => $request->address,
            'license' => $request->license,
            'representative' => $request->representative,
            'telephone' => $request->telephone,
            'phone1' => $request->phone1,
            'phone2' => $request->phone2,
            'email' => $request->email,
            'bank' => $request->bank,
            'card_account_number' => $request->card_account_number,
        ]);
        if($pawnshop){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
    public function updateCashbox(Request $request){
        $pawnshop = Pawnshop::where('id',$request->id)->update([
            'cashbox' => $request->cashbox,
            'bank_cashbox' => $request->bank_cashbox,
            'worth' => $request->worth,
            'given' => $request->given,
            'insurance' => $request->insurance,
        ]);
        if($pawnshop){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }

    public function createCategory(Request $request){
        $category = Category::create([
            'title' => $request->title
        ]);
        if($category){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
    public function updateCategory(Request $request){
        $category = Category::where('id',$request->id)->update([
            'title' => $request->title
        ]);
        if($category){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
    public function checkAuthority(Request $request){
        if(auth('api')->attempt([
            'email' => auth()->user()->email,
            'password' => $request->password
        ])){
            return response()->json([
                'success' => 'success',
            ]);
        }else{
            return response()->json([
                'success' => 'error',
            ]);
        }

    }
}
