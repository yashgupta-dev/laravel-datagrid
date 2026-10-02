<?php

namespace CodeCorner\Datagrid\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Datagrid responses stay JSON, carry no debug traces, and are not stored by browsers.
 */
class SecureResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        header_remove('X-Powered-By');

        /** @var Response $response */
        $response = $next($request);

        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $this->withoutDebugDetails($response);
    }

    private function withoutDebugDetails(Response $response): Response
    {
        $status = $response->getStatusCode();
        if ($status < 400 || $status === 204) {
            return $response;
        }

        $decoded = json_decode($response->getContent(), true);
        $leaks = ! is_array($decoded) || isset($decoded['trace']) || isset($decoded['exception']) || isset($decoded['file']);
        if (! $leaks) {
            return $response;
        }

        $message = match ($status) {
            401 => 'Unauthenticated.',
            403 => 'This action is unauthorized.',
            404 => 'Not found.',
            419 => 'Page expired.',
            422 => is_array($decoded) ? (string) ($decoded['message'] ?? 'The given data was invalid.') : 'The given data was invalid.',
            default => 'Request could not be completed.',
        };
        $body = ['message' => $message];
        if ($status === 422 && is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
            $body['errors'] = $decoded['errors'];
        }

        $response->setContent((string) json_encode($body));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
