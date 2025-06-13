<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GroupExpenseReportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PixController;
use App\Http\Controllers\UserController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::post('/invitations/verify', [InvitationController::class, 'verify']);
Route::post('/forgot-password', [InvitationController::class, 'forgotPassword']);
Route::get('/pix/generate', [PixController::class, 'gerarPix']);



Route::middleware('jwt.auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [AuthController::class, 'dashboard']);
    Route::post('/user/pix', [UserController::class, 'atualizarPix']);

    Route::post('/invitations', [InvitationController::class, 'invite']);

    Route::apiResource('groups', GroupController::class);

    Route::prefix('groups/{groupId}/members')->group(function () {
        Route::get('/', [GroupMemberController::class, 'index']);       
        Route::post('/', [GroupMemberController::class, 'store']);      
        Route::delete('/{userId}', [GroupMemberController::class, 'destroy']);
    });

    Route::apiResource('expenses', ExpenseController::class);

    Route::get('/groups/{groupId}/expenses/monthly', [ExpenseController::class, 'getMonthlyExpenses']);

    Route::get('/groups/{groupId}/expenses/report/{year}', [GroupExpenseReportController::class, 'reportByGroupAndYear']);
    Route::get('/group/{groupId}/report-monthly/{year}', [GroupExpenseReportController::class, 'reportByGroupAndYearMonthlySettlement']);


});
