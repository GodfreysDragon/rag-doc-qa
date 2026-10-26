<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks uploads and questions until the user has verified their email, but
 * only when rag.auth.verify_email is on. Checked per request rather than when
 * routes load, so the setting can change without re-caching routes.
 */
class EnsureEmailIsVerifiedWhenRequired
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('rag.auth.verify_email') && ! $request->user()?->hasVerifiedEmail()) {
            return response()->json(['message' => 'Verify your email address first. Check your inbox for the link.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
