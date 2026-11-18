<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Restrict a route to the given roles, e.g. role:admin,editor.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = collect($roles)
            ->flatMap(fn (string $role): array => explode(',', $role))
            ->map(fn (string $role): string => trim($role))
            ->filter()
            ->all();

        $user = $request->user();

        if (empty($allowed) || ! $user || ! ($user->is_active ?? true) || ! in_array($user->role, $allowed, true)) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}
