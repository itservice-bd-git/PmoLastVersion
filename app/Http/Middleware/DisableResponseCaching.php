<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page in this app is a logged-in, per-user view of live data - there
 * is never a reason for a browser, CDN, or hosting-level page cache (e.g.
 * LiteSpeed Cache) to store and replay it. Without these headers, some
 * shared-hosting setups cache full page/AJAX responses by default, which
 * shows stale data after an update until that outside cache happens to
 * expire - the app has no way to "bust" a cache it doesn't control, so it
 * explicitly tells every layer along the way not to keep a copy at all.
 */
class DisableResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
