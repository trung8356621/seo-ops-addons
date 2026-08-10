<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use RuntimeException;

final class ArticleEditorSessionException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(string $errorCode, string $message, array $context = [], int $httpStatus = 409): self
    {
        return new self($errorCode, $message, $context, $httpStatus);
    }

    public static function locked(array $lockPayload): self
    {
        return self::make(
            ArticleEditorSessionErrorCode::LOCKED,
            'Article is locked by another editor session.',
            ['lock' => $lockPayload],
            423,
        );
    }
}
