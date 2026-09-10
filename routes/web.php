<?php

use App\Http\Controllers\Public\AdmissionLeadController;
use App\Http\Controllers\Public\LibraryCatalogController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PostController;
use App\Http\Controllers\Public\RobotsController;
use App\Http\Controllers\Public\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');

Route::post('/admissions/leads', [AdmissionLeadController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('admissions.leads.store');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

Route::get('/news', [PostController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [PostController::class, 'show'])->name('news.show');

Route::get('/library', [LibraryCatalogController::class, 'index'])->name('library.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

// Catch-all CMS page route — must stay last so it never shadows a more
// specific route above (sitemap.xml, news, library, dashboard, etc.).
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('page.show');
