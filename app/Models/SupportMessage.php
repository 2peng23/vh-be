<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $guarded = [];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function guestConversation(): BelongsTo
    {
        return $this->belongsTo(GuestSupportConversation::class, 'guest_support_conversation_id');
    }
}
