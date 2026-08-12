<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Business;
use App\Models\GuestSupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportTemplate;
use App\Services\SupportConversationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportController extends ApiController
{
    public function __construct(private readonly SupportConversationService $support) {}

    /** Start an unauthenticated, browser-bound support conversation. */
    public function createGuestConversation(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
        ]);

        return $this->ok($this->support->createGuest($data), 'Support conversation created.', 201);
    }

    /** Load a guest's latest messages after validating its private token. */
    public function guestMessages(Request $request)
    {
        $conversation = $this->guestConversation($request);
        $this->support->markRead(null, $conversation->id, 'super_admin');

        return $this->messagePage(null, $request->integer('before_id') ?: null, $conversation->id);
    }

    /** Add a guest message to the token-authorized conversation. */
    public function guestSend(Request $request)
    {
        $conversation = $this->guestConversation($request);
        $data = $this->validateMessage($request);
        $attachment = $this->support->storeAttachment($request, null, $conversation->id);
        $message = SupportMessage::create([
            'guest_support_conversation_id' => $conversation->id,
            'sender_type' => 'guest',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message, 'Message sent.', 201);
    }

    /** Load the authenticated owner's business conversation. */
    public function messages(Request $request)
    {
        $this->ownerOnly($request);
        $this->support->markRead($request->user()->business_id, null, 'super_admin');

        return $this->messagePage($request->user()->business_id, $request->integer('before_id') ?: null);
    }

    /** Add an authenticated owner message to its business conversation. */
    public function send(Request $request)
    {
        $this->ownerOnly($request);
        $data = $this->validateMessage($request);
        $attachment = $this->support->storeAttachment($request, $request->user()->business_id);
        $message = SupportMessage::create([
            'business_id' => $request->user()->business_id,
            'user_id' => $request->user()->id,
            'sender_type' => 'tenant',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message->load('user:id,name'), 'Message sent.', 201);
    }

    /** Return the owner's unread Super Admin reply count. */
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

    /** List all business and guest threads for Super Admin. */
    public function conversations(Request $request)
    {
        $this->superAdmin($request);

        return $this->ok($this->support->adminConversations());
    }

    /** List reusable replies in a stable alphabetical order. */
    public function templates(Request $request)
    {
        $this->superAdmin($request);

        return $this->ok(SupportTemplate::orderBy('title')->get());
    }

    /** Create a reusable Super Admin support reply. */
    public function storeTemplate(Request $request)
    {
        $this->superAdmin($request);
        $data = $request->validate([
            'title' => 'required|string|max:100|unique:support_templates,title',
            'message' => 'required|string|max:5000',
        ]);

        return $this->ok(SupportTemplate::create($data), 'Template created.', 201);
    }

    /** Update an existing reusable Super Admin support reply. */
    public function updateTemplate(Request $request, SupportTemplate $supportTemplate)
    {
        $this->superAdmin($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100', Rule::unique('support_templates', 'title')->ignore($supportTemplate->id)],
            'message' => 'required|string|max:5000',
        ]);
        $supportTemplate->update($data);

        return $this->ok($supportTemplate->fresh(), 'Template updated.');
    }

    /** Load a business thread and mark tenant messages as read. */
    public function adminMessages(Request $request, Business $business)
    {
        $this->superAdmin($request);
        $this->support->markRead($business->id, null, 'tenant');

        return $this->messagePage($business->id, $request->integer('before_id') ?: null);
    }

    /** Send a Super Admin reply to a business thread. */
    public function adminSend(Request $request, Business $business)
    {
        $this->superAdmin($request);
        $data = $this->validateMessage($request);
        $attachment = $this->support->storeAttachment($request, $business->id);
        $message = SupportMessage::create([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'sender_type' => 'super_admin',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message->load('user:id,name'), 'Reply sent.', 201);
    }

    /** Load a guest thread and mark guest messages as read. */
    public function adminGuestMessages(Request $request, GuestSupportConversation $guestSupportConversation)
    {
        $this->superAdmin($request);
        $this->support->markRead(null, $guestSupportConversation->id, 'guest');

        return $this->messagePage(null, $request->integer('before_id') ?: null, $guestSupportConversation->id);
    }

    /** Send a Super Admin reply to a guest thread. */
    public function adminGuestSend(Request $request, GuestSupportConversation $guestSupportConversation)
    {
        $this->superAdmin($request);
        $data = $this->validateMessage($request);
        $attachment = $this->support->storeAttachment($request, null, $guestSupportConversation->id);
        $message = SupportMessage::create([
            'guest_support_conversation_id' => $guestSupportConversation->id,
            'user_id' => $request->user()->id,
            'sender_type' => 'super_admin',
            'message' => $data['message'] ?? null,
            ...$attachment,
        ]);

        return $this->ok($message->load('user:id,name'), 'Reply sent.', 201);
    }

    /** Enforce Super Admin access for platform support operations. */
    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    /** Restrict tenant support conversations to business owners. */
    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user()->business_id && $request->user()->role->value === 'owner', 403);
    }

    /** Download a business attachment after tenant authorization. */
    public function download(Request $request, SupportMessage $supportMessage)
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || ($user->role->value === 'owner' && $user->business_id === $supportMessage->business_id), 403);

        return $this->support->download($supportMessage);
    }

    /** Download a guest attachment after token authorization. */
    public function guestDownload(Request $request, SupportMessage $supportMessage)
    {
        $conversation = $this->guestConversation($request);
        abort_unless($supportMessage->guest_support_conversation_id === $conversation->id, 403);

        return $this->support->download($supportMessage);
    }

    /** Validate text and attachment input shared by all sender types. */
    private function validateMessage(Request $request): array
    {
        return $request->validate([
            'message' => 'nullable|string|max:5000|required_without:attachment',
            'attachment' => 'nullable|file|max:10240|extensions:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt',
        ]);
    }

    /** Build the cursor-based message response used by each chat client. */
    private function messagePage(?int $businessId, ?int $beforeId, ?int $guestId = null)
    {
        $page = $this->support->page($businessId, $guestId, $beforeId);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $page['messages'],
            'meta' => ['has_more' => $page['has_more']],
        ]);
    }

    /** Resolve the guest token supplied by the public chat client. */
    private function guestConversation(Request $request): GuestSupportConversation
    {
        return $this->support->resolveGuestToken($request->header('X-Support-Token'));
    }
}
