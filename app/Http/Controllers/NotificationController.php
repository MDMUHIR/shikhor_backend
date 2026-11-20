<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->boolean('unread')
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        $notifications = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $notifications->setCollection($notifications->getCollection()->map(fn ($n) => [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? null,
            'title' => $n->data['title'] ?? null,
            'message' => $n->data['message'] ?? null,
            'data' => $n->data['data'] ?? [],
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ]));

        return $this->success('Notifications retrieved successfully', [
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'notifications' => $notifications,
        ]);
    }

    public function read(Request $request, $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return $this->error('Notification not found', 404);
        }

        $notification->markAsRead();

        return $this->success('Notification marked as read', null);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->success('All notifications marked as read', null);
    }
}
