<?php

namespace App\Http\Controllers;

use App\Services\NotificationFeed;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * The bell and the notices page. A notice is "ใหม่" until the person opens the bell (users.notifications_seen_at), then
 * "เห็นแล้ว". "ต้องทำ" ones (new work, sent back, alert, tag, overdue - PmoNotice::isTodo) stay until "รับทราบ" (read_at);
 * "ความเคลื่อนไหว" is done with once seen (NotificationService::seen).
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $unreadOnly = $request->query('filter') === 'unread';

        return view('notifications.index', [
            'notifications' => ($unreadOnly ? $request->user()->unreadNotifications() : $request->user()->notifications())->paginate(30)->withQueryString(),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** What the bell in the top bar shows; every page asks again every 15 seconds (so new notices appear without a reload). */
    public function feed(Request $request)
    {
        return response()->json(NotificationFeed::for($request->user()));
    }

    /** The bell was opened: the red number resets (NotificationService::seen). */
    public function seen(Request $request, NotificationService $notifications)
    {
        $notifications->seen($request->user());

        return response()->json(NotificationFeed::for($request->user()->fresh()));
    }

    /** "รับทราบ" on one notice (the bell, the small card). */
    public function ack(Request $request, NotificationService $notifications, string $id)
    {
        $notifications->acknowledge($request->user(), $request->user()->notifications()->whereKey($id)->get());

        return response()->json(NotificationFeed::for($request->user()));
    }

    /** Opening a notice counts as "รับทราบ" too, then goes where it points. */
    public function read(Request $request, NotificationService $notifications, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notifications->acknowledge($request->user(), collect([$notification]));

        return redirect($notification->data['url'] ?? route('notifications.index'));
    }

    /** "รับทราบทั้งหมด". */
    public function readAll(Request $request, NotificationService $notifications)
    {
        $notifications->acknowledge($request->user(), $request->user()->unreadNotifications()->get());

        return $request->wantsJson() ? response()->json(NotificationFeed::for($request->user())) : back();
    }
}
