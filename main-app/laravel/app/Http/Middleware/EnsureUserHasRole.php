<?php

namespace App\Http\Middleware;

use App\Http\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(
        Request $request,
        Closure $next,
        string $role
    ): Response {
        $user = $request->user();

        if (!$user || $user->role !== $role) {
            return ApiErrorResponse::make(403, 'You are not authorized to perform this action');
        }

        return $next($request);
    }
}
