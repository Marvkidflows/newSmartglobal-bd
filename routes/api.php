<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\TelegramWebhookController;
// Auth
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;

// Shared
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;

// Admin
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminInvestmentPlanController;
use App\Http\Controllers\Admin\AdminDepositController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Admin\AdminAnnouncementController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminInvestmentController;
use App\Http\Controllers\Admin\AdminEmailVerificationController;
use App\Http\Controllers\Admin\AdminSectorController;
use App\Http\Controllers\Admin\AdminGlobalManagementController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\AdminEmailController;
use App\Http\Controllers\Admin\AdminTaskController;
use App\Http\Controllers\Admin\AdminTaskTypeController;
use App\Http\Controllers\Investor\InvestorEmailController;
use App\Http\Controllers\Investor\InvestorTaskController;
 
// Investor
use App\Http\Controllers\Investor\InvestorDashboardController;
use App\Http\Controllers\Investor\InvestorInvestmentController;
use App\Http\Controllers\Investor\InvestorDepositController;
use App\Http\Controllers\Investor\InvestorWithdrawalController;
use App\Http\Controllers\Investor\InvestorReferralController;
use App\Http\Controllers\Investor\InvestorProfileController;
use App\Http\Controllers\Investor\InvestorAnnouncementController;
use App\Http\Controllers\Investor\WithdrawalPinController;

/*
|--------------------------------------------------------------------------
| PUBLIC AUTH
|--------------------------------------------------------------------------
| Phase 13 security fix: none of these had any rate limiting before —
| login had no lockout at all (unlike OTP verification, which already
| had its own attempt cap), and forgot-password could be used to spam a
| victim's inbox with reset emails at unlimited rate. Throttle keys
| default to requester IP; limits are intentionally generous enough not
| to lock out someone who mistypes their password a couple of times.
|--------------------------------------------------------------------------
*/
Route::post('/register/stage1', [RegisterController::class, 'submitStage1'])->middleware('throttle:10,1');
Route::post('/login',           [LoginController::class, 'login'])->middleware('throttle:8,1');
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1');
 Route::post('/public/contact-support', [MessageController::class, 'publicContactSupport'])->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| MARKET INFORMATION (Phase 3) — public, read-only. No trade execution
| lives behind these routes; see MarketController's header comment.
| Throttled separately from the general API since it fans out to a
| third-party provider on a cache miss.
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:60,1')->prefix('market')->name('market.')->group(function () {
    Route::get('/overview',      [\App\Http\Controllers\MarketController::class, 'overview'])->name('overview');
    Route::get('/assets',        [\App\Http\Controllers\MarketController::class, 'index'])->name('assets.index');
    Route::get('/assets/{asset}',[\App\Http\Controllers\MarketController::class, 'show'])->name('assets.show');
});

/*
|--------------------------------------------------------------------------
| PUBLIC INVESTMENT PLANS
|--------------------------------------------------------------------------
| Phase 5/8 fix — public marketing pages previously hardcoded fictional
| plan data that didn't match what admins actually configure. This
| exposes the real, live InvestmentPlan records (public-safe subset) so
| /plans and the homepage can never show numbers that contradict what an
| investor actually gets after registering. See PublicPlanController.
|--------------------------------------------------------------------------
*/
Route::get('/plans', [\App\Http\Controllers\PublicPlanController::class, 'index'])->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| AUTHENTICATED (Bearer token required)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        $user = $request->user();
        return response()->json([
            'id'                 => $user->id,
            'name'               => $user->name ?? $user->full_name,
            'email'              => $user->email,
            'role'               => $user->role,
            'balance'            => (float) ($user->balance ?? 0),
            'referral_code'      => $user->referral_code ?? null,
            'status'             => $user->status ?? 'active',
            // Gaming & Prediction / dashboard routing depends on this:
            // 1 = account created, 2 = KYC done, 3 = investor profile done,
            // 4 = fully registered. Admins are always effectively "done"
            // since they never go through this flow.
            'registration_stage' => $user->role === 'admin' ? 4 : ($user->registration_stage ?? 1),
            // registration_stage alone can't tell "just created, OTP not
            // sent yet" apart from "OTP verified, stuck before Stage 2" —
            // both sit at stage 1. RegisterPage uses this to resume at
            // the OTP screen vs the Stage 2 form correctly.
            'email_verified' => (bool) $user->email_verified_at,
        ]);
    });

    Route::post('/logout', [LoginController::class, 'logout']);

    // Registration continuation
    // Email OTP verification (between stage 1 and stage 2)
    // OtpService already enforces its own attempt cap + expiry — this
    // route throttle is defense-in-depth on top of that, and specifically
    // covers resend (which OtpService doesn't rate-limit beyond a 9-min cooldown).
    Route::post('/register/verify-otp', [EmailVerificationController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/register/resend-otp', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1');
    Route::post('/register/stage2', [RegisterController::class, 'submitStage2']);
    Route::post('/register/stage3', [RegisterController::class, 'submitStage3']);
    Route::post('/register/stage4', [RegisterController::class, 'submitStage4']);

    // Sectors — readable by any authenticated user (investor or admin)
    Route::get('/sectors/active', function () {
        return response()->json([
            'sectors' => \App\Models\Sector::with('activeCategories')->active()->ordered()->get(),
        ]);
    });

    /*
    |----------------------------------------------------------------------
    | INVESTOR ROUTES
    |----------------------------------------------------------------------
    */
    Route::middleware(['investor', 'check.account'])
        ->prefix('investor-investment')
        ->name('investor-investment.')
        ->group(function () {

        Route::get('/dashboard', [InvestorDashboardController::class, 'dashboard'])
            ->name('dashboard');
        
        Route::get('/notifications',                      [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.read');
        Route::delete('/notifications/{notification}',    [NotificationController::class, 'destroy'])
            ->name('notifications.destroy');

        Route::get('/announcements', [InvestorAnnouncementController::class, 'investorIndex'])
            ->name('announcements.index');
        Route::get('/announcements/{slug}', [InvestorAnnouncementController::class, 'show'])
            ->name('announcements.show');

        Route::get('/messages',           [MessageController::class, 'investorIndex'])->name('messages.index');
        Route::get('/messages/create',    [MessageController::class, 'investorCreate'])->name('messages.create');
        Route::post('/messages',          [MessageController::class, 'investorStore'])->name('messages.store');
        Route::get('/messages/{message}', [MessageController::class, 'investorShow'])->name('messages.show');
        
        
Route::get('/emails',               [InvestorEmailController::class, 'index'])->name('emails.index');
Route::get('/emails/{sentEmail}',   [InvestorEmailController::class, 'show'])->name('emails.show');

        Route::get('/investor/profile',      [InvestorProfileController::class, 'show'])->name('profile.show');
        Route::get('/investor/profile/edit', [InvestorProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/investor/profile',      [InvestorProfileController::class, 'update'])->name('profile.update');
        
Route::post('/investor/profile/kyc', [InvestorProfileController::class, 'submitKyc'])->name('profile.kyc');
 
      // ── Investments ──
Route::get('/investor/investments',                     [InvestorInvestmentController::class, 'index'])->name('investments.index');
Route::get('/investor/investments/plans',               [InvestorInvestmentController::class, 'plans'])->name('investments.plans');
Route::get('/investor/investments/create/{plan}',       [InvestorInvestmentController::class, 'create'])->name('investments.create');
Route::post('/investor/investments',                    [InvestorInvestmentController::class, 'store'])->name('investments.store');
Route::get('/investor/investments/{investmentAccount}', [InvestorInvestmentController::class, 'show'])->name('investments.show');

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
// ── Deposits ──
Route::get('/investor/deposits',                    [InvestorDepositController::class, 'index'])->name('deposits.index');
Route::get('/investor/deposits/create',             [InvestorDepositController::class, 'create'])->name('deposits.create');
Route::post('/investor/deposits/initiate',          [InvestorDepositController::class, 'initiate'])->name('deposits.initiate');
Route::put('/investor/deposits/{deposit}/confirm',  [InvestorDepositController::class, 'confirm'])->name('deposits.confirm');
Route::post('/investor/deposits',                   [InvestorDepositController::class, 'store'])->name('deposits.store'); // backward-compat alias → initiate
Route::get('/investor/deposits/{deposit}',          [InvestorDepositController::class, 'show'])->name('deposits.show');

        Route::get('/investor/withdrawals',        [InvestorWithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/investor/withdrawals/create', [InvestorWithdrawalController::class, 'create'])->name('withdrawals.create');
        Route::post('/investor/withdrawals',       [InvestorWithdrawalController::class, 'store'])->name('withdrawals.store');


        Route::get('/investor/withdrawal-pin/status', [WithdrawalPinController::class, 'status'])->name('withdrawal-pin.status');
        Route::post('/investor/withdrawal-pin',        [WithdrawalPinController::class, 'store'])->name('withdrawal-pin.store');
        Route::get('/investor/referrals', [InvestorReferralController::class, 'index'])->name('referrals.index');

        /*
        |----------------------------------------------------------------------
        | INVESTOR TASK MANAGEMENT (investor side)
        |----------------------------------------------------------------------
        */
        Route::get('/tasks',                          [InvestorTaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks/submit-code',              [InvestorTaskController::class, 'submitCode'])->name('tasks.submit-code');
        Route::get('/tasks/{taskAssignment}',            [InvestorTaskController::class, 'show'])->name('tasks.show');
        Route::post('/tasks/{taskAssignment}/submit',    [InvestorTaskController::class, 'submitActivity'])->name('tasks.submit');
        Route::get('/tasks/{taskAssignment}/logs',       [InvestorTaskController::class, 'logs'])->name('tasks.logs');

        /*
        |----------------------------------------------------------------------
        | PREDICTION / ACTIVITY MODULE (Phase 4, investor side)
        | Points-based, non-wagering — see InvestorPredictionController.
        |----------------------------------------------------------------------
        */
        Route::get('/predictions',                    [\App\Http\Controllers\Investor\InvestorPredictionController::class, 'index'])->name('predictions.index');
        Route::post('/predictions/{round}/enter',     [\App\Http\Controllers\Investor\InvestorPredictionController::class, 'enter'])->name('predictions.enter');

        /*
        |----------------------------------------------------------------------
        | GAMING & PREDICTION — FINAL SPEC (sports fixtures)
        | Replaces the crypto up/down module above as the platform's one
        | Gaming & Prediction section (that module's routes are left in
        | place, non-destructive, but unlinked from navigation).
        |----------------------------------------------------------------------
        */
        Route::get('/fixtures',  [\App\Http\Controllers\Investor\InvestorFixtureController::class, 'index'])->name('fixtures.index');
        Route::post('/fixtures/markets/{market}/predict', [\App\Http\Controllers\Investor\InvestorFixtureController::class, 'predict'])->name('fixtures.predict');

    });

    /*

    |----------------------------------------------------------------------
    | ADMIN ROUTES
    |----------------------------------------------------------------------
    */
    Route::middleware('admin')
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {

        Route::get('/dashboard', [AdminDashboardController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/analytics', [AdminAnalyticsController::class, 'index'])
            ->name('analytics');

        Route::get('/users',                  [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}',           [AdminUserController::class, 'show'])->name('users.show');
        Route::put('/users/{user}',           [AdminUserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/suspend',  [AdminUserController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
        Route::post('/users/{user}/balance',  [AdminUserController::class, 'adjustBalance'])->name('users.balance');     
Route::post('/users/{user}/freeze',     [AdminUserController::class, 'freeze'])->name('users.freeze');
Route::post('/users/{user}/unfreeze',   [AdminUserController::class, 'unfreeze'])->name('users.unfreeze');
Route::post('/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');
 

        // Email OTP verification — admin controls
        Route::get('/users/{user}/verification-status', [AdminEmailVerificationController::class, 'status'])->name('users.verification-status');
        Route::post('/users/{user}/resend-otp',          [AdminEmailVerificationController::class, 'resend'])->name('users.resend-otp');
        Route::post('/users/{user}/manual-verify',       [AdminEmailVerificationController::class, 'manualVerify'])->name('users.manual-verify');

     Route::post('/global/profit-adjustments', [AdminGlobalManagementController::class, 'adjustProfit'])->name('global.profit-adjust');
Route::get('/global/profit-adjustments',  [AdminGlobalManagementController::class, 'profitAdjustmentHistory'])->name('global.profit-history');
Route::post('/global/balance-bulk',       [AdminGlobalManagementController::class, 'bulkBalance'])->name('global.balance-bulk');
 

        Route::resource('investment-plans', AdminInvestmentPlanController::class);

        // Sectors & categories
        Route::get('/sectors',                      [AdminSectorController::class, 'index'])->name('sectors.index');
        Route::post('/sectors',                      [AdminSectorController::class, 'store'])->name('sectors.store');
        Route::put('/sectors/{sector}',               [AdminSectorController::class, 'update'])->name('sectors.update');
        Route::delete('/sectors/{sector}',            [AdminSectorController::class, 'destroy'])->name('sectors.destroy');
        Route::post('/sectors/{sector}/activate',     [AdminSectorController::class, 'activate'])->name('sectors.activate');
        Route::post('/sectors/{sector}/deactivate',   [AdminSectorController::class, 'deactivate'])->name('sectors.deactivate');

        Route::post('/sectors/{sector}/categories',              [AdminSectorController::class, 'storeCategory'])->name('sectors.categories.store');
        Route::put('/sector-categories/{category}',               [AdminSectorController::class, 'updateCategory'])->name('sector-categories.update');
        Route::delete('/sector-categories/{category}',            [AdminSectorController::class, 'destroyCategory'])->name('sector-categories.destroy');
        Route::post('/sector-categories/{category}/activate',     [AdminSectorController::class, 'activateCategory'])->name('sector-categories.activate');
        Route::post('/sector-categories/{category}/deactivate',   [AdminSectorController::class, 'deactivateCategory'])->name('sector-categories.deactivate');

        Route::get('/investments',                        [AdminInvestmentController::class, 'index'])->name('investments.index');
        Route::get('/investments/{investment}',           [AdminInvestmentController::class, 'show'])->name('investments.show');
        Route::post('/investments/{investment}/complete', [AdminInvestmentController::class, 'complete'])->name('investments.complete');

        Route::post('/investments/{investment}/countdown/extend',  [AdminInvestmentController::class, 'extendCountdown'])->name('investments.countdown.extend');
Route::post('/investments/{investment}/countdown/reduce',  [AdminInvestmentController::class, 'reduceCountdown'])->name('investments.countdown.reduce');
Route::post('/investments/{investment}/countdown/set-date',[AdminInvestmentController::class, 'setCountdownDate'])->name('investments.countdown.set-date');
Route::post('/investments/{investment}/countdown/override',[AdminInvestmentController::class, 'overrideCountdown'])->name('investments.countdown.override');
Route::get('/investments/{investment}/countdown/logs',     [AdminInvestmentController::class, 'countdownLogs'])->name('investments.countdown.logs');

        Route::get('/deposits',                    [AdminDepositController::class, 'index'])->name('deposits.index');
        Route::get('/deposits/{deposit}',          [AdminDepositController::class, 'show'])->name('deposits.show');
        Route::post('/deposits/{deposit}/approve', [AdminDepositController::class, 'approve'])->name('deposits.approve');
        Route::post('/deposits/{deposit}/reject',  [AdminDepositController::class, 'reject'])->name('deposits.reject');
        Route::post('/deposits/{deposit}/hold',  [AdminDepositController::class, 'hold'])->name('deposits.hold');
        Route::post('/deposits/{deposit}/notes', [AdminDepositController::class, 'addNote'])->name('deposits.notes');

        
Route::get('/kyc',                    [AdminKycController::class, 'index'])->name('kyc.index');
Route::get('/kyc/{user}',             [AdminKycController::class, 'show'])->name('kyc.show');
Route::post('/kyc/{user}/approve',    [AdminKycController::class, 'approve'])->name('kyc.approve');
Route::post('/kyc/{user}/reject',     [AdminKycController::class, 'reject'])->name('kyc.reject');
 

        Route::get('/withdrawals',                       [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/withdrawals/{withdrawal}',          [AdminWithdrawalController::class, 'show'])->name('withdrawals.show');
        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])->name('withdrawals.approve');
        Route::post('/withdrawals/{withdrawal}/reject',  [AdminWithdrawalController::class, 'reject'])->name('withdrawals.reject');
        Route::post('/withdrawals/{withdrawal}/hold',  [AdminWithdrawalController::class, 'hold'])->name('withdrawals.hold');
        Route::post('/withdrawals/{withdrawal}/notes', [AdminWithdrawalController::class, 'addNote'])->name('withdrawals.notes');


        Route::get('/messages',                  [MessageController::class, 'adminIndex'])->name('messages.index');
        Route::post('/messages/{investor}/send', [MessageController::class, 'adminSend'])->name('messages.send');
        Route::delete('/messages/{message}',     [MessageController::class, 'adminDelete'])->name('messages.destroy');
        Route::get('/messages/{investor}',       [MessageController::class, 'adminShow'])->name('messages.show');

    /*
|--------------------------------------------------------------------------
| EMAIL CENTER
|--------------------------------------------------------------------------
*/

Route::prefix('email-center')->name('email-center.')->group(function () {

    // Dashboard
    Route::get('/dashboard', [AdminEmailController::class, 'dashboard'])
        ->name('dashboard');
    Route::get('/countries', [AdminEmailController::class, 'countries'])->name('countries');
    // Search Investors
    Route::get('/investors/search', [AdminEmailController::class, 'searchInvestors'])
        ->name('investors.search');

    // Send Email
    Route::post('/send', [AdminEmailController::class, 'send'])
        ->name('send');

    Route::post('/send-test', [AdminEmailController::class, 'sendTest'])
        ->name('send-test');

    // Bulk Email
    Route::get('/bulk/count', [AdminEmailController::class, 'bulkCount'])
        ->name('bulk.count');

    Route::post('/bulk/send', [AdminEmailController::class, 'bulkSend'])
        ->name('bulk.send');

    // Templates
    Route::get('/templates', [AdminEmailController::class, 'templatesIndex'])
        ->name('templates.index');

    Route::post('/templates', [AdminEmailController::class, 'templatesStore'])
        ->name('templates.store');

    Route::put('/templates/{template}', [AdminEmailController::class, 'templatesUpdate'])
        ->name('templates.update');

    Route::delete('/templates/{template}', [AdminEmailController::class, 'templatesDestroy'])
        ->name('templates.destroy');

    // Email Logs
    Route::get('/logs', [AdminEmailController::class, 'logs'])
        ->name('logs');

    Route::get('/logs/{sentEmail}', [AdminEmailController::class, 'logsShow'])
        ->name('logs.show');

});
        
        Route::resource('announcements', AdminAnnouncementController::class);
        Route::post('/announcements/{announcement}/publish',   [AdminAnnouncementController::class, 'publish'])->name('announcements.publish');
        Route::post('/announcements/{announcement}/unpublish', [AdminAnnouncementController::class, 'unpublish'])->name('announcements.unpublish');

        /*
        |----------------------------------------------------------------------
        | INVESTOR TASK MANAGEMENT (admin side)
        |----------------------------------------------------------------------
        */
        Route::get('/tasks',                          [AdminTaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks',                          [AdminTaskController::class, 'store'])->name('tasks.store');
        Route::get('/tasks/{taskAssignment}',            [AdminTaskController::class, 'show'])->name('tasks.show');
        Route::put('/tasks/{taskAssignment}',             [AdminTaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{taskAssignment}/amount',     [AdminTaskController::class, 'setAmount'])->name('tasks.amount');
        Route::post('/tasks/{taskAssignment}/activate',   [AdminTaskController::class, 'activate'])->name('tasks.activate');
        Route::post('/tasks/{taskAssignment}/deactivate', [AdminTaskController::class, 'deactivate'])->name('tasks.deactivate');
        Route::post('/tasks/{taskAssignment}/cancel',     [AdminTaskController::class, 'cancel'])->name('tasks.cancel');
        Route::post('/tasks/{taskAssignment}/mark-failed',[AdminTaskController::class, 'markFailed'])->name('tasks.mark-failed');

        Route::post('/tasks/{taskAssignment}/window/extend', [AdminTaskController::class, 'extendWindow'])->name('tasks.window.extend');
        Route::post('/tasks/{taskAssignment}/window/reduce', [AdminTaskController::class, 'reduceWindow'])->name('tasks.window.reduce');
        Route::post('/tasks/{taskAssignment}/window/set',    [AdminTaskController::class, 'setWindow'])->name('tasks.window.set');
        Route::get('/tasks/{taskAssignment}/logs',            [AdminTaskController::class, 'logs'])->name('tasks.logs');

        Route::post('/tasks/{taskAssignment}/review',   [AdminTaskController::class, 'review'])->name('tasks.review');
        Route::post('/tasks/{taskAssignment}/verify',   [AdminTaskController::class, 'verify'])->name('tasks.verify');
        Route::post('/tasks/{taskAssignment}/complete', [AdminTaskController::class, 'complete'])->name('tasks.complete');
        Route::post('/tasks/{taskAssignment}/close',    [AdminTaskController::class, 'close'])->name('tasks.close');
        Route::post('/tasks/{taskAssignment}/close-task', [AdminTaskController::class, 'closeTask'])->name('tasks.close-task');
        Route::post('/tasks/{taskAssignment}/deactivate-task', [AdminTaskController::class, 'deactivateTask'])->name('tasks.deactivate-task');
        Route::post('/tasks/{taskAssignment}/resume-task',     [AdminTaskController::class, 'resumeTask'])->name('tasks.resume-task');

        Route::get('/task-types',                    [AdminTaskTypeController::class, 'index'])->name('task-types.index');
        Route::post('/task-types',                    [AdminTaskTypeController::class, 'store'])->name('task-types.store');
        Route::put('/task-types/{taskType}',            [AdminTaskTypeController::class, 'update'])->name('task-types.update');
        Route::delete('/task-types/{taskType}',         [AdminTaskTypeController::class, 'destroy'])->name('task-types.destroy');

        /*
        |----------------------------------------------------------------------
        | PREDICTION / ACTIVITY MODULE (Phase 4, admin side)
        |----------------------------------------------------------------------
        */
        Route::get('/predictions',                    [\App\Http\Controllers\Admin\AdminPredictionController::class, 'index'])->name('predictions.index');
        Route::post('/predictions',                   [\App\Http\Controllers\Admin\AdminPredictionController::class, 'store'])->name('predictions.store');
        Route::get('/predictions/{round}',             [\App\Http\Controllers\Admin\AdminPredictionController::class, 'show'])->name('predictions.show');
        Route::patch('/predictions/{round}/close',      [\App\Http\Controllers\Admin\AdminPredictionController::class, 'close'])->name('predictions.close');
        Route::patch('/predictions/{round}/cancel',     [\App\Http\Controllers\Admin\AdminPredictionController::class, 'cancel'])->name('predictions.cancel');
        Route::patch('/predictions/{round}/resolve',    [\App\Http\Controllers\Admin\AdminPredictionController::class, 'resolve'])->name('predictions.resolve');

        /*
        |----------------------------------------------------------------------
        | GAMING & PREDICTION — FINAL SPEC (sports fixtures), admin side
        |----------------------------------------------------------------------
        */
        Route::get('/fixtures/overview',                 [\App\Http\Controllers\Admin\AdminFixtureController::class, 'overview'])->name('fixtures.overview');
        Route::get('/fixtures/sync-status',               [\App\Http\Controllers\Admin\AdminFixtureController::class, 'syncStatus'])->name('fixtures.sync-status');
        Route::post('/fixtures/bulk-publish',             [\App\Http\Controllers\Admin\AdminFixtureController::class, 'bulkPublish'])->name('fixtures.bulk-publish');
        Route::get('/fixtures',                          [\App\Http\Controllers\Admin\AdminFixtureController::class, 'index'])->name('fixtures.index');
        Route::post('/fixtures',                          [\App\Http\Controllers\Admin\AdminFixtureController::class, 'store'])->name('fixtures.store');
        Route::post('/fixtures/fetch-api',                [\App\Http\Controllers\Admin\AdminFixtureController::class, 'fetchFromApi'])->name('fixtures.fetch-api');
        Route::patch('/fixtures/{fixture}',               [\App\Http\Controllers\Admin\AdminFixtureController::class, 'update'])->name('fixtures.update');
        Route::get('/fixtures/{fixture}/predictions',     [\App\Http\Controllers\Admin\AdminFixtureController::class, 'predictions'])->name('fixtures.predictions');
        Route::patch('/fixtures/{fixture}/publish',       [\App\Http\Controllers\Admin\AdminFixtureController::class, 'publish'])->name('fixtures.publish');
        Route::patch('/fixtures/{fixture}/unpublish',     [\App\Http\Controllers\Admin\AdminFixtureController::class, 'unpublish'])->name('fixtures.unpublish');
        Route::delete('/fixtures/{fixture}',              [\App\Http\Controllers\Admin\AdminFixtureController::class, 'destroy'])->name('fixtures.destroy');
        Route::post('/fixtures/{fixture}/markets',        [\App\Http\Controllers\Admin\AdminFixtureController::class, 'addMarket'])->name('fixtures.markets.store');
        Route::delete('/fixtures/{fixture}/markets/{market}', [\App\Http\Controllers\Admin\AdminFixtureController::class, 'removeMarket'])->name('fixtures.markets.destroy');
        Route::patch('/fixtures/{fixture}/cancel',        [\App\Http\Controllers\Admin\AdminFixtureController::class, 'cancel'])->name('fixtures.cancel');
        Route::patch('/fixtures/{fixture}/postpone',      [\App\Http\Controllers\Admin\AdminFixtureController::class, 'postpone'])->name('fixtures.postpone');
        Route::patch('/fixtures/{fixture}/reinstate',     [\App\Http\Controllers\Admin\AdminFixtureController::class, 'reinstate'])->name('fixtures.reinstate');
        Route::patch('/fixtures/{fixture}/resolve',       [\App\Http\Controllers\Admin\AdminFixtureController::class, 'resolve'])->name('fixtures.resolve');

        /*
        |----------------------------------------------------------------------
        | GAMING & PREDICTION — admin league selection
        | Which competitions FootballDataService pulls from. Independent
        | of manual fixture entry, which is never restricted by this.
        |----------------------------------------------------------------------
        */
        Route::get('/competitions',                      [\App\Http\Controllers\Admin\AdminCompetitionController::class, 'index'])->name('competitions.index');
        Route::patch('/competitions/{competition}/toggle',[\App\Http\Controllers\Admin\AdminCompetitionController::class, 'toggle'])->name('competitions.toggle');
        Route::post('/competitions/sync-provider',        [\App\Http\Controllers\Admin\AdminCompetitionController::class, 'syncFromProvider'])->name('competitions.sync-provider');
    });

    /*
    |----------------------------------------------------------------------
    | FINANCIAL TEAM ROUTES
    |----------------------------------------------------------------------
    | Deliberately reuses the same controllers as the admin group above
    | (AdminDepositController, AdminWithdrawalController, etc.) rather
    | than duplicating approval/rejection business logic — the financial
    | team performs the exact same deposit/withdrawal actions an admin
    | would, just reached through a narrower, financial-only route group.
    | FinancialMiddleware is what actually keeps this narrow: it accepts
    | role === 'financial' OR 'admin', and nothing in this group touches
    | admin account management, platform settings, or anything outside
    | deposits/withdrawals/investments/dashboard stats.
    */
    Route::middleware('financial')
        ->prefix('financial')
        ->name('financial.')
        ->group(function () {

        Route::get('/dashboard', [AdminDashboardController::class, 'dashboard'])
            ->name('dashboard');

        // Deposits — full review/approve/reject/hold workflow, identical
        // to the admin one since it's literally the same controller.
        Route::get('/deposits',                    [AdminDepositController::class, 'index'])->name('deposits.index');
        Route::get('/deposits/{deposit}',          [AdminDepositController::class, 'show'])->name('deposits.show');
        Route::post('/deposits/{deposit}/approve', [AdminDepositController::class, 'approve'])->name('deposits.approve');
        Route::post('/deposits/{deposit}/reject',  [AdminDepositController::class, 'reject'])->name('deposits.reject');
        Route::post('/deposits/{deposit}/hold',    [AdminDepositController::class, 'hold'])->name('deposits.hold');
        Route::post('/deposits/{deposit}/notes',   [AdminDepositController::class, 'addNote'])->name('deposits.notes');

        // Withdrawals — same reasoning as deposits above.
        Route::get('/withdrawals',                       [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/withdrawals/{withdrawal}',          [AdminWithdrawalController::class, 'show'])->name('withdrawals.show');
        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])->name('withdrawals.approve');
        Route::post('/withdrawals/{withdrawal}/reject',  [AdminWithdrawalController::class, 'reject'])->name('withdrawals.reject');
        Route::post('/withdrawals/{withdrawal}/hold',    [AdminWithdrawalController::class, 'hold'])->name('withdrawals.hold');
        Route::post('/withdrawals/{withdrawal}/notes',   [AdminWithdrawalController::class, 'addNote'])->name('withdrawals.notes');

        // Investment records — read-only. The countdown-manipulation
        // endpoints (extend/reduce/override/complete) stay admin-only;
        // reviewing investment-related financial records doesn't require
        // being able to alter an investor's investment terms.
        Route::get('/investments',              [AdminInvestmentController::class, 'index'])->name('investments.index');
        Route::get('/investments/{investment}', [AdminInvestmentController::class, 'show'])->name('investments.show');

        // Notifications — the exact same controller/table investors use
        // (Auth::user()->notifications()), just reached from here. New
        // deposit/withdrawal notifications are pushed to financial+admin
        // users the same way (see FinancialNotificationService).
        Route::get('/notifications',                      [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::delete('/notifications/{notification}',    [NotificationController::class, 'destroy'])->name('notifications.destroy');

        // Profile — own account only, no route parameter, so there's no
        // way to target anyone else's user record through this.
        Route::get('/profile',           [\App\Http\Controllers\Financial\FinancialProfileController::class, 'show'])->name('profile.show');
        Route::post('/profile/password', [\App\Http\Controllers\Financial\FinancialProfileController::class, 'changePassword'])->name('profile.password');
    });

    Route::get('/notifications/mark-all-read', function (Request $request) {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();
        return response()->json(['message' => 'All notifications marked as read.']);
    })->name('notifications.mark-all-read');
});