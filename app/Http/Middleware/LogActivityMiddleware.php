<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogActivityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (auth()->check()) {
            $user = auth()->user();

            activity()
                ->causedBy($user)
                ->withProperties([
                    'url'         => $request->fullUrl(),
                    'method'      => $request->method(),
                    'ip'          => $request->ip(),
                    'user_agent'  => $request->userAgent(),
                    'status_code' => $response->getStatusCode(),
                ])
                ->log("L'utilisateur {$user->login} a accédé à : " . $request->path());
        }
        return $response;
    }
}
