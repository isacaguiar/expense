<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupExpenseReportController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PixController;
use App\Http\Controllers\ProofDownloadController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/invitations/verify', [InvitationController::class, 'verify']);
Route::post('/forgot-password', [InvitationController::class, 'forgotPassword']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

Route::middleware('jwt.auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [AuthController::class, 'dashboard']);
    Route::post('/user/pix', [UserController::class, 'atualizarPix']);
    Route::put('/user/profile', [UserController::class, 'updateProfile']);
    Route::put('/user/password', [UserController::class, 'changePassword']);
    Route::get('/user/google/redirect-url', [GoogleAuthController::class, 'redirectUrl']);
    Route::get('/pix/generate', [PixController::class, 'gerarPix']);

    Route::apiResource('groups', GroupController::class);

    Route::prefix('groups/{groupId}/members')->group(function () {
        Route::get('/', [GroupMemberController::class, 'index']);
        Route::post('/', [GroupMemberController::class, 'store']);
        Route::delete('/{userId}', [GroupMemberController::class, 'destroy']);
    });

    Route::apiResource('expenses', ExpenseController::class);
    Route::post('/expenses/{expenseId}/stop-recurrence', [ExpenseController::class, 'stopRecurrence']);
    Route::post('/expenses/{expenseId}/pay', [ExpenseController::class, 'pay']);
    Route::post('/expenses/{expenseId}/unpay', [ExpenseController::class, 'unpay']);
    Route::post('/groups/{groupId}/settlements/confirm', [ExpenseController::class, 'confirmSettlement']);

    Route::get('/groups/{groupId}/expenses', [ExpenseController::class, 'indexByGroup']);
    Route::get('/groups/{groupId}/expenses/monthly', [ExpenseController::class, 'getMonthlyExpenses']);
    Route::get('/groups/{groupId}/expenses/summary', [ExpenseController::class, 'summary']);
    Route::post('/groups/{groupId}/expenses/close', [ExpenseController::class, 'close']);
    Route::post('/groups/{groupId}/expenses/reopen', [ExpenseController::class, 'reopen']);
    Route::get('/groups/{groupId}/expenses/cycles', [ExpenseController::class, 'cycleHistory']);
    Route::get('/groups/{groupId}/expenses/gross-debts', [ExpenseController::class, 'grossDebts']);

    Route::get('/groups/{groupId}/expenses/report/{year}', [GroupExpenseReportController::class, 'reportByGroupAndYear']);
    Route::get('/group/{groupId}/report-monthly/{year}', [GroupExpenseReportController::class, 'reportByGroupAndYearMonthlySettlement']);

});

// Download de comprovante de pagamento: fora de `jwt.auth` de propósito — a aba
// do browser aberta pelo link não envia `Authorization: Bearer`. Autorizado
// pela URL assinada de curta duração emitida nos accessors de `Quota` /
// `SettlementConfirmation`. Ver ADR-005.
Route::get('/groups/{groupId}/proofs/{type}/{id}', [ProofDownloadController::class, 'show'])
    ->middleware('signed')
    ->name('proofs.show');
