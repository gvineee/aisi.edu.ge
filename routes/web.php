<?php

use App\Http\Controllers\Public\AdmissionLeadController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\RobotsController;
use App\Http\Controllers\Public\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::post('/admissions/leads', [AdmissionLeadController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('admissions.leads.store');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
