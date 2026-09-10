<?php

use App\Http\Controllers\Portal\AttendanceController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DirectorDocumentWorklistController;
use App\Http\Controllers\Portal\DocumentApprovalController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\DocumentVersionController;
use App\Http\Controllers\Portal\LessonController;
use App\Http\Controllers\Portal\MyDocumentWorkController;
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
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('lessons', [LessonController::class, 'store'])->name('lessons.store');

    Route::get('lessons/{lesson}/attendance', [AttendanceController::class, 'show'])->name('attendance.show');
    Route::post('lessons/{lesson}/attendance', [AttendanceController::class, 'store'])->name('attendance.store');

    Route::get('documents/my-work', MyDocumentWorkController::class)->name('documents.my-work');
    Route::get('documents/director-worklist', DirectorDocumentWorklistController::class)->name('documents.director-worklist');
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('documents/{document}/versions', [DocumentVersionController::class, 'store'])->name('documents.versions.store');
    Route::get('documents/{document}/versions/{version}/download', [DocumentVersionController::class, 'download'])
        ->middleware('signed')
        ->name('documents.versions.download');
    Route::post('documents/{document}/submit', [DocumentApprovalController::class, 'submit'])->name('documents.submit');
    Route::post('approval-requests/{approvalRequest}/decide', [DocumentApprovalController::class, 'decide'])->name('documents.approvals.decide');
});

require __DIR__.'/settings.php';

// Catch-all CMS page route — must stay last so it never shadows a more
// specific route above (sitemap.xml, news, library, dashboard, etc.).
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('page.show');
