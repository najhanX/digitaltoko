<?php

namespace App\Http\Controllers;

use App\Models\PanelNotification;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\PanelNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;

class PanelNotificationController extends Controller
{
    private function recipient(Request $request, PanelNotifications $notifications): User|StoreStaff
    {
        $recipient = $notifications->recipient($request);
        abort_unless($recipient, 401);

        return $recipient;
    }

    public function index(Request $request, PanelNotifications $notifications): JsonResponse
    {
        $recipient = $this->recipient($request, $notifications);
        $data = $request->validate(['cursor' => 'nullable|string|max:1024', 'filter' => 'nullable|in:all,unread']);
        $cursor = Cursor::fromEncoded($data['cursor'] ?? null);
        if (! empty($data['cursor'])) {
            $parameters = $cursor?->toArray() ?? [];
            Validator::make($parameters, ['created_at' => 'required|date', 'id' => 'required|uuid', '_pointsToNextItems' => 'required|accepted'])->validate();
        }
        $query = $notifications->query($recipient);
        if (($data['filter'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        }
        $total = (clone $query)->count();
        $paginator = $query->latest()->orderByDesc('id')->cursorPaginate(10, ['*'], 'cursor', $cursor);

        return response()->json(['status' => 'SUCCESS', 'data' => [
            'recipient' => $notifications->key($recipient), 'items' => $paginator->getCollection()->map(fn ($item) => $notifications->payload($item, $recipient)),
            'unread_count' => $notifications->query($recipient)->whereNull('read_at')->count(),
            'next_cursor' => $paginator->nextCursor()?->encode(), 'total' => $total,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, PanelNotification $notification, PanelNotifications $notifications): JsonResponse
    {
        $recipient = $this->recipient($request, $notifications);
        $owned = $notifications->query($recipient)->whereKey($notification->id)->firstOrFail();
        if (! $owned->read_at) {
            $owned->update(['read_at' => now()]);
        }

        return response()->json(['status' => 'SUCCESS', 'data' => ['read_at' => $owned->read_at,
            'unread_count' => $notifications->query($recipient)->whereNull('read_at')->count(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function readAll(Request $request, PanelNotifications $notifications): JsonResponse
    {
        $recipient = $this->recipient($request, $notifications);
        $readAt = now();
        $notifications->query($recipient)->whereNull('read_at')->update(['read_at' => $readAt]);

        return response()->json(['status' => 'SUCCESS', 'data' => ['read_at' => $readAt,
            'unread_count' => $notifications->query($recipient)->whereNull('read_at')->count(),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
