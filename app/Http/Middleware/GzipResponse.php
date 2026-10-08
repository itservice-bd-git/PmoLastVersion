<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compresses a (large) HTML/JSON response itself when the client accepts gzip and nothing in front of PHP has done it.
 * Not every host compresses PHP output (the local XAMPP Apache does not), and the /planning page + its board JSON are
 * 5-15x smaller compressed. Safe next to server-side compression: a response that already carries Content-Encoding is
 * left alone, and Apache/LiteSpeed skip one that is already encoded.
 */
class GzipResponse
{
    private const MIN_BYTES = 2048;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! function_exists('gzencode')
            || ! str_contains((string) $request->header('Accept-Encoding'), 'gzip')
            || $response->headers->has('Content-Encoding')
            || $response->getStatusCode() !== 200
            || $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $response->setContent(gzencode($content, 5));
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Vary', 'Accept-Encoding');
        $response->headers->remove('Content-Length');

        return $response;
    }
}
