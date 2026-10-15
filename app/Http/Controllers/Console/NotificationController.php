<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Staff\Services\StaffNotificationInbox;
use App\Http\Controllers\Controller;
use App\Http\Requests\Console\NotificationRequest;
use Illuminate\Http\JsonResponse;

/**
 * The shell's notification bell, in both panels: the signed-in user's latest in-app notifications, the unread count it
 * polls, and marking them read. Answers JSON; the shell keeps its page where it is.
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly StaffNotificationInbox $inbox,
    ) {
    }

    public function index(NotificationRequest $request): JsonResponse
    {
        $user = $request->staff();

        return response()->json([
            'unread' => $this->inbox->unreadCount($user),
            'items'  => array_map(
                static fn (array $item): array => [...$item, 'readUrl' => route('console.notifications.read', ['notification' => $item['id']], false)],
                $this->inbox->latest($user),
            ),
        ]);
    }

    public function count(NotificationRequest $request): JsonResponse
    {
        return response()->json(['unread' => $this->inbox->unreadCount($request->staff())]);
    }

    public function read(NotificationRequest $request, string $notification): JsonResponse
    {
        $user = $request->staff();

        $this->inbox->markRead($user, $notification);

        return response()->json(['unread' => $this->inbox->unreadCount($user)]);
    }

    public function readAll(NotificationRequest $request): JsonResponse
    {
        $user = $request->staff();

        $this->inbox->markAllRead($user);

        // Counted again: a notification may have arrived since.
        return response()->json(['unread' => $this->inbox->unreadCount($user)]);
    }
}
