<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(string $action, Model $m, array $old = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'entity_type' => $m::class, 'entity_id' => $m->getKey(), 'old_values' => $old ?: null, 'new_values' => $m->getChanges() ?: $m->toArray(), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'created_at' => now()]);
    }
}
