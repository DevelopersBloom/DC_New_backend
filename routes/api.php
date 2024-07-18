<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\InnerController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
    Route::group(['middleware' => 'admin', 'prefix' => 'admin'], function () {
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
    });

    Route::post('/create-contract', [ContractController::class, 'create']);
    Route::post('/update-contract', [ContractController::class, 'update']);
    Route::post('/extend-contract', [ContractController::class, 'extend']);
    Route::post('/execute-contract', [ContractController::class, 'execute']);
    Route::post('/make-regular-payment', [PaymentController::class, 'makePayment']);
    Route::post('/make-full-payment', [PaymentController::class, 'makeFullPayment']);
    Route::post('/make-partial-payment', [PaymentController::class, 'makePartialPayment']);
    Route::get('/get-contracts', [ContractController::class, 'get']);
    Route::get('/get-filters', [ContractController::class, 'getFilters']);
    Route::get('/get-todays-contracts', [ContractController::class, 'getTodaysContracts']);
    Route::get('/get-payments/{id}', [PaymentController::class, 'getPayments']);
    Route::get('/edit-contract/{id}', [ContractController::class, 'editContract']);
    Route::post('/get-clients', [ContractController::class, 'searchClient']);
    Route::post('/filter-contracts', [ContractController::class, 'filterContracts']);
    Route::post('/main-search', [ContractController::class, 'mainSearch']);
    Route::get('/get-clients-info/{id}', [ClientController::class, 'getInfo']);
    Route::get('/get-clients-list', [ClientController::class, 'getClients']);
    Route::post('/save-profile-files', [ClientController::class, 'saveFiles']);
    Route::get('/get-categories', [ContractController::class, 'getCategories']);
    Route::post('/send-comment', [InnerController::class, 'addComment']);
    Route::post('/request-discount', [PaymentController::class, 'requestDiscount']);
    Route::post('/answer-discount', [PaymentController::class, 'answerDiscount']);
    Route::get('/get-comments', [InnerController::class, 'getComments']);
    Route::post('/get-deals', [DealController::class, 'index']);
    Route::post('/add-cost', [DealController::class, 'addCost']);
});


Route::get('/test', [TestController::class, 'test']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

