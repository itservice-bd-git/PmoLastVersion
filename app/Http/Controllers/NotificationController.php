<?php

namespace App\Http\Controllers;

use App\Notifications\PmoNotice;
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
        return response()->json(self::feedFor($request->user()));
    }

    public static function feedFor($user): array
    {
        $seen = $user->notifications_seen_at;
        $isNew = fn ($n) => $n->read_at === null && (! $seen || $n->created_at->gt($seen));
        $acceptUrl = function ($n) use ($user) {
            $url = $n->data['url'] ?? '';
            if ((($n->data['kind'] ?? '') !== 'assigned' && empty($n->data['parts']['assigned'])) || ! str_contains($url, 'planning/board')) {
                return null;
            }
            if (! str_contains($url, 'stage=')) {
                if (! $user->department_id) {
                    return null;
                }
                $url .= (str_contains($url, '?') ? '&' : '?').'stage=d_'.$user->department_id;
            }

            return $url.'&accept=1';
        };
        $row = fn ($n) => [
            'id' => $n->id, 'kind' => $n->data['kind'] ?? '', 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '',
            'latest' => $n->data['latest'] ?? null, 'at' => $n->created_at->thaiDateTime(),
            'todo' => PmoNotice::todoOf($n->data), 'new' => $isNew($n), 'acked' => $n->read_at !== null,
            'ackedAt' => $n->read_at?->format('H:i'),
            // new work: "รับงาน" opens the department's window on Planning and accepts what is waiting there
            // (a project's notice that also holds a reminder takes the reminder's kind and link, so look at its parts too)
            'acceptUrl' => $acceptUrl($n),
        ];
        $open = $user->unreadNotifications()->latest()->limit(100)->get()->toBase()->filter(fn ($n) => PmoNotice::todoOf($n->data));
        $recent = $user->notifications()->limit(40)->get()->toBase();

        return [
            'unread' => $user->unreadNotifications()->when($seen, fn ($q) => $q->where('created_at', '>', $seen))->count(),   // the red number: new since the bell was last opened
            'todoOpen' => $open->count(),
            // "ต้องทำ": still to acknowledge, then the last few acknowledged (faded)
            'todo' => $open->take(20)->map($row)->merge($recent->filter(fn ($n) => $n->read_at && PmoNotice::todoOf($n->data))->take(3)->map($row))->values()->all(),
            'feed' => $recent->reject(fn ($n) => PmoNotice::todoOf($n->data))->take(10)->map($row)->values()->all(),
            // unread urgent ones (tag, alert, new work, overdue): a new one pops the alert window up at once
            // (id + how many events it holds: a project's notice that gained something urgent counts as new)
            'urgent' => $user->unreadNotifications()->latest()->limit(50)->get()
                ->filter(fn ($n) => ($n->data['urgent'] ?? false) || in_array($n->data['kind'] ?? null, \App\Services\StatusReport::URGENT_KINDS, true))
                ->map(fn ($n) => $n->id.':'.($n->data['count'] ?? 1))->values()->all(),
        ];
    }

    /** The bell was opened: the red number resets (NotificationService::seen). */
    public function seen(Request $request, NotificationService $notifications)
    {
        $notifications->seen($request->user());

        return response()->json(self::feedFor($request->user()->fresh()));
    }

    /** "รับทราบ" on one notice (the bell, the small card). */
    public function ack(Request $request, NotificationService $notifications, string $id)
    {
        $notifications->acknowledge($request->user(), $request->user()->notifications()->whereKey($id)->get());

        return response()->json(self::feedFor($request->user()));
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

        return $request->wantsJson() ? response()->json(self::feedFor($request->user())) : back();
    }
}
