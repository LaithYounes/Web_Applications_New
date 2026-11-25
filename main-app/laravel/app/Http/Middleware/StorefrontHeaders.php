<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class StorefrontHeaders
{
    public const CSP_ATTRIBUTE = 'storefront.csp';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = "'nonce-".Vite::useCspNonce()."'";
        $hot = Vite::isRunningHot() ? $this->origin(trim(file_get_contents(Vite::hotFile()))) : '';
        $reverb = $this->origin(config('storefront.reverb.url'));
        $connect = [
            $this->origin(config('storefront.api_origin')),
            $reverb,
            preg_replace('/^http/', 'ws', $reverb),
            $hot,
            preg_replace('/^http/', 'ws', $hot),
        ];
        $connect = implode(' ', array_unique(array_filter($connect)));
        $images = $this->origin(config('storefront.storage_origin'));

        $policy = "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; "
            ."script-src 'self' {$nonce} {$hot}; style-src 'self' {$nonce} {$hot}; "
            ."img-src 'self' blob: {$images}; font-src 'self' {$hot}; connect-src 'self' {$connect}; "
            ."worker-src 'self'; manifest-src 'self'; object-src 'none'";
        $request->attributes->set(self::CSP_ATTRIBUTE, $policy);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function origin(?string $url): string
    {
        $parts = parse_url($url ?? '');
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https', 'ws', 'wss'], true)
            || ! preg_match('/\A(?:[a-z0-9.-]+|\[[a-f0-9:]+\])\z/i', $host)) {
            return '';
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
