<?php

use App\Http\Controllers\Portal\AttendanceController;
use App\Http\Controllers\Portal\CmsMediaController;
use App\Http\Controllers\Portal\CmsPageController;
use App\Http\Controllers\Portal\CmsPostController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DirectorDocumentWorklistController;
use App\Http\Controllers\Portal\DocumentApprovalController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\DocumentVersionController;
use App\Http\Controllers\Portal\LessonController;
use App\Http\Controllers\Portal\MessageController;
use App\Http\Controllers\Portal\MyDocumentWorkController;
use App\Http\Controllers\Portal\PortalRoleController;
use App\Http\Controllers\Portal\PortfolioController;
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
    Route::post('portal/active-role', [PortalRoleController::class, 'update'])->name('portal.active-role.update');

    Route::post('lessons', [LessonController::class, 'store'])->name('lessons.store');

    Route::get('portal/students/{student}/portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
    Route::post('portal/students/{student}/portfolio', [PortfolioController::class, 'store'])->name('portfolio.store');
    Route::get('portal/portfolio/review-queue', [PortfolioController::class, 'reviewQueue'])->name('portfolio.review-queue');
    Route::get('portal/portfolio/{portfolioItem}', [PortfolioController::class, 'show'])->name('portfolio.show');
    Route::post('portal/portfolio/{portfolioItem}/assets', [PortfolioController::class, 'storeAsset'])->name('portfolio.assets.store');
    Route::get('portal/portfolio/{portfolioItem}/assets/{asset}/download', [PortfolioController::class, 'downloadAsset'])
        ->middleware('signed')
        ->name('portfolio.assets.download');
    Route::post('portal/portfolio/{portfolioItem}/submit', [PortfolioController::class, 'submit'])->name('portfolio.submit');
    Route::post('portal/portfolio/{portfolioItem}/decide', [PortfolioController::class, 'decide'])->name('portfolio.decide');

    Route::get('portal/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('portal/messages', [MessageController::class, 'store'])->name('messages.store');
    Route::get('portal/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('portal/messages/{conversation}/reply', [MessageController::class, 'reply'])->name('messages.reply');

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

    // --- CMS (Pages / Posts / Media) — admin/director/editor/academic-manager
    // only; see CmsPageController's docblock for the exact role split.
    // Publishes docs/02 §5.1 + CLAUDE.md Phase 1's "draft → preview →
    // publish, and revert to a version" requirement, previously unbuilt.
    Route::get('portal/cms/pages', [CmsPageController::class, 'index'])->name('cms.pages.index');
    Route::get('portal/cms/pages/create', [CmsPageController::class, 'create'])->name('cms.pages.create');
    Route::post('portal/cms/pages', [CmsPageController::class, 'store'])->name('cms.pages.store');
    Route::get('portal/cms/pages/{page}/edit', [CmsPageController::class, 'edit'])->name('cms.pages.edit');
    Route::get('portal/cms/pages/{page}/preview', [CmsPageController::class, 'preview'])->name('cms.pages.preview');
    Route::put('portal/cms/pages/{page}', [CmsPageController::class, 'update'])->name('cms.pages.update');
    Route::post('portal/cms/pages/{page}/publish', [CmsPageController::class, 'publish'])->name('cms.pages.publish');
    Route::post('portal/cms/pages/{page}/unpublish', [CmsPageController::class, 'unpublish'])->name('cms.pages.unpublish');
    Route::post('portal/cms/pages/{page}/revisions/{revision}/restore', [CmsPageController::class, 'restoreRevision'])->name('cms.pages.revisions.restore');

    Route::get('portal/cms/posts', [CmsPostController::class, 'index'])->name('cms.posts.index');
    Route::get('portal/cms/posts/create', [CmsPostController::class, 'create'])->name('cms.posts.create');
    Route::post('portal/cms/posts', [CmsPostController::class, 'store'])->name('cms.posts.store');
    Route::get('portal/cms/posts/{post}/edit', [CmsPostController::class, 'edit'])->name('cms.posts.edit');
    Route::get('portal/cms/posts/{post}/preview', [CmsPostController::class, 'preview'])->name('cms.posts.preview');
    Route::put('portal/cms/posts/{post}', [CmsPostController::class, 'update'])->name('cms.posts.update');
    Route::post('portal/cms/posts/{post}/publish', [CmsPostController::class, 'publish'])->name('cms.posts.publish');
    Route::post('portal/cms/posts/{post}/unpublish', [CmsPostController::class, 'unpublish'])->name('cms.posts.unpublish');
    Route::post('portal/cms/posts/{post}/revisions/{revision}/restore', [CmsPostController::class, 'restoreRevision'])->name('cms.posts.revisions.restore');

    Route::get('portal/cms/media', [CmsMediaController::class, 'index'])->name('cms.media.index');
    Route::post('portal/cms/media', [CmsMediaController::class, 'store'])->name('cms.media.store');
    // --- end CMS ---
});

require __DIR__.'/settings.php';

// Catch-all CMS page route — must stay last so it never shadows a more
// specific route above (sitemap.xml, news, library, dashboard, etc.).
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('page.show');
