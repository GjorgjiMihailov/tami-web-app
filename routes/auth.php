<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    // Нема јавна регистрација. Сметките ги отвора канцеларијата — клиент не
    // смее сам да си направи пристап до сметководствена апликација, а откако
    // порталот има јавна влезна страна, адресата ја знае секој.
    //
    // Рутата е тргната наместо да е заклучена со middleware: `Route::has('register')`
    // е она што Breeze-овите шаблони го прашуваат, па отсуството на рутата
    // автоматски ја крие секаде каде би се појавила.

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
