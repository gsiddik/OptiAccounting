<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Correlation id for audit records and logs; a client-provided id is accepted only if it is a sane token. */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->header('X-Request-Id');
        $id = preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $given) ? $given : (string) Str::uuid();
        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
