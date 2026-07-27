<?php

use App\Http\Controllers\AcraController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BankProvisionController;
use App\Http\Controllers\BusinessEventController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DocumentJournalController;
use App\Http\Controllers\LoanNdmController;
use App\Http\Controllers\MonthlyIncomeExpenseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
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
use App\Http\Controllers\TAccountReportController;
use App\Http\Controllers\TPartnerReportController;
use App\Http\Controllers\TurnoverReportController;
use App\Http\Controllers\CreditRegistryController;
use App\Http\Controllers\RolePermissionController;
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
    Route::group(['prefix' => 'admin'], function () {

        //general
        Route::get('/get',[AdminControllerNew::class,'get'])->middleware('can:view_personal_information');
        Route::put('/update',[AdminControllerNew::class,'update'])->middleware('can:update_personal_information');
        Route::post('/upload-file',[AdminControllerNew::class,'uploadFile'])->middleware('can:upload_personal_information_file');
        Route::get('/download-file/{id}',[AdminControllerNew::class,'downloadFile'])->middleware('can:download_personal_information_file');

        //users
        Route::get('/get-users', [AdminControllerNew::class, 'getUsers'])->middleware('can:view_users');
        Route::post('/create-user', [AdminControllerNew::class, 'createUser'])->middleware('can:create_user');
        Route::put('/update-user/{id}', [AdminControllerNew::class, 'updateUser'])->middleware('can:update_user');
        Route::post('/update-users', [AdminControllerNew::class, 'updateUsers'])->middleware('can:update_user');
        Route::delete('/delete-user/{id}', [AdminControllerNew::class, 'deleteUser'])->middleware('can:delete_user');

        // roles + role_has_permissions (admin middleware; Gate allows admin for page abilities)
        Route::get('/role-has-permissions', [RolePermissionController::class, 'index'])
            ->middleware('can:view_role_permissions');
        Route::put('/roles/{roleId}/role-has-permissions', [RolePermissionController::class, 'update'])
            ->middleware('can:update_role_permissions');
        // legacy URLs (older frontend builds)
        Route::get('/role-permissions', [RolePermissionController::class, 'index'])
            ->middleware('can:view_role_permissions');
        Route::put('/roles/{roleId}/permissions', [RolePermissionController::class, 'update'])
            ->middleware('can:update_role_permissions');

        // conditions
        //Interest rate
        Route::get('/get-categories',[AdminControllerNew::class,'getCategories'])->middleware('can:view_categories');
        Route::post('/save-rates',[AdminControllerNew::class,'saveRates'])->middleware('can:create_category_rate');
        //Route::put('/update-rate/{id}',[AdminControllerNew::class,'updateRate']);
        Route::delete('/delete-rate/{id}',[AdminControllerNew::class,'deleteRate'])->middleware('can:delete_category_rate');


        Route::get('/get-lump-rates',[AdminControllerNew::class,'getLumpRates'])->middleware('can:view_lump_rates');
        Route::post('/create-lump-rate',[AdminControllerNew::class,'createLumpRate'])->middleware('can:create_lump_rate');
        Route::put('/update-lump-rate/{id}',[AdminControllerNew::class,'updateLumpRate'])->middleware('can:update_lump_rate');
        Route::delete('/delete-lump-rate/{id}',[AdminControllerNew::class,'deleteLumpRate'])->middleware('can:delete_lump_rate');

        //duration
        Route::get('/get-category-duration',[AdminControllerNew::class,'getCategoryDuration'])->middleware('can:view_category_duration');
        Route::post('/save-duration',[AdminControllerNew::class,'saveCategoryDuration'])->middleware('can:create_category_duration');

        //Subcategories
        Route::get('/get-subcategories',[AdminControllerNew::class,'getCategoriesWithSubcategories'])->middleware('can:view_subcategories');
        Route::post('/create-subcategory',[AdminControllerNew::class,'addSubcategory'])->middleware('can:create_subcategory');
        Route::post('/create-item',[AdminControllerNew::class,'addSubcategoryItem'])->middleware('can:create_subcategory_item');
        Route::delete('/delete-item/{id}',[AdminControllerNew::class,'deleteSubcategoryItem'])->middleware('can:delete_subcategory_item');

        //Pawnshops
        Route::get('/get-pawnshops', [AdminControllerNew::class, 'getPawnshops'])->middleware('can:view_pawnshops');
        Route::put('update-pawnshop/{id}', [AdminControllerNew::class, 'updatePawnshop'])->middleware('can:update_pawnshop');
        Route::put('update-pawnshops', [AdminControllerNew::class, 'updatePawnshops'])->middleware('can:update_pawnshop');


        //Deals
        Route::get('get-deals',[AdminControllerNew::class,'getDeals'])->middleware('can:view_deals');
        Route::put('update-deals',[AdminControllerNew::class,'updateDeals'])->middleware('can:update_deals');
        Route::delete('delete-deal/{id}',[AdminControllerNew::class,'deleteDeal'])->middleware('can:delete_deal');
//        Route::get('/reports/monthly-income-expense', MonthlyIncomeExpenseController::class);

        //Discount
        Route::get('/get-discounts', [AdminControllerNew::class, 'getDiscounts'])->middleware('can:view_discounts');
        Route::post('answer-discount',[DiscountController::class,'answerDiscount'])->middleware('can:respond_discounts');
        Route::delete('delete-discount/{id}',[AdminControllerNew::class,'deleteDiscount'])->middleware('can:delete_discount');

        Route::prefix('reports')->group(function (){
            Route::get('/monthly-income-expense', MonthlyIncomeExpenseController::class)->middleware('can:view_v05_report');
            Route::get('/turnover', TurnoverReportController::class)->middleware('can:view_account_balances');
            Route::get('/t-account', TAccountReportController::class)->middleware('can:view_account_balances');
            Route::get('/t-partner', TPartnerReportController::class)->middleware('can:view_partner_balances');
            Route::get('/v03',  [ReportController::class, 'getV03Report'])->middleware('can:view_v03_report');
            Route::get('/v06',  [ReportController::class, 'getV06Report'])->middleware('can:view_v06_report');
            Route::get('/v07',  [ReportController::class, 'getV07Report'])->middleware('can:view_v07_report');
            Route::get('/v09',  [ReportController::class, 'getV09Report']);
            Route::get('/v13', [ReportController::class, 'getV13Report'])->middleware('can:view_v13_report');
            Route::get('/v17',[ReportController::class,'getV17Report'])->middleware('can:view_v17_report');
            Route::get('/v20',[ReportController::class,'getV20Report']);
        });
        Route::get('/transactions/reports/export', \App\Http\Controllers\ReportV01Controller::class)->middleware('can:view_v01_report');
//        Route::get('/transactions/reports/export', [ReportController::class, 'getFirstReport']);

        Route::prefix('chart-of-accounts')->middleware('can:view_chart_of_accounts')->group(function () {
            Route::get('/search-account', [ChartOfAccountController::class, 'searchAccount']);
            Route::get('/children',[ChartOfAccountController::class,'getChildren']);
            Route::get('/', [ChartOfAccountController::class, 'index']);
            Route::get('/{id}', [ChartOfAccountController::class, 'show']);
            Route::post('/', [ChartOfAccountController::class, 'store'])->middleware('can:create_chart_of_account');
            Route::put('/{id}', [ChartOfAccountController::class, 'update'])->middleware('can:update_chart_of_account');
            Route::delete('/{id}', [ChartOfAccountController::class, 'destroy'])->middleware('can:delete_chart_of_account');
        });

        Route::get('/contracts', [AdminControllerNew::class, 'getContracts'])->middleware('can:admin_view_contracts');
        Route::get('/export-acra-report',[AcraController::class,'downloadAcraReport']);
        Route::get('/logs', [ActivityLogController::class,'getLogs'])->middleware('can:view_logs');

        Route::get('/accounts/balances', [ChartOfAccountController::class, 'accountBalances'])->middleware('can:view_account_balances');
        Route::get('/transactions/account-balance/export', [TransactionController::class, 'exportAccountBalances'])->middleware('can:export_account_balances');
        Route::get('/accounts/partner-balances',[ChartOfAccountController::class,'partnerAccountBalances'])->middleware('can:view_partner_balances');
        Route::get('/transactions/partner-balances/export', [ChartOfAccountController::class, 'exportPartnerAccountBalances'])->middleware('can:export_partner_balances');
        Route::get('/accounts/reserve-balance-check', [ChartOfAccountController::class, 'reserveBalanceCheck'])->middleware('can:view_partner_balances');


        Route::apiResource('posting-rules', PostingRuleController::class)->except(['update']);
        Route::put('posting-rules', [PostingRuleController::class, 'update']);
        Route::apiResource('business-events', BusinessEventController::class);
        Route::apiResource('reminder-orders', ReminderOrderController::class);
        Route::apiResource('loan-ndms', LoanNdmController::class);
        Route::post('/loan-ndm/attach', [LoanNdmController::class, 'attachLoanNdm'])->middleware(['can:attach_loan_ndm', 'idempotent:loan_ndm.attach']);
        Route::get('/loan-ndm/attraction/{journalId}', [LoanNdmController::class, 'getLoanAttraction'])->middleware('can:view_loan_attraction');
        Route::put('/loan-ndm/attraction', [LoanNdmController::class, 'updateLoanAttraction'])->middleware('can:update_loan_attraction');

        Route::get('loan-ndm/interest', [LoanNdmController::class, 'calculateInterest'])->middleware('can:calculate_loan_interest');
        Route::post('loan-ndm/post-interest',[LoanNdmController::class,'postInterest'])->middleware('can:post_loan_interest');
        Route::post('loan-ndm/repay',[LoanNdmController::class, 'repay'])->middleware(['can:repay_loan_interest', 'idempotent:loan_ndm.repay']);
        Route::get('/loan-ndm/remaining/{id}', [ChartOfAccountController::class, 'remainingAmount'])->middleware('can:view_remaining_loan');
        Route::get('/loan-ndm/by-journal/{journal}', [LoanNdmController::class, 'get'])->middleware('can:view_loan_by_journal');
        Route::get('/transactions', [TransactionController::class, 'index'])->middleware('can:view_transactions');
        Route::get('/transactions/export', [TransactionController::class, 'export'])->middleware('can:export_transactions');
        Route::get('/account-transactions', [TransactionController::class, 'getAccountTransactions']);

        Route::get('/transactions/loan-ndms', [DocumentJournalController::class, 'index'])->middleware('can:view_document_journals');
        Route::get('/documents-journal/trashed', [DocumentJournalController::class, 'trashed'])->middleware('can:view_document_journals');
        Route::post('/documents-journal/{id}/restore', [DocumentJournalController::class, 'restore'])->middleware('can:restore_document_journal');
        Route::delete('/documents-journal/{id}/force', [DocumentJournalController::class, 'forceDestroy'])->middleware('can:force_delete_document_journal');
        Route::get('/document-journals/export', [DocumentJournalController::class, 'export'])->middleware('can:export_document_journals');

        Route::get('/document-journals/{id}', [DocumentJournalController::class, 'show'])->middleware('can:view_document_journals');

        Route::delete('/journal/{journal}', [DocumentJournalController::class, 'destroy'])->middleware('can:delete_document_journal');
        Route::put('/journal/{journal}', [DocumentJournalController::class, 'update'])->middleware('can:update_document_journal');

        Route::get('/transactions/reports', [\App\Http\Controllers\ReportV01Controller::class])->middleware('can:view_v01_report');

        Route::get('/files', [FileController::class, 'index'])->middleware('can:view_files');
        Route::post('/upload-file', [FileController::class, 'upload'])->middleware('can:upload_file');
        Route::get('/files/{id}/download', [FileController::class, 'download'])->middleware('can:download_file');
        Route::delete('/files/{id}', [FileController::class, 'destroy']);

        Route::post('/bank-provision/run', [BankProvisionController::class, 'run']);
        Route::post('/clients/correct-reserve', [ClientControllerNew::class, 'correctClientReserve']);
        Route::post('/clients/correct-all-reserves', [ClientControllerNew::class, 'correctAllClientReserves']);
        Route::get('/journal-vs-transaction-mismatch', [DocumentJournalController::class, 'journalVsTransactionMismatch']);
        Route::get('/inner/tx-vs-journal-diff', [DocumentJournalController::class, 'txVsJournalDiff']);
        Route::get('/inner/tx-dj-diff-ids', [DocumentJournalController::class, 'txDjDiffIds']);

    });
    Route::post('set-pawnshop', [AdminController::class, 'setPawnshop']);
    Route::get('/clients/search-partner', [ClientControllerNew::class, 'searchPartner']);
    Route::get('/clients/search', [ClientControllerNew::class, 'search']);

    Route::get('/users/get-fullname',[UserController::class, 'getClientsFullName']);

    Route::prefix('clients')->group(function () {
        Route::put('/{id}/update', [ClientControllerNew::class, 'updateClientData'])->middleware('can:update_client');
        Route::get('/', [ClientControllerNew::class, 'index'])->middleware('can:view_clients');
        Route::get('/upcoming-birthdays', [ClientControllerNew::class, 'getUpcomingBirthdays']);
        Route::get('/{id}',[ClientControllerNew::class,'show'])->middleware('can:view_clients');
        Route::post('/store-client', [ClientControllerNew::class, 'storeClient'])->middleware('can:create_client');
        Route::post('/store-non-client', [ClientControllerNew::class, 'storeNonClient'])->middleware('can:create_client');
        Route::post('/update-classification', [ClientControllerNew::class, 'updateClientClassification'])->middleware('can:classify_client');
        Route::post('/{id}/fetch-bank-id', [CreditRegistryController::class, 'fetchBankId']);
    });
    Route::post('/credit-registry/import-acc-classification/preview', [CreditRegistryController::class, 'previewAccClassification'])->middleware('can:classify_client');
    Route::post('/credit-registry/import-acc-classification', [CreditRegistryController::class, 'applyAccClassification'])->middleware('can:classify_client');
    Route::get('/export-clients', [ClientControllerNew::class, 'exportClients'])->middleware('can:export_clients');
    Route::get('/currencies', [\App\Http\Controllers\CurrencyController::class, 'index']);

    Route::group(['prefix' => 'contracts'], function () {
//        Route::get('/export', [ContractControllerNew::class, 'exportContracts']);
        Route::get('/', [ContractControllerNew::class, 'get'])->middleware('can:view_contracts');
        Route::post('/', [ContractControllerNew::class, 'store'])->middleware('can:create_contract');
        Route::get('/calculate-interest', [ContractControllerNew::class, 'calculateContractInterest'])->middleware('can:calculate_contract_interest');
        Route::get('/interest-balance', [ContractControllerNew::class, 'getInterestBalance']);
        Route::get('/calc-export', [ContractControllerNew::class, 'exportContractsCalc'])->middleware('can:export_contracts');
        Route::post('/confirm-interest', [ContractControllerNew::class, 'confirmCalculatedInterest'])->middleware('can:confirm_calculated_interest');
        Route::get('/download/{id}', [FileController::class, 'downloadContract'])->middleware('can:download_contract_file');
        Route::get('/download-schedule/{id}', [FileController::class, 'downloadSchedule']);
        Route::get('/download-credit-statement/{id}', [FileController::class, 'downloadCreditStatement'])->middleware('can:download_contract_file');
        Route::get('/download-individual/{id}', [FileController::class, 'downloadIndividualSheet'])->middleware('can:download_contract_file');
        Route::get('/download-main/{id}', [FileController::class, 'downloadContractDoc'])->middleware('can:download_contract_file');
        Route::get('/download-car-application/{id}', [FileController::class, 'downloadCarApplicationDoc'])->middleware('can:download_contract_file');
        Route::get('/download-guarantor/{id}/{guarantorId}', [FileController::class, 'downloadGuarantorDoc'])->middleware('can:download_contract_file');
        Route::get('/download-marital-status/{id}', [FileController::class, 'downloadMaritalStatusDoc'])->middleware('can:download_contract_file');
        Route::get('/download-all/{id}', [FileController::class, 'downloadAllFiles'])->middleware('can:download_all_contract_files');
        Route::get('/export', [FileController::class, 'exportZip'])->middleware('can:export_contracts_zip');
        Route::get('/export-all',[ContractControllerNew::class,'exportContracts'])->middleware('can:export_contracts');
//        Route::get('/{id}/credit-registry/l001', [CreditRegistryController::class, 'downloadL001'])->middleware('can:download_contract_file');
        Route::get('/{id}/credit-registry/l002', [CreditRegistryController::class, 'downloadL002'])->middleware('can:download_contract_file');
        Route::get('/{id}/credit-registry/l003', [CreditRegistryController::class, 'downloadL003'])->middleware('can:download_contract_file');
        Route::get('/{id}/credit-registry/l005', [CreditRegistryController::class, 'downloadL005'])->middleware('can:download_contract_file');
        Route::get('/{id}/credit-registry/l006', [CreditRegistryController::class, 'downloadL006'])->middleware('can:download_contract_file');
        Route::get('/{id}/credit-registry/preview/{code}', [CreditRegistryController::class, 'previewXml'])->middleware('can:download_contract_file');
        Route::get('/credit-registry/risk-modifications', [CreditRegistryController::class, 'downloadUnsentRiskModifications'])->middleware('can:download_contract_file');
        Route::get('/credit-registry/test', [CreditRegistryController::class, 'testConnection']);
        Route::post('/{id}/credit-registry/l001/send', [CreditRegistryController::class, 'sendL001']);
        Route::post('/{id}/credit-registry/l002/send', [CreditRegistryController::class, 'sendL002']);
        Route::post('/{id}/credit-registry/l003/send', [CreditRegistryController::class, 'sendL003']);
        Route::post('/{id}/credit-registry/l005/send', [CreditRegistryController::class, 'sendL005']);
        Route::post('/{id}/credit-registry/l006/send', [CreditRegistryController::class, 'sendL006']);
        Route::get('/{id}', [ContractControllerNew::class, 'show'])->middleware('can:view_contracts');
        Route::post('/make-payment', [PaymentControllerNew::class, 'makePayment'])->middleware(['can:make_contract_payment', 'idempotent:contracts.make-payment']);
        Route::post('/make-full-payment',[PaymentControllerNew::class, 'makeFullPayment'])->middleware(['can:make_full_contract_payment', 'idempotent:contracts.make-full-payment']);
        Route::post('/make-partial-payment',[PaymentControllerNew::class,'payPartial'])->middleware('can:make_partial_contract_payment');
        Route::post('/execute',[PaymentControllerNew::class,'executeItem'])->middleware('can:execute_contract_item');
        Route::match('put', '/payments/bulk-update', [PaymentControllerNew::class, 'bulkUpdate']);
        Route::get('/history-detail/{id}',[ContractControllerNew::class,'getHistoryDetails'])->middleware('can:view_contract_history_details');
        Route::post('/pay-amount',[ContractControllerNew::class,'payContractAmount'])->middleware('can:pay_contract_amount');
        Route::post('/reprovide-amount', [ContractControllerNew::class, 'reprovideContractAmount']);//            ->middleware(['can:reprovide_contract_amount', 'idempotent:contracts.reprovide-amount']);
        Route::post('/regenerate-schedule', [ContractControllerNew::class, 'regenerateSchedule']);
        Route::post('/request-discount', [DiscountController::class, 'requestDiscount'])->middleware('can:request_contract_discount');
        Route::put('/update-number/{id}',[ContractControllerNew::class,'updateContractNumber'])->middleware('can:update_contract_number');
        Route::put('/update-items', [ContractControllerNew::class, 'updateContractItems']);
        Route::get('/{contractId}/docs', [DocumentJournalController::class, 'getContractDocsHistory']);
    });
    Route::post('/credit-registry/is-response-prepared', [CreditRegistryController::class, 'isResponsePrepared']);
    Route::post('/credit-registry/get-response', [CreditRegistryController::class, 'getResponse']);
    Route::post('/upload-file', [FileController::class, 'upload'])->middleware('can:upload_file');
    Route::get('/files/{id}/download', [FileController::class, 'download'])->middleware('can:download_file');
    Route::delete('/files/{id}', [FileController::class, 'destroy']);

    Route::prefix('tasks')->group(function () {
        Route::get('/', [TaskController::class, 'index']);
        Route::get('{id}', [TaskController::class, 'show']);
        Route::post('/', [TaskController::class, 'store']);
        Route::put('{id}', [TaskController::class, 'update']);
        Route::delete('{id}', [TaskController::class, 'destroy']);

        // Comments belong to tasks
        Route::get('/{task}/comments', [TaskCommentController::class, 'index']);
        Route::post('/{task}/comments', [TaskCommentController::class, 'store']);
    });

    Route::delete('/comments/{comment}', [TaskCommentController::class, 'destroy']);

    Route::get('/get-discount-requests', [DiscountController::class, 'getDiscountRequests']);

    Route::group(['prefix' => 'notes'], function () {
       Route::get('/{contract_id}',[NoteController::class,'index'])->middleware('can:view_notes');
       Route::post('/',[NoteController::class,'store'])->middleware('can:create_note');
       Route::put('/{id}',[NoteController::class,'update'])->middleware('can:update_note');
       Route::delete('/{id}',[NoteController::class,'destroy'])->middleware('can:delete_note');
    });
    Route::get('/rates',[RateController::class,'getRates']);
    Route::group(['prefix' => 'categories'], function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{id}', [CategoryController::class, 'show']);
    });

    Route::get('/download-order/{id}', [FileController::class, 'downloadOrder']);
    Route::get('/get-cashBox/{id}',[DealController::class,'getCashBox'])->middleware('can:view_cashbox_balance');
    Route::get('/get-cashBox-summary/{month}/{year}', [DealController::class, 'calculatePawnshopCashbox']);
    Route::put('/contract-amount-histories/{id}', [DealController::class, 'updateContractAmountHistory']);
    Route::get('/get-deals', [DealController::class, 'index'])->middleware('can:view_deals');
    Route::post('/add-cost', [DealController::class, 'addCostNDM'])->middleware('can:create_loan_ndm');
    Route::post('/make-expense', [DealController::class, 'makeExpense'])->middleware('can:create_expense');
    Route::post('/add-cash-box', [DealController::class, 'addCashBox'])->middleware('can:add_funds');
    Route::post('/download-monthly-export', [ExcelController::class, 'downloadMonthlyExport']);
    Route::post('/download-quarter-export', [ExcelController::class, 'downloadQuarterExport']);

    Route::prefix('notifications')->group(function () {
        Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index']);
        Route::post('/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    });

});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

