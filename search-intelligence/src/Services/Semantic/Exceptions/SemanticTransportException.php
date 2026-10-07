<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions;

/** Timeout or non-2xx HTTP response from semantic API. */
final class SemanticTransportException extends SemanticHttpException
{
    public static function timeout(string $detail): self
    {
        return new self('Semantic request timed out: '.$detail, 'semantic_timeout');
    }

    public static function httpError(int $status, string $body): self
    {
        $snippet = mb_substr(trim($body), 0, 400);

        return new self(
            sprintf('Semantic HTTP %d%s', $status, $snippet !== '' ? ': '.$snippet : ''),
            'semantic_http_'.$status,
            $status,
        );
    }
}
