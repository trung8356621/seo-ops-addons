<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Enums;

/**
 * Canonical Article business classification.
 *
 * Platform-native types (WP CPT, taxonomies, Shopify, …) MUST be mapped to one of these
 * before Article core consumes them. No fourth value is allowed.
 */
enum ContentType: string
{
    case Post = 'post';
    case Page = 'page';
    case Product = 'product';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $resolved = self::tryFromString($value);
        if ($resolved === null) {
            throw new \InvalidArgumentException(
                'Invalid content_type "'.$value.'". Allowed: '.implode('|', self::values()),
            );
        }

        return $resolved;
    }

    public function isPost(): bool
    {
        return $this === self::Post;
    }

    public function isPage(): bool
    {
        return $this === self::Page;
    }

    public function isProduct(): bool
    {
        return $this === self::Product;
    }
}
