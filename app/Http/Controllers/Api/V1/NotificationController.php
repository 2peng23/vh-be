<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $r)
    {
        return $this->paginated($r->user()->notifications()->paginate(min((int) $r->input('per_page', 20), 100)));
    }

    public function unreadCount(Request $r)
    {
        return $this->ok(['count' => $r->user()->unreadNotifications()->count()]);
    }

    public function read(Request $r, string $id)
    {
        $n = $r->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        return $this->ok($n, 'Notification read.');
    }

    public function readAll(Request $r)
    {
        $r->user()->unreadNotifications->markAsRead();

        return $this->ok(null, 'All notifications read.');
    }
}
