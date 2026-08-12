<?php

namespace App\Services;

use App\Models\Business;
use App\Models\GuestSupportConversation;
use App\Models\SupportMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportConversationService
{
    /** Create a browser-bound guest conversation and return its plain-text access token once. */
    public function createGuest(array $attributes): array
    {
        $token = Str::random(64);
        $conversation = GuestSupportConversation::create([
            ...$attributes,
            'email' => Str::lower($attributes['email']),
            'access_token_hash' => hash('sha256', $token),
        ]);

        return compact('conversation', 'token');
    }

    /** Resolve a guest conversation without storing a recoverable access token. */
    public function resolveGuestToken(?string $token): GuestSupportConversation
    {
        abort_unless(is_string($token) && strlen($token) >= 40, 401, 'Support conversation token is missing.');

        return GuestSupportConversation::where('access_token_hash', hash('sha256', $token))->firstOrFail();
    }

    /** Return business and guest conversations in one latest-activity list for Super Admin. */
    public function adminConversations(): Collection
    {
        $businesses = Business::whereHas('supportMessages')
            ->withCount(['supportMessages as unread_support_count' => fn (Builder $query) => $query
                ->where('sender_type', 'tenant')->whereNull('read_at')])
            ->withMax('supportMessages', 'created_at')
            ->get()
            ->map(fn (Business $business) => [...$business->toArray(), 'conversation_type' => 'business']);

        $guests = GuestSupportConversation::whereHas('messages')
            ->withCount(['messages as unread_support_count' => fn (Builder $query) => $query
                ->where('sender_type', 'guest')->whereNull('read_at')])
            ->withMax('messages', 'created_at')
            ->get()
            ->map(fn (GuestSupportConversation $guest) => [
                ...$guest->toArray(),
                'conversation_type' => 'guest',
                'subscription_status' => null,
                'support_messages_max_created_at' => $guest->messages_max_created_at,
            ]);

        return $businesses->concat($guests)->sortByDesc('support_messages_max_created_at')->values();
    }

    /** Fetch the latest ten messages, or the ten messages before the supplied cursor. */
    public function page(?int $businessId, ?int $guestId, ?int $beforeId): array
    {
        $messages = SupportMessage::with('user:id,name')
            ->when($businessId, fn (Builder $query) => $query->where('business_id', $businessId))
            ->when($guestId, fn (Builder $query) => $query->where('guest_support_conversation_id', $guestId))
            ->when($beforeId, fn (Builder $query) => $query->where('id', '<', $beforeId))
            ->latest('id')
            ->limit(11)
            ->get();

        return [
            'messages' => $messages->take(10)->reverse()->values(),
            'has_more' => $messages->count() > 10,
        ];
    }

    /** Mark messages from the opposite participant as read when a thread is opened. */
    public function markRead(?int $businessId, ?int $guestId, string $senderType): void
    {
        SupportMessage::query()
            ->when($businessId, fn (Builder $query) => $query->where('business_id', $businessId))
            ->when($guestId, fn (Builder $query) => $query->where('guest_support_conversation_id', $guestId))
            ->where('sender_type', $senderType)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Store an optional attachment in a conversation-specific private directory. */
    public function storeAttachment(Request $request, ?int $businessId, ?int $guestId = null): array
    {
        if (! $request->hasFile('attachment')) {
            return [];
        }

        $file = $request->file('attachment');
        $directory = $businessId ? "support/{$businessId}" : "support/guests/{$guestId}";

        return [
            'attachment_path' => $file->store($directory, 'local'),
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_mime' => $file->getMimeType(),
            'attachment_size' => $file->getSize(),
        ];
    }

    /** Stream a private attachment after its caller has authorized the conversation. */
    public function download(SupportMessage $message): StreamedResponse
    {
        abort_unless($message->attachment_path && Storage::disk('local')->exists($message->attachment_path), 404);

        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name, [
            'Content-Type' => $message->attachment_mime,
        ]);
    }
}
