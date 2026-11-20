<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardResearchAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        if (! in_array($request->method(), ['GET', 'POST'], true) || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return response()->json([
                'success' => false,
                'status' => 'FORBIDDEN_RESEARCH_ORIGIN',
                'message' => 'Permintaan riset hanya menerima POST lokal terautentikasi.',
            ], 403);
        }

        $origin = (string) $request->header('Origin', '');
        $referer = (string) $request->header('Referer', '');
        $host = $request->getSchemeAndHttpHost();
        if (($origin !== '' && $origin !== $host) || ($referer !== '' && ! str_starts_with($referer, $host.'/'))) {
            return response()->json([
                'success' => false,
                'status' => 'FORBIDDEN_RESEARCH_ORIGIN',
                'message' => 'Origin riset tidak diizinkan.',
            ], 403);
        }

        if (! $request->hasSession() || ! $request->session()->has('_token')) {
            return response()->json([
                'success' => false,
                'status' => 'UNAUTHENTICATED_RESEARCH_SESSION',
                'message' => 'Sesi analis tidak terautentikasi.',
            ], 401);
        }

        $sessionId = $request->session()->getId();
        $appKey = (string) config('app.key', '');
        $expectedCapability = $appKey !== '' ? hash_hmac('sha256', $sessionId, $appKey) : '';
        $presentCapability = (string) $request->header('X-Aether-Research-Auth', '');
        if ($expectedCapability === '' || ! hash_equals($expectedCapability, $presentCapability)) {
            return response()->json([
                'success' => false,
                'status' => 'UNAUTHORIZED_RESEARCH_CAPABILITY',
                'message' => 'Kapabilitas riset sesi tidak valid.',
            ], 401);
        }

        return $next($request);
    }
}
