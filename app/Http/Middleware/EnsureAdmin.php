<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only routes (Settings > Users / Departments / Job Types). Without this any logged-in
 * user could open those pages and, for example, create an admin account.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น');

        return $next($request);
    }
}
