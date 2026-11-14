<?php
// TASK 4 — News & Media routes. NEW FILE, loaded by ONE line appended to routes/api.php:   require __DIR__ . '/news.php';
// Loaded from inside api.php, so every path is automatically prefixed with /api.

use App\Http\Controllers\News\ArticleAdminController;
use App\Http\Controllers\News\InvestorNewsController;
use App\Http\Controllers\News\MediaAdminController;
use App\Http\Controllers\News\NewsAccessController;
use App\Http\Controllers\News\NewsMediaController;
use App\Http\Controllers\News\PublicNewsController;
use App\Http\Middleware\RequireNewsPermission as Perm;
use Illuminate\Support\Facades\Route;

// ── PUBLIC (anonymous): public + live articles only ──────────────────────────────────────────────────────────────────────────
Route::prefix('public/news')->middleware('throttle:120,1')->group(function () {
    Route::get('/categories',     [PublicNewsController::class, 'categories']);
    Route::get('/media/{media}',  [NewsMediaController::class, 'publicMedia'])->whereNumber('media')->name('news.public.media');
    Route::get('/',               [PublicNewsController::class, 'index']);
    Route::get('/{slug}',         [PublicNewsController::class, 'show'])->where('slug', '[A-Za-z0-9\-]+');
});

// Short-lived signed media URLs (issued by the API only to viewers authorised for the article / staff previews).
Route::get('/news/media/{media}', [NewsMediaController::class, 'signedMedia'])->whereNumber('media')->middleware(['signed', 'throttle:240,1'])->name('news.media.signed');

// ── SIGNED-IN INVESTORS (visibility levels decided server-side) ──────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'staff.active', 'investor', 'check.account', 'throttle:120,1'])->prefix('investor/news')->group(function () {
    Route::get('/',        [InvestorNewsController::class, 'index']);
    Route::get('/{slug}',  [InvestorNewsController::class, 'show'])->where('slug', '[A-Za-z0-9\-]+');
});

// ── STAFF ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'staff.active'])->prefix('news-admin')->group(function () {
    $any     = Perm::class . ':any';
    $review  = Perm::class . ':news.review';
    $publish = Perm::class . ':news.publish';
    $manage  = Perm::class . ':news.manage';

    Route::get('/my/permissions', [NewsAccessController::class, 'myPermissions']);   // any staff role; returns only that user's own grants
    Route::get('/categories', [NewsAccessController::class, 'categories'])->middleware($any);
    Route::post('/categories',        [NewsAccessController::class, 'categoryStore'])->middleware($manage);
    Route::put('/categories/{id}',    [NewsAccessController::class, 'categoryUpdate'])->whereNumber('id')->middleware($manage);
    Route::get('/audit',              [NewsAccessController::class, 'audit'])->middleware($manage);

    Route::middleware('admin')->group(function () {          // granting news permissions is an ADMIN-only action
        Route::get('/permissions',         [NewsAccessController::class, 'permissions']);
        Route::put('/permissions/{user}',  [NewsAccessController::class, 'syncPermissions'])->whereNumber('user');
    });

    Route::get('/articles',                 [ArticleAdminController::class, 'index'])->middleware($any);
    Route::post('/articles',                [ArticleAdminController::class, 'store'])->middleware([$any, 'throttle:60,1']);
    Route::get('/articles/{id}',            [ArticleAdminController::class, 'show'])->whereNumber('id')->middleware($any);
    Route::put('/articles/{id}',            [ArticleAdminController::class, 'update'])->whereNumber('id')->middleware($any);
    Route::delete('/articles/{id}',         [ArticleAdminController::class, 'destroy'])->whereNumber('id')->middleware($any);
    Route::post('/articles/{id}/submit',    [ArticleAdminController::class, 'submit'])->whereNumber('id')->middleware($any);
    Route::post('/articles/{id}/approve',   [ArticleAdminController::class, 'approve'])->whereNumber('id')->middleware($review);
    Route::post('/articles/{id}/reject',    [ArticleAdminController::class, 'reject'])->whereNumber('id')->middleware($review);
    Route::post('/articles/{id}/publish',   [ArticleAdminController::class, 'publish'])->whereNumber('id')->middleware($publish);
    Route::post('/articles/{id}/unpublish', [ArticleAdminController::class, 'unpublish'])->whereNumber('id')->middleware($publish);

    Route::get('/media',                [MediaAdminController::class, 'index'])->middleware($any);
    Route::post('/media/image',         [MediaAdminController::class, 'image'])->middleware([$any, 'throttle:20,1']);
    Route::post('/media/video',         [MediaAdminController::class, 'video'])->middleware([$any, 'throttle:6,1']);
    Route::post('/media/embed',         [MediaAdminController::class, 'embed'])->middleware([$any, 'throttle:30,1']);
    Route::post('/media/{id}/approve',  [MediaAdminController::class, 'approve'])->whereNumber('id')->middleware($review);
    Route::post('/media/{id}/reject',   [MediaAdminController::class, 'reject'])->whereNumber('id')->middleware($review);
    Route::delete('/media/{id}',        [MediaAdminController::class, 'destroy'])->whereNumber('id')->middleware($any);
});
