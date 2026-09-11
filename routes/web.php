<?php
// LOCATION: routes/web.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
/*
|--------------------------------------------------------------------------
| PUBLIC PAGES (Blade)
|--------------------------------------------------------------------------
*/
Route::get('/',             [HomeController::class, 'index'])->name('home');
Route::get('/about',        [HomeController::class, 'about'])->name('about');
Route::get('/plans',        [HomeController::class, 'plans'])->name('plans');
Route::get('/how-it-works', [HomeController::class, 'howItWorks'])->name('how-it-works');
Route::get('/faq',          [HomeController::class, 'faq'])->name('faq');
Route::get('/contact',      [HomeController::class, 'contact'])->name('contact');
Route::post('/contact',     [HomeController::class, 'contactSubmit'])->name('contact.submit');

/*
|--------------------------------------------------------------------------
| BLADE AUTH VIEWS (fallback only — React handles real auth via /api)
|--------------------------------------------------------------------------
*/
Route::get('/login',           [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
Route::get('/register',        [RegisterController::class, 'showStage1'])->name('register');
Route::get('/register/verify-email', [RegisterController::class, 'showStage1'])->name('register.verify-email');
Route::get('/register/stage2', [RegisterController::class, 'showStage2'])->name('register.stage2');
Route::get('/register/stage3', [RegisterController::class, 'showStage3'])->name('register.stage3');
Route::get('/register/stage4', [RegisterController::class, 'showStage4'])->name('register.stage4');