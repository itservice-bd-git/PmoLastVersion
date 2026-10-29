<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "ดูได้อย่างเดียว" (Settings > สิทธิ์การใช้งาน, edit.work off - e.g. ขาย): a person of such a department may look at
 * everything they see, but every write is refused here, on the server, whatever page or JSON sends it. Only what is
 * their own stays: reading / acknowledging notices, their profile, password, push and notice preferences, logging out.
 */
class EnsureCanWork
{
    /** route names (or prefixes ending in '.') a view-only person may still send */
    private const OWN = ['notifications.', 'status-report.acknowledge', 'projects.follow', 'profile.', 'password.update', 'push.', 'settings.notifications.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $request->isMethodSafe() || ! $user->isViewOnly() || $this->own((string) $request->route()?->getName())) {
            return $next($request);
        }
        $message = 'บัญชีนี้ดูได้อย่างเดียว — แก้ไขหรือบันทึกไม่ได้';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'results' => [['ok' => false, 'message' => $message]]], 403);
        }

        return back()->with('error', $message);
    }

    private function own(string $name): bool
    {
        foreach (self::OWN as $own) {
            if ($name === $own || (str_ends_with($own, '.') && str_starts_with($name, $own))) {
                return true;
            }
        }

        return false;
    }
}
