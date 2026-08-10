<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

use Illuminate\Http\Client\Response;

final class WordPressRestResponseParser
{
    public static function formatHttpErrorMessage(int $status, Response $response): string
    {
        $parts = ['WordPress trả lỗi HTTP ' . $status];

        $body = $response->json();
        if (is_array($body)) {
            foreach (['message', 'error', 'error_file', 'error_class'] as $key) {
                $value = trim((string) ($body[$key] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }

            $debug = $body['debug'] ?? null;
            if (is_array($debug)) {
                $exceptionMessage = trim((string) ($debug['exception_message'] ?? ''));
                if ($exceptionMessage !== '') {
                    $parts[] = 'Exception: ' . $exceptionMessage;
                }

                $file = trim((string) ($debug['file'] ?? ''));
                $line = (int) ($debug['line'] ?? 0);
                if ($file !== '') {
                    $parts[] = 'File: ' . $file . ($line > 0 ? ':' . $line : '');
                }

                $logFile = trim((string) ($debug['log_file'] ?? ''));
                if ($logFile !== '') {
                    $parts[] = 'WP log: ' . $logFile;
                }
            }
        } else {
            $raw = trim(strip_tags((string) $response->body()));
            if ($raw !== '') {
                $parts[] = mb_substr($raw, 0, 300);
            }
        }

        return mb_substr(implode(' — ', array_values(array_unique($parts))), 0, 900);
    }
}
