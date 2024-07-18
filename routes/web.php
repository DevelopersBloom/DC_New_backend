<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\InnerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TestController;
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
Route::get('/test', [TestController::class, 'test']);
Route::get('/api/download-contract/{id}', [FileController::class, 'downloadContract']);
Route::get('/api/download-bond/{id}', [FileController::class, 'downloadBond']);
Route::get('/api/download-order/{id}', [FileController::class, 'downloadOrder']);

Route::post('/api/download-monthly-export', [ExcelController::class, 'downloadMonthlyExport']);
Route::post('/api/download-quarter-export', [ExcelController::class, 'downloadQuarterExport']);
Route::group(['middleware' => 'jwt.auth', 'prefix' => 'api'], function () {
    Route::group(['prefix' => 'config'], function () {
        Route::post('/get-cashbox-list', [ConfigController::class, 'getCashboxList']);
        Route::post('/set-cashbox-value', [ConfigController::class, 'setCashboxValue']);
        Route::post('/calculate-cashboxes-final', [ConfigController::class, 'calculateCashboxesFinal']);
        Route::post('/set-bank-cashbox-value', [ConfigController::class, 'setBankCashboxValue']);
        Route::post('/set-orders', [ConfigController::class, 'setOrders']);
    });

//    Route::group(['prefix' => 'admin'], function () {
//        Route::get('/get-users', [AdminController::class, 'getUsers']);
//        Route::get('/get-discounts', [AdminController::class, 'getDiscounts']);
//        Route::get('/get-evaluators', [AdminController::class, 'getEvaluators']);
//        Route::get('/edit-user/{id}', [AdminController::class, 'editUser']);
//        Route::get('/edit-evaluator/{id}', [AdminController::class, 'editEvaluator']);
//        Route::get('/edit-pawnshop/{id}', [AdminController::class, 'editPawnshop']);
//        Route::get('/edit-category/{id}', [AdminController::class, 'editCategory']);
//        Route::get('/get-categories', [AdminController::class, 'getCategories']);
//        Route::get('/get-pawnshops', [AdminController::class, 'getPawnshops']);
//        Route::get('/get-user-config', [AdminController::class, 'getUserConfig']);
//        Route::post('/create-user', [AdminController::class, 'createUser']);
//        Route::post('/create-evaluator', [AdminController::class, 'createEvaluator']);
//        Route::post('/update-user', [AdminController::class, 'updateUser']);
//        Route::post('/delete-user', [AdminController::class, 'deleteUser']);
//        Route::post('/update-evaluator', [AdminController::class, 'updateEvaluator']);
//        Route::post('/create-pawnshop', [AdminController::class, 'createPawnshop']);
//        Route::post('/update-pawnshop', [AdminController::class, 'updatePawnshop']);
//        Route::post('/update-cashbox', [AdminController::class, 'updateCashbox']);
//        Route::post('/create-category', [AdminController::class, 'createCategory']);
//        Route::post('/update-category', [AdminController::class, 'updateCategory']);
//        Route::post('/check-authority', [AdminController::class, 'checkAuthority']);
//    });
});

Route::get('/api/get-document', [FileController::class, 'getDocument']);

//Route::group(['prefix' => 'api/auth'], function () {
//    Route::post('register', [LoginController::class, 'register']);
//    Route::post('login', [LoginController::class, 'login']);
//    Route::group(['middleware' => 'auth:api'], function () {
//        Route::get('get-user', [LoginController::class, 'getUser']);
//        Route::post('logout', [LoginController::class, 'logout']);
//        Route::post('refresh', [LoginController::class, 'refresh']);
//    });
//});


Route::get('/{vue_capture?}', function () {
    return view('welcome');
})->where('vue_capture', '[\/\w\.-]*');
