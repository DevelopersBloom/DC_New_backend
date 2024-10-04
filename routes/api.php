<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ClientControllerNew;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\TestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ContractControllerNew;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(['prefix' => 'auth'], function () {
    Route::post('register', [LoginController::class, 'register']);
    Route::post('login', [LoginController::class, 'login']);

    Route::group(['middleware' => 'auth:api'], function () {
        Route::get('get-user', [LoginController::class, 'getUser']);
        Route::post('logout', [LoginController::class, 'logout']);
        Route::post('refresh', [LoginController::class, 'refresh']);
    });
});

Route::group(['middleware' => 'jwt.auth'], function () {
//    Route::group(['middleware' => 'admin','prefix' => 'admin'], function () {

        Route::get('/get-users', [AdminController::class, 'getUsers']);
        Route::get('/get-discounts', [AdminController::class, 'getDiscounts']);
        Route::get('/get-evaluators', [AdminController::class, 'getEvaluators']);
        Route::get('/edit-user/{id}', [AdminController::class, 'editUser']);
        Route::get('/edit-evaluator/{id}', [AdminController::class, 'editEvaluator']);
        Route::get('/edit-pawnshop/{id}', [AdminController::class, 'editPawnshop']);
        Route::get('/edit-category/{id}', [AdminController::class, 'editCategory']);
        Route::get('/get-categories', [AdminController::class, 'getCategories']);
        Route::get('/get-pawnshops', [AdminController::class, 'getPawnshops']);
        Route::get('/get-user-config', [AdminController::class, 'getUserConfig']);
        Route::post('set-pawnshop', [AdminController::class, 'setPawnshop']);
        Route::post('/create-user', [AdminController::class, 'createUser']);
        Route::post('/create-evaluator', [AdminController::class, 'createEvaluator']);
        Route::post('/update-user', [AdminController::class, 'updateUser']);
        Route::post('/delete-user', [AdminController::class, 'deleteUser']);
        Route::post('/update-evaluator', [AdminController::class, 'updateEvaluator']);
        Route::post('/create-pawnshop', [AdminController::class, 'createPawnshop']);
        Route::post('/update-pawnshop', [AdminController::class, 'updatePawnshop']);
        Route::post('/update-cashbox', [AdminController::class, 'updateCashbox']);
        Route::post('/create-category', [AdminController::class, 'createCategory']);
        Route::post('/update-category', [AdminController::class, 'updateCategory']);
        Route::post('/check-authority', [AdminController::class, 'checkAuthority']);
        Route::group(['prefix' => 'clients'], function () {
            Route::post('/', [ClientControllerNew::class, 'storeOrUpdate']);
            Route::get('/search', [ClientControllerNew::class, 'search']);
        });
        Route::post('/contract', [ContractControllerNew::class, 'store']);
        Route::get('/contracts/download/{id}', [FileController::class, 'downloadContract']);
//    });
});


Route::get('/test', [TestController::class, 'test']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

