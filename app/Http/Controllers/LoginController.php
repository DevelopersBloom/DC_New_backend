<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    public function register(Request $request) {
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => bcrypt($request->input('password')),
        ]);

        $token = auth('api')->login($user);

        return $this->respondWithToken($token);
    }

    public function login(Request $request) {
        $credentials = $request->only('email', 'password');
        $rememberMe = $request->has('remember_me');

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized', 'success' => 'error'], 401);
        }

        $cookie = cookie(
            'token',
            $token,
            $rememberMe ? 20160 : 120, // Expiration time in minutes (2 weeks vs 2 hours)
            null,
            null,
            false,
            true // HttpOnly
        );

        return $this->respondWithToken($token)->withCookie($cookie);
    }
    public function getUser(){
        return response()->json(['user' => auth()->user()]);
    }

    public function refresh() {
        return $this->respondWithToken(auth()->refresh());
    }

    public function logout() {
        auth()->logout();
        return response()->json(['message' => 'Successfully logged out'])
        ->cookie('token', '', -1);
    }

    protected function respondWithToken($token) {
        return response()->json([
            'success' => 'success',
//            'access_token' => $token,
//            'token_type' => 'bearer',
            'user' => auth()->user()
        ])->cookie('token', $token, 60, null, null, false, true);
    }
}
