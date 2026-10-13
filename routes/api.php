<?php

use App\Adapters\Inbound\Http as H;
use Illuminate\Support\Facades\Route;

/*
 * Routes from api-contract.md §3, under /api/v1 (prefix set in bootstrap/app.php).
 * Authentication first, then role, and only then does the controller look up the resource (403 before 404).
 */

Route::get('/health', H\HealthController::class);
Route::post('/auth/login', [H\AuthController::class, 'login']);

Route::middleware('token')->group(function () {
    Route::get('/categories', [H\CategoryController::class, 'index']);

    Route::get('/books', [H\BookController::class, 'index']);
    Route::get('/books/{id}', [H\BookController::class, 'show']);
    // Not in the §3.4 table, but the contract names it: the cover is rendered via GET /books/{id}/cover.
    Route::get('/books/{id}/cover', [H\BookController::class, 'getCover']);

    Route::middleware('role:admin')->group(function () {
        Route::post('/users', [H\UserController::class, 'store']);
        Route::post('/books', [H\BookController::class, 'store']);
        Route::put('/books/{id}', [H\BookController::class, 'update']);
        Route::delete('/books/{id}', [H\BookController::class, 'destroy']);
        Route::put('/books/{id}/cover', [H\BookController::class, 'putCover']);
        Route::delete('/books/{id}/cover', [H\BookController::class, 'deleteCover']);
        Route::get('/reports/most-borrowed', [H\ReportController::class, 'mostBorrowed']);
    });

    Route::middleware('role:admin,librarian')->group(function () {
        Route::get('/members', [H\MemberController::class, 'index']);
        Route::get('/members/{id}', [H\MemberController::class, 'show']);
        Route::post('/members', [H\MemberController::class, 'store']);

        // /loans/overdue goes BEFORE /loans/{id}: otherwise "overdue" would be taken as an id.
        Route::get('/loans/overdue', [H\LoanController::class, 'overdue']);
        Route::post('/loans', [H\LoanController::class, 'store']);
        Route::get('/loans', [H\LoanController::class, 'index']);
        Route::get('/loans/{id}', [H\LoanController::class, 'show']);
        Route::post('/loans/{loanId}/items/{itemId}/return', [H\LoanController::class, 'returnItem']);
    });
});
