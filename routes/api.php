<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BusinessEventController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DocumentJournalController;
use App\Http\Controllers\LoanNdmController;
use App\Http\Controllers\MonthlyIncomeExpenseController;
use App\Http\Controllers\TransactionsExport;
use App\Http\Controllers\PostingRuleController;
use App\Http\Controllers\RateController;
use App\Http\Controllers\ClientControllerNew;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentControllerNew;
use App\Http\Controllers\ReminderOrderController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ContractControllerNew;
use App\Http\Controllers\DealController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\AdminControllerNew;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ChartOfAccountController;
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
    Route::group(['middleware' => 'admin','prefix' => 'admin'], function () {
        //general
        Route::get('/get',[AdminControllerNew::class,'get']);
        Route::put('/update',[AdminControllerNew::class,'update']);
        Route::post('/upload-file',[AdminControllerNew::class,'uploadFile']);
        Route::get('/download-file/{id}',[AdminControllerNew::class,'downloadFile']);

        //users
        Route::get('/get-users',[AdminControllerNew::class,'getUsers']);
        Route::post('/create-user',[AdminControllerNew::class,'createUser']);
        Route::post('/update-users', [AdminControllerNew::class, 'updateUsers']);
        Route::put('/update-user/{id}',[AdminControllerNew::class,'updateUser']);
        Route::delete('/delete-user/{id}',[AdminControllerNew::class,'deleteUser']);

        // conditions
        //Interest rate
        Route::get('/get-categories',[AdminControllerNew::class,'getCategories']);
        Route::post('/save-rates',[AdminControllerNew::class,'saveRates']);
        //Route::put('/update-rate/{id}',[AdminControllerNew::class,'updateRate']);
        Route::delete('/delete-rate/{id}',[AdminControllerNew::class,'deleteRate']);


        Route::get('/get-lump-rates',[AdminControllerNew::class,'getLumpRates']);
        Route::post('/create-lump-rate',[AdminControllerNew::class,'createLumpRate']);
        Route::put('/update-lump-rate/{id}',[AdminControllerNew::class,'updateLumpRate']);
        Route::delete('/delete-lump-rate/{id}',[AdminControllerNew::class,'deleteLumpRate']);

        //duration
        Route::get('/get-category-duration',[AdminControllerNew::class,'getCategoryDuration']);
        Route::post('/save-duration',[AdminControllerNew::class,'saveCategoryDuration']);

        //Subcategories
        Route::get('/get-subcategories',[AdminControllerNew::class,'getCategoriesWithSubcategories']);
        Route::post('/create-subcategory',[AdminControllerNew::class,'addSubcategory']);
        Route::post('/create-item',[AdminControllerNew::class,'addSubcategoryItem']);
        Route::delete('/delete-item/{id}',[AdminControllerNew::class,'deleteSubcategoryItem']);

        //Pawnshops
        Route::get('/get-pawnshops', [AdminControllerNew::class, 'getPawnshops']);
        Route::put('update-pawnshop/{id}',[AdminControllerNew::class,'updatePawnshop']);
        Route::put('update-pawnshops', [AdminControllerNew::class, 'updatePawnshops']);


        //Deals
        Route::get('get-deals',[AdminControllerNew::class,'getDeals']);
        Route::put('update-deals',[AdminControllerNew::class,'updateDeals']);
        Route::delete('delete-deal/{id}',[AdminControllerNew::class,'deleteDeal']);
        Route::get('/reports/monthly-income-expense', MonthlyIncomeExpenseController::class);

        //Discount
        Route::get('/get-discounts', [AdminControllerNew::class, 'getDiscounts']);
        Route::post('answer-discount',[DiscountController::class,'answerDiscount']);
        Route::delete('delete-discount/{id}',[AdminControllerNew::class,'deleteDiscount']);

        Route::prefix('chart-of-accounts')->group(function () {

            Route::get('/search-account', [ChartOfAccountController::class, 'searchAccount']);
            Route::get('/', [ChartOfAccountController::class, 'index']);
            Route::post('/', [ChartOfAccountController::class, 'store']);
            Route::get('/{id}', [ChartOfAccountController::class, 'show']);
            Route::put('/{id}', [ChartOfAccountController::class, 'update']);
            Route::delete('/{id}', [ChartOfAccountController::class, 'destroy']);
        });
        Route::get('/accounts/balances', [ChartOfAccountController::class, 'accountBalances']);
        Route::get('/accounts/partner-balances',[ChartOfAccountController::class,'partnerAccountBalances']);

        Route::apiResource('posting-rules', PostingRuleController::class);
        Route::apiResource('business-events', BusinessEventController::class);
        Route::apiResource('reminder-orders', ReminderOrderController::class);
        Route::apiResource('loan-ndms', LoanNdmController::class);
        Route::post('/loan-ndm/attach', [LoanNdmController::class, 'attachLoanNdm']);
        Route::get('loan-ndm/interest', [LoanNdmController::class, 'calculateInterest']);
        Route::post('loan-ndm/post-interest',[LoanNdmController::class,'postInterest']);
        Route::post('loan-ndm/repay',[LoanNdmController::class, 'repay']);
        Route::get('/loan-ndm/remaining/{id}', [LoanNdmController::class, 'remainingAmount']);
        Route::get('/loan-ndm/by-journal/{journal}', [LoanNdmController::class, 'get']);
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/export', [TransactionController::class, 'export']);
        Route::get('/transactions/loan-ndms', [DocumentJournalController::class, 'index']);
        Route::get('/transactions/loan-ndms/export', [DocumentJournalController::class, 'export']);
        Route::delete('/journal/{journal}', [DocumentJournalController::class, 'destroy']);
        Route::put('/journal/{journal}', [DocumentJournalController::class, 'update']);

        Route::get('/transactions/reports', [TransactionController::class, 'reportsJournal']);
        Route::get('/transactions/reports/export', [TransactionController::class, 'exportReportsJournal']);

        // Pawnshop Management
//        Route::get('/get-pawnshops', [AdminController::class, 'getPawnshops']);
//        Route::post('/create-pawnshop', [AdminController::class, 'createPawnshop']);
//        Route::post('/update-pawnshop', [AdminController::class, 'updatePawnshop']);
//        Route::get('/edit-pawnshop/{id}', [AdminController::class, 'editPawnshop']);
//        Route::post('/update-cashbox', [AdminController::class, 'updateCashbox']);
//
//        // Categories
//        Route::get('/get-categories', [AdminController::class, 'getCategories']);
//        Route::post('/create-category', [AdminController::class, 'createCategory']);
//        Route::post('/update-category', [AdminController::class, 'updateCategory']);
//        Route::get('/edit-category/{id}', [AdminController::class, 'editCategory']);
//
//        // Discounts and Configuration
//        Route::get('/get-discounts', [AdminController::class, 'getDiscounts']);
//        Route::get('/get-user-config', [AdminController::class, 'getUserConfig']);
//        Route::post('/check-authority', [AdminController::class, 'checkAuthority']);
    });
    Route::post('set-pawnshop', [AdminController::class, 'setPawnshop']);
    Route::get('/clients/search-partner', [ClientControllerNew::class, 'searchPartner']);
    Route::get('/clients/search', [ClientControllerNew::class, 'search']);

    Route::get('/users/get-fullname',[UserController::class, 'getClientsFullName']);

    Route::prefix('clients')->group(function () {
        Route::put('/{id}/update', [ClientControllerNew::class, 'updateClientData']);
        Route::get('/', [ClientControllerNew::class, 'index']);
        Route::get('/{id}',[ClientControllerNew::class,'show']);
        Route::post('/store-client', [ClientControllerNew::class, 'storeClient']);
        Route::post('/store-non-client', [ClientControllerNew::class, 'storeNonClient']);
    });
    Route::get('/export-clients', [ClientControllerNew::class, 'exportClients']);


    Route::get('/download-order/{id}', [FileController::class, 'downloadOrder']);
    Route::group(['prefix' => 'contracts'], function () {
//        Route::get('/export', [ContractControllerNew::class, 'exportContracts']);
        Route::get('/', [ContractControllerNew::class, 'get']);
        Route::post('/', [ContractControllerNew::class, 'store']);
        Route::get('/download/{id}', [FileController::class, 'downloadContract']);
        Route::get('/download-all/{id}', [FileController::class, 'downloadAllFiles']);
        Route::get('/export', [FileController::class, 'exportZip']);
        Route::get('/{id}', [ContractControllerNew::class, 'show']);
        Route::post('/make-payment', [PaymentControllerNew::class, 'makePayment']);
        Route::post('/make-full-payment',[PaymentControllerNew::class, 'makeFullPayment']);
        Route::post('/make-partial-payment',[PaymentControllerNew::class,'payPartial']);
        Route::post('/execute',[PaymentControllerNew::class,'executeItem']);
        Route::get('/history-detail/{id}',[ContractControllerNew::class,'getHistoryDetails']);
        Route::post('/pay-amount',[ContractControllerNew::class,'payContractAmount']);
        Route::post('/request-discount', [DiscountController::class, 'requestDiscount']);
        Route::put('/update-number/{id}',[ContractControllerNew::class,'updateContractNumber']);
        Route::put('/update-items', [ContractControllerNew::class, 'updateContractItems']);

    });

    Route::get('/get-discount-requests', [DiscountController::class, 'getDiscountRequests']);

    Route::group(['prefix' => 'notes'], function () {
       Route::get('/{contract_id}',[NoteController::class,'index']);
       Route::post('/',[NoteController::class,'store']);
       Route::put('/{id}',[NoteController::class,'update']);
       Route::delete('/{id}',[NoteController::class,'destroy']);
    });
    Route::get('/rates',[RateController::class,'getRates']);
    Route::group(['prefix' => 'categories'], function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{id}', [CategoryController::class, 'show']);
    });
    Route::group(['prefix' => 'payments'], function () {
        Route::get('/{id}', [PaymentController::class, 'getPayments']);
    });
    Route::get('/get-cashBox/{id}',[DealController::class,'getCashBox']);
    Route::get('/get-cashBox-summary/{month}/{year}', [DealController::class, 'calculatePawnshopCashbox']);
    Route::get('/get-deals', [DealController::class, 'index']);
    Route::post('/add-cost', [DealController::class, 'addCostNDM']);
    Route::post('/make-expense', [DealController::class, 'makeExpense']);
    Route::post('/add-cash-box', [DealController::class, 'addCashBox']);
    Route::post('/download-monthly-export', [ExcelController::class, 'downloadMonthlyExport']);
    Route::post('/download-quarter-export', [ExcelController::class, 'downloadQuarterExport']);

});


Route::get('/test', [TestController::class, 'test']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

