<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a sub task assignment action is refused (locked department,
 * wrong department user, invalid status transition). Renders itself as a JSON
 * error for async callers, or a flash error for normal form posts.
 */
class AssignmentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], $this->status);
        }

        return back()->with('error', $this->getMessage());
    }
}
