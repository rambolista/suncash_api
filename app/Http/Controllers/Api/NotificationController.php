<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                ...$notification->data,
                'read_at' => optional($notification->read_at)->toISOString(),
                'created_at' => optional($notification->created_at)->toISOString(),
            ]);

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $item = $this->findForUser($request, $notification);
        $item->markAsRead();

        ActivityLog::recordAction($request->user(), 'Notifications', 'updated', 'Marked notification as read', $item, $request);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        ActivityLog::recordAction($request->user(), 'Notifications', 'updated', 'Marked all notifications as read', null, $request);

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $item = $this->findForUser($request, $notification);
        $item->delete();

        ActivityLog::recordAction($request->user(), 'Notifications', 'deleted', 'Dismissed notification', $item, $request);

        return response()->json(['message' => 'Notification dismissed.']);
    }

    private function findForUser(Request $request, string $notification): DatabaseNotification
    {
        return $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();
    }
}
