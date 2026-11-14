<?php
// TASK 4 — `php artisan news:publish-scheduled` promotes scheduled articles whose time has come. (Reads also do this lazily.)
namespace App\Console\Commands;

use App\Services\News\NewsWorkflow;
use Illuminate\Console\Command;

class NewsPublishScheduled extends Command
{
    protected $signature = 'news:publish-scheduled';
    protected $description = 'Publish scheduled news articles that are due';

    public function handle(): int
    {
        $this->info('Published ' . NewsWorkflow::syncScheduled() . ' article(s).');
        return self::SUCCESS;
    }
}
