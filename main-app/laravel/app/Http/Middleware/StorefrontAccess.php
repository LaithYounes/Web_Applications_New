<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class StorefrontAccess
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = Auth::guard('api')->user();

        if (! $user) {
            return new RedirectResponse('/login?'.http_build_query(['returnTo' => $request->getRequestUri()]));
        }

        if ($role !== null && $user->role !== $role) {
            return response()->view('storefront', [], 403);
        }

        return $next($request);
    }
}
