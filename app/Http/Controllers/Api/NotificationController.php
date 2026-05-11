<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $limit = (int) $request->query('limit', 20);
        $limit = max(1, min($limit, 100));

        $notifications = $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'title' => (string) data_get($item->data, 'title', 'Notification'),
                'message' => (string) data_get($item->data, 'message', ''),
                'category' => (string) data_get($item->data, 'category', 'general'),
                'case_id' => data_get($item->data, 'case_id'),
                'meeting_id' => data_get($item->data, 'meeting_id'),
                'actor_name' => data_get($item->data, 'actor_name'),
                'meta' => data_get($item->data, 'meta'),
                'read_at' => $item->read_at,
                'created_at' => $item->created_at,
            ])
            ->values();

        return response()->json([
            'data' => $notifications,
            'unread_count' => (int) $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $notificationId = (string) $request->input('id', '');

        if ($notificationId !== '') {
            $target = $user->unreadNotifications()->where('id', $notificationId)->first();
            if ($target) {
                $target->markAsRead();
            }
        } else {
            $user->unreadNotifications->markAsRead();
        }

        return response()->json([
            'message' => 'Notification(s) marked as read.',
            'unread_count' => (int) $user->unreadNotifications()->count(),
        ]);
    }
}
