<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ContractControllerNew extends Controller
{

    public function store($id)
    {
        if ($id == 0) {
            ClientControllerNew::create()
        }


    }


}
