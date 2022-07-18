<?php

namespace App\Http\Middleware;

use Closure;
use http\Env\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ValidateRouteMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (!array_key_exists($request->route('store'), config('services.shopify'))) {
            Log::error('Store "' . $request->route('store') . '" not available');
            return response('Store "' . $request->route('store') . '" not available', 400);
        }
        return $next($request);
    }
}
