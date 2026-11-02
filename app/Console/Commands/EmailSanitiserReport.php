<?php
// LOCATION: app/Console/Commands/EmailSanitiserReport.php
//
// READ-ONLY compatibility report. Existing emails, templates AND stored messages (Admin/Investor/Financial, staff channel, MarvFlow) are now
// sanitised when they are DISPLAYED/SENT (the stored rows are never rewritten). Emails and templates use the `email` profile; every message table uses
// the `message` profile. This command shows, for the data in the database it runs against, which rows the sanitiser would change and WHAT it would remove
// (tags, attributes, style properties), so legitimate old content can be checked before go-live. It never writes to the database.
//
//   php artisan emails:sanitiser-report            # summary + the first 20 changed rows
//   php artisan emails:sanitiser-report --show=100 # list more rows
//
// Run it on a STAGING copy restored from a backup (see APPLY.md), not on production.

namespace App\Console\Commands;

use App\Services\RichTextSanitizer;
use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailSanitiserReport extends Command
{
    protected $signature   = 'emails:sanitiser-report {--show=20 : how many changed rows to list}';
    protected $description = 'READ-ONLY: report which stored emails, templates and messages the sanitiser would change, and what it removes.';

    public function handle(RichTextSanitizer $sanitizer): int
    {
        $show = max(0, (int) $this->option('show'));
        $this->info('READ-ONLY report — nothing is written to the database.');

        // table => sanitiser profile applied on read
        foreach (['sent_emails' => 'email', 'email_templates' => 'email', 'messages' => 'message', 'staff_messages' => 'message', 'marvflow_messages' => 'message'] as $label => $profile) {
            if (!Schema::hasTable($label) || !Schema::hasColumn($label, 'body_html')) { $this->line("{$label}: not present (or no body_html column) — skipped"); continue; }
            $total = 0; $changed = 0; $emptied = 0; $removed = []; $listed = [];

            DB::table($label)->orderBy('id')->chunkById(200, function ($rows) use ($sanitizer, $profile, &$total, &$changed, &$emptied, &$removed, &$listed, $show) {
                foreach ($rows as $row) {
                    $before = (string) $row->body_html;
                    if (trim($before) === '') continue;
                    $total++;
                    $after = $sanitizer->sanitize($before, $profile);
                    $gone  = $this->removed($before, $after);
                    if ($after === '' && trim(strip_tags($before)) !== '') $emptied++;
                    if (!$gone) continue;
                    $changed++;
                    foreach ($gone as $k => $n) $removed[$k] = ($removed[$k] ?? 0) + $n;
                    if (count($listed) < $show) $listed[] = ['id' => $row->id, 'what' => implode(', ', array_map(fn ($k, $n) => "{$k}×{$n}", array_keys($gone), $gone))];
                }
            });

            $this->line('');
            $this->info("{$label}: {$total} with HTML, {$changed} would be changed on display, {$emptied} would become empty.");
            if ($removed) {
                arsort($removed);
                $this->table(['removed construct', 'occurrences'], array_map(fn ($k, $n) => [$k, $n], array_keys($removed), $removed));
            }
            if ($listed) $this->table(['row id', 'removed'], $listed);
        }

        $this->line('');
        $this->comment('Review the "removed construct" tables: <script>, on…= handlers, javascript: links and unsupported style properties SHOULD appear; ordinary formatting should not.');
        return self::SUCCESS;
    }

    /** What the original contains that the sanitised version does not: tag / attribute / style-property occurrence deltas. */
    protected function removed(string $before, string $after): array
    {
        $a = $this->inventory($before);
        $b = $this->inventory($after);
        $gone = [];
        foreach ($a as $key => $count) {
            $delta = $count - ($b[$key] ?? 0);
            if ($delta > 0) $gone[$key] = $delta;
        }
        return $gone;
    }

    protected function inventory(string $html): array
    {
        $out = [];
        if (trim($html) === '') return $out;
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        foreach ($dom->getElementsByTagName('*') as $el) {
            /** @var DOMElement $el */
            $tag = strtolower($el->tagName);
            if (in_array($tag, ['html', 'body'], true)) continue;
            $out["<{$tag}>"] = ($out["<{$tag}>"] ?? 0) + 1;
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $out["{$name}="] = ($out["{$name}="] ?? 0) + 1;
                if ($name === 'style') {
                    foreach (explode(';', $attr->value) as $decl) {
                        if (!str_contains($decl, ':')) continue;
                        $prop = strtolower(trim(explode(':', $decl, 2)[0]));
                        if ($prop !== '') $out["style:{$prop}"] = ($out["style:{$prop}"] ?? 0) + 1;
                    }
                }
            }
        }
        return $out;
    }
}
