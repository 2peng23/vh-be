<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestSupportConversation extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token_hash'];

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }
}
