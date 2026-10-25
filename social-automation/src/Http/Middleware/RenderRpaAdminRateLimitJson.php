<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep framework rate limiting while preserving the RPA Admin JSON contract. */
final class RenderRpaAdminRateLimitJson
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (ThrottleRequestsException $exception) {
            $headers = $exception->getHeaders();
            $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

            return new JsonResponse([
                'error' => 'rate_limited',
                'message' => 'Too many requests',
                'retry_after' => $retryAfter,
            ], 429, $headers);
        }
    }
}
