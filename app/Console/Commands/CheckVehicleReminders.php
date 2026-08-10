<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

class CheckVehicleReminders extends Command
{
    protected $signature = 'vehicle:check-reminders';

    protected $description = 'Generate deduplicated vehicle reminders';

    public function handle(ReminderService $s): int
    {
        $this->info($s->run().' reminders generated.');

        return self::SUCCESS;
    }
}
