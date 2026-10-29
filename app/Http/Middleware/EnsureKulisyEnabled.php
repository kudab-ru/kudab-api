<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKulisyEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('kulisy.enabled')) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return $next($request);
    }
}
