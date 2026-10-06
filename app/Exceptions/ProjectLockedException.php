<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A closed (Completed/Cancelled) Project can't be changed any further.
 * Renders itself so callers don't each need a try/catch: JSON callers (the
 * fetch()-driven panels) get a 423, page forms bounce back with a flash error.
 */
class ProjectLockedException extends RuntimeException
{
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 423);
        }

        return redirect()->back()->with('error', $this->getMessage());
    }
}
