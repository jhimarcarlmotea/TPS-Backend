<?php

namespace App\Console\Commands;

use App\Services\AdminBackupService;
use Illuminate\Console\Command;

class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run-scheduled';

    protected $description = 'Create an automatic backup when the configured schedule is due';

    public function handle(AdminBackupService $backups): int
    {
        if ($backups->runScheduledBackup()) {
            $this->info('Scheduled backup created.');
        }

        return self::SUCCESS;
    }
}
