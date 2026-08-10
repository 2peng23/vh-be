<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Notifications\VehicleReminder;
use Illuminate\Database\Eloquent\Model;

class ReminderService
{
    public function run(): int
    {
        $count = 0;
        Business::query()->each(function ($b) use (&$count) {
            $users = User::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('role', ['owner', 'staff'])->get();
            VehicleDocument::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('expiration_date')->whereDate('expiration_date', '<=', now()->addDays(30))->each(function ($d) use ($users, &$count, $b) {
                $days = (int) now()->startOfDay()->diffInDays($d->expiration_date, false);
                $threshold = $days < 0 ? 'overdue' : collect([1, 7, 14, 30])->first(fn ($x) => $days <= $x);
                if ($threshold !== null) {
                    $count += $this->sendOnce($b->id, $d, 'document', (string) $threshold, $users, ['title' => 'Vehicle document reminder', 'message' => "{$d->document_type} is ".($days < 0 ? 'overdue' : "due in {$days} days").'.', 'vehicle_id' => $d->vehicle_id]);
                }
            });
        });

        return $count;
    }

    private function sendOnce(int $businessId, Model $m, string $kind, string $threshold, $users, array $payload): int
    {
        $exists = ReminderDelivery::withoutGlobalScopes()->where(['business_id' => $businessId, 'remindable_type' => $m::class, 'remindable_id' => $m->id, 'kind' => $kind, 'threshold' => $threshold])->exists();
        if ($exists) {
            return 0;
        }foreach ($users as $u) {
            $u->notify(new VehicleReminder($payload));
        }ReminderDelivery::withoutGlobalScopes()->create(['business_id' => $businessId, 'remindable_type' => $m::class, 'remindable_id' => $m->id, 'kind' => $kind, 'threshold' => $threshold, 'sent_at' => now()]);

        return 1;
    }
}
