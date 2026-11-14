<?php
// TASK 3 — Mailing module routes. NEW FILE, loaded by ONE line appended to routes/api.php:   require __DIR__ . '/mailing.php';
// Included from inside api.php, so every path below is automatically prefixed with /api.
// Public / investor separation: every staff route is gated by an environment-specific permission ({env}.view|manage|send).

use App\Http\Controllers\Mailing\CampaignController;
use App\Http\Controllers\Mailing\CommunicationController;
use App\Http\Controllers\Mailing\DepartmentController;
use App\Http\Controllers\Mailing\InvestorMailingController;
use App\Http\Controllers\Mailing\PublicMailingController;
use App\Http\Controllers\Mailing\SubscriberController;
use App\Http\Controllers\Mailing\WebhookController;
use App\Http\Middleware\RequireMailingPermission as Perm;
use Illuminate\Support\Facades\Route;

// ── PUBLIC (no auth) ─────────────────────────────────────────────────────────────────────────────────────────────────────────
Route::prefix('public/mailing')->group(function () {
    // Rate limits: per minute AND per day, per client IP (distinct prefixes keep the two counters apart).
    Route::post('/contact',   [PublicMailingController::class, 'contact'])->middleware(['throttle:5,1,mailing_contact_min', 'throttle:20,1440,mailing_contact_day'])->name('mailing.public.contact');
    Route::post('/subscribe', [PublicMailingController::class, 'subscribe'])->middleware(['throttle:5,1,mailing_sub_min', 'throttle:20,1440,mailing_sub_day'])->name('mailing.public.subscribe');

    // Signed links. GET shows a button (no state change); POST performs the change (also the RFC 8058 one-click target).
    Route::get('/unsubscribe/{ref}/{purpose}',  [PublicMailingController::class, 'unsubscribePage'])->middleware(['signed', 'throttle:60,1'])->name('mailing.unsubscribe');
    Route::post('/unsubscribe/{ref}/{purpose}', [PublicMailingController::class, 'unsubscribe'])->middleware(['signed', 'throttle:60,1'])->name('mailing.unsubscribe.process');
    Route::get('/confirm/{ref}/{purpose}',      [PublicMailingController::class, 'confirmPage'])->middleware(['signed', 'throttle:60,1'])->name('mailing.confirm');
    Route::post('/confirm/{ref}/{purpose}',     [PublicMailingController::class, 'confirm'])->middleware(['signed', 'throttle:60,1'])->name('mailing.confirm.process');
});

// ── PROVIDER WEBHOOKS (shared-secret authenticated inside the controller) ────────────────────────────────────────────────────
Route::post('/mailing/webhooks/delivery', [WebhookController::class, 'delivery'])->middleware('throttle:300,1')->name('mailing.webhook.delivery');
Route::post('/mailing/webhooks/inbound',  [WebhookController::class, 'inbound'])->middleware('throttle:300,1')->name('mailing.webhook.inbound');

// ── VERIFIED INVESTOR (own history + optional-mail preferences) ──────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'staff.active', 'investor', 'check.account'])->prefix('investor/mailing')->group(function () {
    Route::get('/history',        [InvestorMailingController::class, 'history']);
    Route::get('/history/{id}',   [InvestorMailingController::class, 'show'])->whereNumber('id');
    Route::get('/preferences',    [InvestorMailingController::class, 'preferences']);
    Route::put('/preferences',    [InvestorMailingController::class, 'update'])->middleware('throttle:30,1');
});

// ── STAFF ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'staff.active'])->prefix('mailing')->group(function () {
    $staff = Perm::class . ':staff';

    // Environment-independent (registered BEFORE the {environment} routes).
    Route::get('/my/messages',                [CommunicationController::class, 'mine'])->middleware($staff);
    Route::get('/my/permissions',             [DepartmentController::class, 'myPermissions'])->middleware($staff);
    Route::get('/messages/{message}',         [CommunicationController::class, 'show'])->whereNumber('message')->middleware($staff);

    Route::get('/departments',                        [DepartmentController::class, 'index'])->middleware(Perm::class . ':departments.view,departments.manage');
    Route::post('/departments',                       [DepartmentController::class, 'store'])->middleware(Perm::class . ':departments.manage');
    Route::put('/departments/{department}',           [DepartmentController::class, 'update'])->whereNumber('department')->middleware(Perm::class . ':departments.manage');
    Route::put('/departments/{department}/members',   [DepartmentController::class, 'syncMembers'])->whereNumber('department')->middleware(Perm::class . ':departments.manage');
    Route::post('/departments/{department}/mailboxes',[DepartmentController::class, 'mailboxStore'])->whereNumber('department')->middleware(Perm::class . ':departments.manage');
    Route::put('/mailboxes/{mailbox}',                [DepartmentController::class, 'mailboxUpdate'])->whereNumber('mailbox')->middleware(Perm::class . ':departments.manage');
    Route::delete('/mailboxes/{mailbox}',             [DepartmentController::class, 'mailboxDestroy'])->whereNumber('mailbox')->middleware(Perm::class . ':departments.manage');
    Route::post('/mailboxes/{mailbox}/check',         [DepartmentController::class, 'mailboxCheck'])->whereNumber('mailbox')->middleware([Perm::class . ':departments.manage', 'throttle:20,1']);

    Route::get('/audit',                              [DepartmentController::class, 'audit'])->middleware(Perm::class . ':audit.view');

    // Granting mailing permissions is an ADMIN-only action (existing 'admin' middleware).
    Route::middleware('admin')->group(function () {
        Route::get('/permissions',          [DepartmentController::class, 'permissions']);
        Route::put('/permissions/{user}',   [DepartmentController::class, 'syncPermissions'])->whereNumber('user');
    });

    Route::post('/public/subscribers',            [SubscriberController::class, 'store'])->middleware([Perm::class . ':public.manage', 'throttle:30,1']);
    Route::post('/public/contacts/{contact}/status', [SubscriberController::class, 'contactStatus'])->whereNumber('contact')->middleware(Perm::class . ':public.manage');

    // Per-environment. {environment} is constrained to public|investor; each route needs that environment's own permission.
    Route::prefix('{environment}')->where(['environment' => 'public|investor'])->group(function () {
        $view = Perm::class . ':{env}.view';
        $manage = Perm::class . ':{env}.manage';
        $send = Perm::class . ':{env}.send';
        $manageOrSend = Perm::class . ':{env}.manage,{env}.send';

        Route::get('/dashboard',  [CommunicationController::class, 'dashboard'])->middleware($view);
        Route::get('/messages',   [CommunicationController::class, 'index'])->middleware($view);
        Route::get('/failures',   [CommunicationController::class, 'failures'])->middleware($view);
        Route::post('/messages',  [CommunicationController::class, 'compose'])->middleware([$send, 'throttle:60,1']);
        Route::post('/messages/{message}/retry',  [CommunicationController::class, 'retry'])->whereNumber('message')->middleware([$send, 'throttle:60,1']);
        Route::post('/messages/{message}/assign', [CommunicationController::class, 'assign'])->whereNumber('message')->middleware($manage);

        Route::get('/audiences',  [CampaignController::class, 'audiences'])->middleware($manageOrSend);
        Route::get('/campaigns',  [CampaignController::class, 'index'])->middleware($view);
        Route::post('/campaigns', [CampaignController::class, 'store'])->middleware([$manage, 'throttle:60,1']);
        Route::get('/campaigns/{campaign}',      [CampaignController::class, 'show'])->whereNumber('campaign')->middleware($view);
        Route::put('/campaigns/{campaign}',      [CampaignController::class, 'update'])->whereNumber('campaign')->middleware($manage);
        Route::delete('/campaigns/{campaign}',   [CampaignController::class, 'destroy'])->whereNumber('campaign')->middleware($manage);
        Route::get('/campaigns/{campaign}/audience-preview', [CampaignController::class, 'preview'])->whereNumber('campaign')->middleware([$manageOrSend, 'throttle:30,1']);
        Route::post('/campaigns/{campaign}/schedule', [CampaignController::class, 'schedule'])->whereNumber('campaign')->middleware($send);
        Route::post('/campaigns/{campaign}/send',     [CampaignController::class, 'send'])->whereNumber('campaign')->middleware([$send, 'throttle:10,1']);
        Route::post('/campaigns/{campaign}/cancel',   [CampaignController::class, 'cancel'])->whereNumber('campaign')->middleware($send);

        Route::get('/templates',                [CampaignController::class, 'templates'])->middleware($view);
        Route::post('/templates',               [CampaignController::class, 'templateStore'])->middleware($manage);
        Route::put('/templates/{template}',     [CampaignController::class, 'templateUpdate'])->whereNumber('template')->middleware($manage);
        Route::delete('/templates/{template}',  [CampaignController::class, 'templateDestroy'])->whereNumber('template')->middleware($manage);

        // Address lists: only staff holding {env}.manage (investor list protection).
        Route::get('/subscribers',   [SubscriberController::class, 'index'])->middleware($manage);
        Route::post('/subscribers/{subscription}/unsubscribe', [SubscriberController::class, 'unsubscribe'])->whereNumber('subscription')->middleware($manage);
        Route::get('/unsubscribes',  [SubscriberController::class, 'unsubscribes'])->middleware($manage);
        Route::get('/consents',      [SubscriberController::class, 'consents'])->middleware($manage);
    });
});
