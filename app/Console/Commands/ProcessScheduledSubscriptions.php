<?php

namespace App\Console\Commands;

use App\Models\ScheduledSubscriptionChange;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;

class ProcessScheduledSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Apply scheduled subscription changes that are due.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $applied = 0;

        ScheduledSubscriptionChange::query()
            ->where('status', 'scheduled')
            ->whereDate('starts_at', '<=', now(config('app.timezone'))->toDateString())
            ->orderBy('starts_at')
            ->each(function (ScheduledSubscriptionChange $change) use ($subscriptions, &$applied) {
                if ($subscriptions->applyScheduledChange($change)) {
                    $applied++;
                }
            });

        $this->info("Applied {$applied} scheduled subscription change(s).");

        return self::SUCCESS;
    }
}
