<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions;

/** Response body is not usable JSON / missing required contract fields. */
final class SemanticInvalidResponseException extends SemanticHttpException
{
    public static function invalidJson(string $detail): self
    {
        return new self('Semantic response is not valid JSON: '.$detail, 'semantic_invalid_json');
    }

    public static function contract(string $detail): self
    {
        return new self('Semantic response contract mismatch: '.$detail, 'semantic_contract_mismatch');
    }
}
