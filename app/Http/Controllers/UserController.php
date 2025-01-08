<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController extends Controller
{
    public function getClientsFullName()
    {
        return User::select('id','name','surname')->get();
    }

}
