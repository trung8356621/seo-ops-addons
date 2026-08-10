<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

use Closure;
use RuntimeException;

/**
 * Đọc file SQL theo stream và tách từng câu lệnh hoàn chỉnh (kết thúc bằng ; ngoài chuỗi/comment).
 */
final class SeoSqlStreamParser
{
    /** @var list<string> */
    private const BLOCKED_PATTERNS = [
        '/\bINTO\s+OUTFILE\b/i',
        '/\bLOAD_FILE\s*\(/i',
        '/\bLOAD\s+DATA\b/i',
    ];

    /**
     * @param  resource  $handle
     * @param  Closure(string): void  $execute
     * @param  (Closure(int): void)|null  $onProgress  Nhận phần trăm 0-100 theo bytes đã đọc.
     * @return array{statements: int, bytes_read: int}
     */
    public function executeStream($handle, Closure $execute, ?int $totalBytes = null, ?Closure $onProgress = null): array
    {
        if (! is_resource($handle)) {
            throw new RuntimeException('Handle SQL không hợp lệ.');
        }

        $buffer = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        $escapeNext = false;
        $statements = 0;
        $bytesRead = 0;

        while (! feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $bytesRead += strlen($line);
            $length = strlen($line);

            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $next = $line[$i + 1] ?? '';

                if ($inLineComment) {
                    if ($char === "\n") {
                        $inLineComment = false;
                    }

                    continue;
                }

                if ($inBlockComment) {
                    if ($char === '*' && $next === '/') {
                        $inBlockComment = false;
                        $i++;
                    }

                    continue;
                }

                if (! $inSingleQuote && ! $inDoubleQuote && ! $inBacktick) {
                    if ($char === '-' && $next === '-') {
                        $inLineComment = true;
                        $i++;

                        continue;
                    }

                    if ($char === '#') {
                        $inLineComment = true;

                        continue;
                    }

                    if ($char === '/' && $next === '*') {
                        $inBlockComment = true;
                        $i++;

                        continue;
                    }
                }

                if ($escapeNext) {
                    $buffer .= $char;
                    $escapeNext = false;

                    continue;
                }

                if ($inSingleQuote) {
                    $buffer .= $char;
                    if ($char === '\\') {
                        $escapeNext = true;
                    } elseif ($char === "'") {
                        $inSingleQuote = false;
                    }

                    continue;
                }

                if ($inDoubleQuote) {
                    $buffer .= $char;
                    if ($char === '\\') {
                        $escapeNext = true;
                    } elseif ($char === '"') {
                        $inDoubleQuote = false;
                    }

                    continue;
                }

                if ($inBacktick) {
                    $buffer .= $char;
                    if ($char === '`') {
                        $inBacktick = false;
                    }

                    continue;
                }

                if ($char === "'") {
                    $inSingleQuote = true;
                    $buffer .= $char;

                    continue;
                }

                if ($char === '"') {
                    $inDoubleQuote = true;
                    $buffer .= $char;

                    continue;
                }

                if ($char === '`') {
                    $inBacktick = true;
                    $buffer .= $char;

                    continue;
                }

                if ($char === ';') {
                    $statement = trim($buffer);
                    $buffer = '';

                    if ($statement !== '') {
                        $this->assertSafeStatement($statement);
                        $execute($statement);
                        $statements++;
                    }

                    continue;
                }

                $buffer .= $char;
            }

            if ($onProgress !== null && $totalBytes !== null && $totalBytes > 0) {
                $onProgress(min(99, (int) floor(($bytesRead / $totalBytes) * 100)));
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $this->assertSafeStatement($tail);
            $execute($tail);
            $statements++;
        }

        if ($onProgress !== null) {
            $onProgress(100);
        }

        return [
            'statements' => $statements,
            'bytes_read' => $bytesRead,
        ];
    }

    private function assertSafeStatement(string $statement): void
    {
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $statement) === 1) {
                throw new RuntimeException('Câu lệnh SQL không được phép trong file import.');
            }
        }
    }
}
