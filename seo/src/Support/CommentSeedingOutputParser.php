<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use InvalidArgumentException;

/**
 * Parse seeding comments: lines of "Name | Email | Content".
 * Split max 3 parts so comment body may contain "|".
 * Invalid lines are skipped; empty result throws.
 */
final class CommentSeedingOutputParser
{
    public const MAX_COMMENTS = 10;

    /**
     * @return list<array{name: string, email: string, content: string}>
     */
    public function parse(string $raw): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $comments = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) !== 3) {
                continue;
            }

            [$name, $email, $content] = $parts;
            if ($name === '' || $content === '') {
                continue;
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $comments[] = [
                'name' => $name,
                'email' => $email,
                'content' => $content,
            ];

            if (count($comments) >= self::MAX_COMMENTS) {
                break;
            }
        }

        if ($comments === []) {
            throw new InvalidArgumentException(
                'Comment output has no valid lines in format: Name | Email | Content.',
            );
        }

        return $comments;
    }
}
