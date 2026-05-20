<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\InAppUserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    private const CASE_INTERACTION_CATEGORY = 'case';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $limit = (int) $request->query('limit', 20);
        $limit = max(1, min($limit, 100));
        $role = strtolower((string) ($user->role ?? ''));

        if ($role === 'admin' || $role === 'adminstaff') {
            $notifications = DatabaseNotification::query()
                ->where('type', InAppUserNotification::class)
                ->where('data', 'like', '%"category":"' . self::CASE_INTERACTION_CATEGORY . '"%')
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn ($item) => $this->transformNotification($item))
                ->values();

            $unreadCount = (int) DatabaseNotification::query()
                ->where('type', InAppUserNotification::class)
                ->where('data', 'like', '%"category":"' . self::CASE_INTERACTION_CATEGORY . '"%')
                ->whereNull('read_at')
                ->count();

            return response()->json([
                'data' => $notifications,
                'unread_count' => $unreadCount,
            ]);
        }

        $notifications = $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($item) => $this->transformNotification($item))
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

        $role = strtolower((string) ($user->role ?? ''));
        $notificationId = (string) $request->input('id', '');

        if ($role === 'admin' || $role === 'adminstaff') {
            $adminCaseNotifications = DatabaseNotification::query()
                ->where('type', InAppUserNotification::class)
                ->where('data', 'like', '%"category":"' . self::CASE_INTERACTION_CATEGORY . '"%');

            if ($notificationId !== '') {
                $target = (clone $adminCaseNotifications)
                    ->where('id', $notificationId)
                    ->whereNull('read_at')
                    ->first();

                if ($target) {
                    $target->markAsRead();
                }
            } else {
                (clone $adminCaseNotifications)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            return response()->json([
                'message' => 'Notification(s) marked as read.',
                'unread_count' => (int) DatabaseNotification::query()
                    ->where('type', InAppUserNotification::class)
                    ->where('data', 'like', '%"category":"' . self::CASE_INTERACTION_CATEGORY . '"%')
                    ->whereNull('read_at')
                    ->count(),
            ]);
        }

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

    private function transformNotification(DatabaseNotification $item): array
    {
        return [
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
        ];
    }
}
