<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateBroadcastSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->validate([
            'socket_id' => [
                'required',
                'string',
                'max:100',
                'regex:/\A[0-9]+\.[0-9]+\z/',
            ],

            'channel_name' => [
                'required',
                'string',
                'max:200',
                'regex:/\Aprivate-[A-Za-z0-9_.-]+\z/',
            ],
        ]);

        return $next($request);
    }
}
