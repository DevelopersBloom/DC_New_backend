<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test', [\App\Http\Controllers\TestController::class, 'test']);
Route::get('/api/download-contract/{id}', [\App\Http\Controllers\FileController::class, 'downloadContract']);
Route::get('/api/download-bond/{id}', [\App\Http\Controllers\FileController::class, 'downloadBond']);
Route::get('/api/download-order/{id}', [\App\Http\Controllers\FileController::class, 'downloadOrder']);

Route::post('/api/download-monthly-export', [\App\Http\Controllers\ExcelController::class, 'downloadMonthlyExport']);
Route::post('/api/download-quarter-export', [\App\Http\Controllers\ExcelController::class, 'downloadQuarterExport']);
Route::group(['middleware' => 'jwt.auth','prefix' => 'api'], function () {
    Route::post('/create-contract', [\App\Http\Controllers\ContractController::class, 'create']);
    Route::post('/update-contract', [\App\Http\Controllers\ContractController::class, 'update']);
    Route::post('/extend-contract', [\App\Http\Controllers\ContractController::class, 'extend']);
    Route::post('/execute-contract', [\App\Http\Controllers\ContractController::class, 'execute']);
    Route::post('/make-regular-payment', [\App\Http\Controllers\PaymentController::class, 'makePayment']);
    Route::post('/make-full-payment', [\App\Http\Controllers\PaymentController::class, 'makeFullPayment']);
    Route::post('/make-partial-payment', [\App\Http\Controllers\PaymentController::class, 'makePartialPayment']);
    Route::get('/get-contracts', [\App\Http\Controllers\ContractController::class, 'get']);
    Route::get('/get-filters', [\App\Http\Controllers\ContractController::class, 'getFilters']);
    Route::get('/get-todays-contracts', [\App\Http\Controllers\ContractController::class, 'getTodaysContracts']);
    Route::get('/get-payments/{id}', [\App\Http\Controllers\PaymentController::class, 'getPayments']);
    Route::get('/edit-contract/{id}', [\App\Http\Controllers\ContractController::class, 'editContract']);
    Route::post('/get-clients', [\App\Http\Controllers\ContractController::class, 'searchClient']);
    Route::post('/filter-contracts', [\App\Http\Controllers\ContractController::class, 'filterContracts']);
    Route::post('/main-search', [\App\Http\Controllers\ContractController::class, 'mainSearch']);
    Route::get('/get-clients-info/{id}', [\App\Http\Controllers\ClientController::class, 'getInfo']);
    Route::get('/get-clients-list', [\App\Http\Controllers\ClientController::class, 'getClients']);
    Route::post('/save-profile-files', [\App\Http\Controllers\ClientController::class, 'saveFiles']);
    Route::get('/get-categories', [\App\Http\Controllers\ContractController::class, 'getCategories']);
    Route::post('/send-comment', [\App\Http\Controllers\InnerController::class, 'addComment']);
    Route::post('/request-discount', [\App\Http\Controllers\PaymentController::class, 'requestDiscount']);
    Route::post('/answer-discount', [\App\Http\Controllers\PaymentController::class, 'answerDiscount']);
    Route::get('/get-comments', [\App\Http\Controllers\InnerController::class, 'getComments']);
    Route::post('/get-deals', [\App\Http\Controllers\DealController::class, 'index']);
    Route::post('/add-cost', [\App\Http\Controllers\DealController::class, 'addCost']);

    Route::group(['prefix' => 'config'], function () {
        Route::post('/get-cashbox-list', [\App\Http\Controllers\ConfigController::class, 'getCashboxList']);
        Route::post('/set-cashbox-value', [\App\Http\Controllers\ConfigController::class, 'setCashboxValue']);
        Route::post('/calculate-cashboxes-final', [\App\Http\Controllers\ConfigController::class, 'calculateCashboxesFinal']);
        Route::post('/set-bank-cashbox-value', [\App\Http\Controllers\ConfigController::class, 'setBankCashboxValue']);
        Route::post('/set-orders', [\App\Http\Controllers\ConfigController::class, 'setOrders']);
    });

    Route::group(['prefix' => 'admin'], function () {
        Route::get('/get-users', [\App\Http\Controllers\AdminController::class, 'getUsers']);
        Route::get('/get-discounts', [\App\Http\Controllers\AdminController::class, 'getDiscounts']);
        Route::get('/get-evaluators', [\App\Http\Controllers\AdminController::class, 'getEvaluators']);
        Route::get('/edit-user/{id}', [\App\Http\Controllers\AdminController::class, 'editUser']);
        Route::get('/edit-evaluator/{id}', [\App\Http\Controllers\AdminController::class, 'editEvaluator']);
        Route::get('/edit-pawnshop/{id}', [\App\Http\Controllers\AdminController::class, 'editPawnshop']);
        Route::get('/edit-category/{id}', [\App\Http\Controllers\AdminController::class, 'editCategory']);
        Route::get('/get-categories', [\App\Http\Controllers\AdminController::class, 'getCategories']);
        Route::get('/get-pawnshops', [\App\Http\Controllers\AdminController::class, 'getPawnshops']);
        Route::get('/get-user-config', [\App\Http\Controllers\AdminController::class, 'getUserConfig']);
        Route::post('/create-user', [\App\Http\Controllers\AdminController::class, 'createUser']);
        Route::post('/create-evaluator', [\App\Http\Controllers\AdminController::class, 'createEvaluator']);
        Route::post('/update-user', [\App\Http\Controllers\AdminController::class, 'updateUser']);
        Route::post('/delete-user', [\App\Http\Controllers\AdminController::class, 'deleteUser']);
        Route::post('/update-evaluator', [\App\Http\Controllers\AdminController::class, 'updateEvaluator']);
        Route::post('/create-pawnshop', [\App\Http\Controllers\AdminController::class, 'createPawnshop']);
        Route::post('/update-pawnshop', [\App\Http\Controllers\AdminController::class, 'updatePawnshop']);
        Route::post('/update-cashbox', [\App\Http\Controllers\AdminController::class, 'updateCashbox']);
        Route::post('/create-category', [\App\Http\Controllers\AdminController::class, 'createCategory']);
        Route::post('/update-category', [\App\Http\Controllers\AdminController::class, 'updateCategory']);
        Route::post('/check-authority', [\App\Http\Controllers\AdminController::class, 'checkAuthority']);
    });
});

Route::get('/api/get-document', [\App\Http\Controllers\FileController::class, 'getDocument']);

Route::group(['prefix' => 'api/auth'], function () {
    Route::post('register', 'LoginController@register');
    Route::post('login', [\App\Http\Controllers\LoginController::class, 'login']);
    Route::get('get-user', [\App\Http\Controllers\LoginController::class, 'getUser']);
    Route::post('refresh', 'LoginController@refresh');
    Route::group(['middleware' => 'auth:api'], function () {
        Route::post('logout', [\App\Http\Controllers\LoginController::class, 'logout']);
    });
});
Route::get('/{vue_capture?}', function() {
    return view('welcome');
})->where('vue_capture', '[\/\w\.-]*');
