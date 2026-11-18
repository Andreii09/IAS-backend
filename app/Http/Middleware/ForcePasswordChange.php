<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /** Routes a user with a temporary password may still call. */
    private const ALLOWED_PATHS = ['logout', 'change-password', 'me'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $path = str_starts_with($path, 'api/') ? substr($path, 4) : $path;

        if (in_array(rtrim($path, '/'), self::ALLOWED_PATHS, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You must change your password before continuing.',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
        ], 403);
    }
}
