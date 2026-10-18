<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for the sign-in and first-password pages: a temporary password is typed here, so nothing is cached, referred or framed.
 * No `form-action` directive on purpose: a successful sign-in answers the form post with a redirect to the application, which a
 * `form-action 'self'` policy would block in some browsers.
 */
class ActivationPageHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");

        return $response;
    }
}
