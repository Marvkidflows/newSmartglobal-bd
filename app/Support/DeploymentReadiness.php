<?php
// LOCATION: app/Support/DeploymentReadiness.php   (NEW FILE — D-19, D-20, D-21)
//
// Pure, read-only evaluation of settings that decide whether queued e-mail, rank alerts, scheduled jobs and e-mail delivery can work. It takes plain values
// (never reads secrets itself), returns findings, and never prints a secret: only variable NAMES and whether they are set.
// Used by `php artisan ssi:check-deployment`. It does not send mail, start a worker, run the scheduler or touch any data.

namespace App\Support;

class DeploymentReadiness
{
    public const ERROR = 'error';
    public const WARN  = 'warn';
    public const INFO  = 'info';

    /**
     * @param array{env?:string, debug?:bool, mailer?:string, mailing_mailer?:string, brevo_key_set?:bool, queue?:string, cache?:string,
     *              maturity_flag?:bool, maturity_ref_set?:bool, maturity_max_set?:bool, fixture_flag?:bool, fixture_key_set?:bool} $c
     * @return array<int,array{level:string,code:string,message:string}>
     */
    public static function evaluate(array $c): array
    {
        $f = [];
        $add = function (string $level, string $code, string $message) use (&$f) { $f[] = compact('level', 'code', 'message'); };
        $prod = ($c['env'] ?? 'production') === 'production';
        $mailer = strtolower((string) ($c['mailer'] ?? 'log'));
        $mailing = strtolower((string) ($c['mailing_mailer'] ?? ''));
        $queue = strtolower((string) ($c['queue'] ?? 'sync'));
        $cache = strtolower((string) ($c['cache'] ?? 'file'));

        // ── D-20: e-mail ─────────────────────────────────────────────────────────────────────────────────────────
        if (in_array($mailer, ['log', 'array'], true)) {
            $add($prod ? self::ERROR : self::INFO, 'mail.not_delivering', "MAIL_MAILER is \"{$mailer}\": e-mail is written to the log (or discarded) and NEVER delivered." . ($prod ? ' Set a real provider before launch.' : ' Fine for local development.'));
        }
        if (($mailer === 'brevo' || $mailing === 'brevo') && empty($c['brevo_key_set'])) {
            $add($prod ? self::ERROR : self::WARN, 'mail.brevo_key_missing', 'A Brevo mailer is selected but BREVO_API_KEY is not set: sending fails and mailbox checks report an error.');
        }
        $add(self::INFO, 'mail.delivery_unproven', 'This check cannot prove delivery. Domain authentication (SPF, DKIM, DMARC), verified senders and bounce handling must be confirmed with the provider and a real test message (decision D21).');

        // ── D-19: queue worker and scheduler ─────────────────────────────────────────────────────────────────────
        if ($queue === 'sync') {
            $add(self::INFO, 'queue.sync', 'QUEUE_CONNECTION=sync: jobs run inside the web request. No worker is needed, but a slow provider slows the request.');
        } elseif ($queue === 'null') {
            $add($prod ? self::ERROR : self::WARN, 'queue.null', 'QUEUE_CONNECTION=null: queued jobs (e-mail, rank alerts) are silently dropped.');
        } else {
            $add(self::WARN, 'queue.worker_required', "QUEUE_CONNECTION={$queue}: queued e-mail and rank alerts are sent ONLY while a worker process runs (php artisan queue:work). This repository cannot start one; configure it on the host (docs/DEPLOY_WORKER_AND_SCHEDULER.md).");
        }
        $add(self::WARN, 'scheduler.process_required', 'Scheduled work (rank re-evaluation, scheduled campaigns, news publishing, referral re-assessment) runs ONLY while `php artisan schedule:work` (or a per-minute `schedule:run`) is running on exactly one process. Confirm it on the host.');
        if (in_array($cache, ['array', 'file'], true) && $prod) {
            $add(self::WARN, 'cache.not_shared', "CACHE_STORE={$cache}: the scheduler's onOneServer()/withoutOverlapping() locks are not shared between instances. Use database or redis if more than one instance runs.");
        }

        // ── D-05 / D-21: scheduled jobs that are off by default ──────────────────────────────────────────────────
        if (!empty($c['maturity_flag'])) {
            if (empty($c['maturity_ref_set']) || empty($c['maturity_max_set'])) {
                $add(self::ERROR, 'maturity.no_approval', 'SCHEDULE_INVESTMENT_MATURITY is ON but no Finance approval is recorded (INVESTMENT_MATURITY_APPROVAL_REF and INVESTMENT_MATURITY_MAX_RUN_TOTAL). Scheduled runs will refuse to pay.');
            } else {
                $add(self::WARN, 'maturity.enabled', 'Profit payouts are scheduled and approved. Confirm the dry-run total and the named approver are current.');
            }
        } else {
            $add(self::INFO, 'maturity.off', 'Profit-payout job is OFF (safe default).');
        }
        if (!empty($c['fixture_flag'])) {
            $add(empty($c['fixture_key_set']) ? self::WARN : self::INFO, 'fixtures.on', 'Football fixture sync is ON' . (empty($c['fixture_key_set']) ? ' but its API key is not set, so every run logs a failure. Turn SCHEDULE_FIXTURE_SYNC off unless Management keeps this module (decision D5).' : ' (module kept by Management decision D5?). It never touches balances.'));
        } else {
            $add(self::INFO, 'fixtures.off', 'Football fixture sync is OFF.');
        }

        // ── general ──────────────────────────────────────────────────────────────────────────────────────────────
        if ($prod && !empty($c['debug'])) {
            $add(self::ERROR, 'app.debug_on', 'APP_DEBUG is true in production: error pages expose internals.');
        }
        return $f;
    }

    public static function hasErrors(array $findings): bool
    {
        foreach ($findings as $x) if ($x['level'] === self::ERROR) return true;
        return false;
    }
}
