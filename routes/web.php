<?php

use App\Http\Controllers\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');

// Links in account emails. The reset link opens the SPA, which reads the token from the URL.
Route::view('reset-password/{token}', 'app')->name('password.reset');
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
