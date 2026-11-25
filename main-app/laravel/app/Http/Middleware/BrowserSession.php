<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class BrowserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $request->headers->remove('Authorization');
        $request->server->remove('HTTP_AUTHORIZATION');
        $request->server->remove('REDIRECT_HTTP_AUTHORIZATION');
        $request->query->remove('token');
        $request->request->remove('token');
        $request->json()->remove('token');
        $request->cookies->remove(config('jwt.cookie_key_name', 'token'));
        $request->headers->set('Accept', 'application/json');

        $encryptedToken = $session->get('storefront.jwt');
        if ($encryptedToken) {
            try {
                $request->headers->set('Authorization', 'Bearer '.Crypt::decryptString($encryptedToken));
            } catch (DecryptException) {
                $session->forget('storefront.jwt');
            }
        }

        $authenticating = $request->is('api/browser/auth/login', 'api/browser/auth/register');
        $response = $next($request);

        if ($authenticating && $response instanceof JsonResponse && $response->isSuccessful()) {
            $payload = $response->getData(true);
            $credentials = $payload['data'] ?? [];
            abort_unless(strcasecmp($credentials['token_type'] ?? '', 'bearer') === 0
                && is_string($credentials['token'] ?? null) && isset($credentials['user']), 502);

            $session->regenerate(true);
            $session->put('storefront.jwt', Crypt::encryptString($credentials['token']));
            $payload['data'] = ['user' => $credentials['user']];
            $response->setData($payload);
        }

        if (($encryptedToken && $response->getStatusCode() === 401 && ! $authenticating)
            || $request->is('api/browser/auth/logout')) {
            $session->invalidate();
            $session->regenerateToken();
        }

        return $response;
    }
}
