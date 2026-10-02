<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary: skips the login screen by auto-authenticating as the first
 * admin user. Swap the 'auto-login' alias back to 'auth' in routes/web.php
 * to restore the normal login requirement.
 */
class AutoLoginDemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $user = User::where('role', User::ROLE_ADMIN)->first() ?? User::first();

            if ($user) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
