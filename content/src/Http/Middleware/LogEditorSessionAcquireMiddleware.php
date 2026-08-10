<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Temporary diagnostics for editor-sessions acquire failures (local debug).
 */
final class LogEditorSessionAcquireMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $log = storage_path('logs/editor-session-debug.log');
        @file_put_contents(
            $log,
            date('c').' MW_IN auth='.(auth()->id() ?? 'null')
            .' method='.$request->method()
            .' path=/'.ltrim($request->path(), '/')
            .' csrf_hdr_len='.strlen((string) $request->header('X-CSRF-TOKEN', ''))
            .' has_session='.($request->hasSession() ? '1' : '0')
            .' content_type='.(string) $request->header('Content-Type', '')
            .' body_len='.strlen((string) $request->getContent())
            .' seo='.(string) $request->header('X-SEO-Connection', '')
            ."\n",
            FILE_APPEND,
        );

        try {
            /** @var Response $response */
            $response = $next($request);
            @file_put_contents(
                $log,
                date('c').' MW_OUT status='.$response->getStatusCode()
                .' body='.substr((string) $response->getContent(), 0, 500)
                ."\n",
                FILE_APPEND,
            );

            return $response;
        } catch (Throwable $exception) {
            @file_put_contents(
                $log,
                date('c').' MW_THROW '.$exception::class.' '.$exception->getMessage()."\n",
                FILE_APPEND,
            );

            throw $exception;
        }
    }
}
