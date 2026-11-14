<?php
// LOCATION: app/Console/Commands/ManagementDefaultsReport.php
//
// Read-only. Prints the EFFECTIVE value of every Management-decision default from the live configuration, its classification, and the fact that it
// is NOT approved by Management. `--check` exits 1 if any value differs from the documented safe default (an override requires a recorded approval).
// `--json` prints machine-readable output (for the staging gate / audit trail). Changes nothing.
namespace App\Console\Commands;

use App\Support\ManagementDefaults;
use Illuminate\Console\Command;

class ManagementDefaultsReport extends Command
{
    protected $signature = 'ssi:defaults-report {--json : Machine-readable output} {--check : Exit 1 if any value differs from the documented safe default}';
    protected $description = 'Read-only: show the effective Management-decision defaults and whether any differs from the documented safe default.';

    public function handle(): int
    {
        $rows = ManagementDefaults::register();
        $deviating = array_values(array_filter($rows, fn ($r) => !$r['ok']));
        if ($this->option('json')) {
            $this->line(json_encode(['approval_status' => ManagementDefaults::APPROVAL, 'deviations' => array_column($deviating, 'id'), 'items' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Defaults in force. Using a default is NOT an approval: every item below is ' . ManagementDefaults::APPROVAL . '.');
            foreach ($rows as $r) {
                $this->line(sprintf('#%-2d %-62s [%s | confirmation %s]%s', $r['id'], $r['topic'], $r['classification'], $r['confirmation'], $r['ok'] ? '' : '  *** DIFFERS FROM SAFE DEFAULT ***'));
                $this->line('     effective: ' . $r['effective']);
            }
            if ($deviating) $this->warn('Deviations from the documented safe default: #' . implode(', #', array_column($deviating, 'id')) . ' — each needs a recorded approval.');
        }
        return ($this->option('check') && $deviating) ? self::FAILURE : self::SUCCESS;
    }
}
