<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->active) {
            return response()->json(['message' => 'This account is not active. Please contact your administrator.'], 401);
        }

        return $next($request);
    }
}