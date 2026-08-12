<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Business;
use App\Models\GuestSupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportController extends ApiController
{
    public function createGuestConversation(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
        ]);
        $token = Str::random(64);
        $conversation = GuestSupportConversation::create([
            ...$data,
            'email' => Str::lower($data['email']),
            'access_token_hash' => hash('sha256', $token),
        ]);

        return $this->ok(['conversation' => $conversation, 'token' => $token], 'Support conversation created.', 201);
    }

    public function guestMessages(Request $request)
    {
        $conversation = $this->guestConversation($request);
        SupportMessage::where('guest_support_conversation_id', $conversation->id)
            ->where('sender_type', 'super_admin')->whereNull('read_at')->update(['read_at' => now()]);

        return $this->messagePage(null, $request->integer('before_id') ?: null, $conversation->id);
    }

    public function guestSend(Request $request)
    {
        $conversation = $this->guestConversation($request);
        $data = $this->validateMessage($request);
        $attachment = $this->storeAttachment($request, null, $conversation->id);
        $message = SupportMessage::create([
            'guest_support_conversation_id' => $conversation->id,
            'sender_type' => 'guest',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message, 'Message sent.', 201);
    }

    public function messages(Request $request)
    {
        $this->ownerOnly($request);
        SupportMessage::where('business_id', $request->user()->business_id)
            ->where('sender_type', 'super_admin')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->messagePage($request->user()->business_id, $request->integer('before_id') ?: null);
    }

    public function send(Request $request)
    {
        $this->ownerOnly($request);
        $data = $this->validateMessage($request);
        $attachment = $this->storeAttachment($request, $request->user()->business_id);
        $message = SupportMessage::create([
            'business_id' => $request->user()->business_id,
            'user_id' => $request->user()->id,
            'sender_type' => 'tenant',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message->load('user:id,name'), 'Message sent.', 201);
    }

    public function unreadCount(Request $request)
    {
        $this->ownerOnly($request);

        return $this->ok([
            'count' => SupportMessage::where('business_id', $request->user()->business_id)
                ->where('sender_type', 'super_admin')
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function conversations(Request $request)
    {
        $this->superAdmin($request);

        $businesses = Business::whereHas('supportMessages')
            ->withCount(['supportMessages as unread_support_count' => fn ($query) => $query
                ->where('sender_type', 'tenant')->whereNull('read_at')])
            ->withMax('supportMessages', 'created_at')
            ->orderByDesc('support_messages_max_created_at')
            ->get()->map(fn ($business) => [...$business->toArray(), 'conversation_type' => 'business']);
        $guests = GuestSupportConversation::whereHas('messages')
            ->withCount(['messages as unread_support_count' => fn ($query) => $query
                ->where('sender_type', 'guest')->whereNull('read_at')])
            ->withMax('messages', 'created_at')
            ->get()->map(fn ($guest) => [
                ...$guest->toArray(),
                'conversation_type' => 'guest',
                'subscription_status' => null,
                'support_messages_max_created_at' => $guest->messages_max_created_at,
            ]);

        return $this->ok($businesses->concat($guests)->sortByDesc('support_messages_max_created_at')->values());
    }

    public function templates(Request $request)
    {
        $this->superAdmin($request);

        return $this->ok(SupportTemplate::orderBy('title')->get());
    }

    public function storeTemplate(Request $request)
    {
        $this->superAdmin($request);
        $data = $request->validate([
            'title' => 'required|string|max:100|unique:support_templates,title',
            'message' => 'required|string|max:5000',
        ]);

        return $this->ok(SupportTemplate::create($data), 'Template created.', 201);
    }

    public function adminMessages(Request $request, Business $business)
    {
        $this->superAdmin($request);
        SupportMessage::where('business_id', $business->id)
            ->where('sender_type', 'tenant')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->messagePage($business->id, $request->integer('before_id') ?: null);
    }

    public function adminSend(Request $request, Business $business)
    {
        $this->superAdmin($request);
        $data = $this->validateMessage($request);
        $attachment = $this->storeAttachment($request, $business->id);
        $message = SupportMessage::create([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'sender_type' => 'super_admin',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message->load('user:id,name'), 'Reply sent.', 201);
    }

    public function adminGuestMessages(Request $request, GuestSupportConversation $guestSupportConversation)
    {
        $this->superAdmin($request);
        SupportMessage::where('guest_support_conversation_id', $guestSupportConversation->id)
            ->where('sender_type', 'guest')->whereNull('read_at')->update(['read_at' => now()]);

        return $this->messagePage(null, $request->integer('before_id') ?: null, $guestSupportConversation->id);
    }

    public function adminGuestSend(Request $request, GuestSupportConversation $guestSupportConversation)
    {
        $this->superAdmin($request);
        $data = $this->validateMessage($request);
        $attachment = $this->storeAttachment($request, null, $guestSupportConversation->id);
        $message = SupportMessage::create([
            'guest_support_conversation_id' => $guestSupportConversation->id,
            'user_id' => $request->user()->id,
            'sender_type' => 'super_admin',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message->load('user:id,name'), 'Reply sent.', 201);
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user()->business_id && $request->user()->role->value === 'owner', 403);
    }

    public function download(Request $request, SupportMessage $supportMessage)
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || ($user->role->value === 'owner' && $user->business_id === $supportMessage->business_id), 403);
        abort_unless($supportMessage->attachment_path && Storage::disk('local')->exists($supportMessage->attachment_path), 404);

        return Storage::disk('local')->download(
            $supportMessage->attachment_path,
            $supportMessage->attachment_name,
            ['Content-Type' => $supportMessage->attachment_mime]
        );
    }

    public function guestDownload(Request $request, SupportMessage $supportMessage)
    {
        $conversation = $this->guestConversation($request);
        abort_unless($supportMessage->guest_support_conversation_id === $conversation->id, 403);
        abort_unless($supportMessage->attachment_path && Storage::disk('local')->exists($supportMessage->attachment_path), 404);

        return Storage::disk('local')->download($supportMessage->attachment_path, $supportMessage->attachment_name, [
            'Content-Type' => $supportMessage->attachment_mime,
        ]);
    }

    private function validateMessage(Request $request): array
    {
        return $request->validate([
            'message' => 'nullable|string|max:5000|required_without:attachment',
            'attachment' => 'nullable|file|max:10240|extensions:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt',
        ]);
    }

    private function storeAttachment(Request $request, ?int $businessId, ?int $guestId = null): array
    {
        if (! $request->hasFile('attachment')) {
            return [];
        }
        $file = $request->file('attachment');

        return [
            'attachment_path' => $file->store($businessId ? "support/{$businessId}" : "support/guests/{$guestId}", 'local'),
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_mime' => $file->getMimeType(),
            'attachment_size' => $file->getSize(),
        ];
    }

    private function messagePage(?int $businessId, ?int $beforeId, ?int $guestId = null)
    {
        $query = SupportMessage::with('user:id,name')
            ->when($businessId, fn ($messages) => $messages->where('business_id', $businessId))
            ->when($guestId, fn ($messages) => $messages->where('guest_support_conversation_id', $guestId))
            ->when($beforeId, fn ($messages) => $messages->where('id', '<', $beforeId))
            ->latest('id');
        $messages = $query->limit(11)->get();
        $hasMore = $messages->count() > 10;

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $messages->take(10)->reverse()->values(),
            'meta' => ['has_more' => $hasMore],
        ]);
    }

    private function guestConversation(Request $request): GuestSupportConversation
    {
        $token = $request->header('X-Support-Token');
        abort_unless(is_string($token) && strlen($token) >= 40, 401, 'Support conversation token is missing.');

        return GuestSupportConversation::where('access_token_hash', hash('sha256', $token))->firstOrFail();
    }
}
