<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // Public sign-up: a new customer registers themselves WITH their organization and
    // becomes its Owner — the role is never client input.
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:signup');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    // Email verification (owner's rule, 2026-09-29) — open to an account that has not confirmed yet: it is where
    // the `verified` middleware sends it. Each link opens nothing by itself: the account must be signed in.
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')->middleware('throttle:verification-link')->name('verification.verify');
    // Every email that confirms an address draws on the account's and the visitor's budgets (User::sendALink),
    // whichever door asks — so none of the doors needs a throttle of its own.
    Route::post('verify-email/resend', [EmailVerificationController::class, 'resend'])->name('verification.send');
    // A super admin viewing as the account through "Log in as" confirms it for them (owner, 2026-10-06); the
    // controller reads the impersonation again, so the account itself is refused.
    Route::post('verify-email/confirm-for-them', [EmailVerificationController::class, 'confirmForThem'])
        ->middleware('throttle:admin')->name('verification.confirm-for-them');
    Route::get('confirm-email/{id}/{hash}', [EmailVerificationController::class, 'confirmNewEmail'])
        ->whereNumber('id')->middleware('throttle:verification-link')->name('email.confirm');

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
