<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\EnsureActiveAdministrator;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [SessionController::class, 'create'])->name('login');
    Route::post('/admin/login', [SessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::middleware(EnsureActiveAdministrator::class)->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [SessionController::class, 'dashboard'])->name('dashboard');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::patch('/users/{user}/disable', [UserController::class, 'disable'])->name('users.disable');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
