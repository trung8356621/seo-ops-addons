<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Exceptions;

final class FaqManualExtractException extends \InvalidArgumentException
{
    /**
     * @param  array<string, mixed>  $debug
     * @param  array<string, mixed>|null  $faqSectionHeading
     */
    public function __construct(
        string $message,
        public readonly array $debug = [],
        public readonly ?array $faqSectionHeading = null,
    ) {
        parent::__construct($message);
    }
}
