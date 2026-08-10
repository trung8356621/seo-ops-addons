<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Support;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Carbon\Carbon;

/**
 * Concurrency guard cho article.content.update.
 * Primary: expected_document_version (canonical).
 * Compat: expected_updated_at / expected_content_hash — must not veto when document_version matches.
 */
final class ArticleContentConflictGuard
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function assertCompatible(SeoArticle $article, array $input): ?ActionResult
    {
        $expectedVersion = $input['expected_document_version'] ?? null;
        $versionMatched = false;
        if ($expectedVersion !== null && $expectedVersion !== '') {
            $actualVersion = max(1, (int) ($article->document_version ?? 1));
            if ((int) $expectedVersion !== $actualVersion) {
                return ActionResult::failure(
                    'conflict_document_version',
                    'Article document_version mismatch; refusing silent overwrite.',
                    error: [
                        'expected_document_version' => (int) $expectedVersion,
                        'actual_document_version' => $actualVersion,
                    ],
                );
            }
            // Canonical lock satisfied — legacy content_hash must not veto JSON/session writers.
            $versionMatched = true;
        }

        $expectedUpdatedAt = $input['expected_updated_at'] ?? null;
        $expectedHash = $input['expected_content_hash'] ?? null;

        if ($expectedUpdatedAt === null && ($expectedHash === null || $expectedHash === '') && ($expectedVersion === null || $expectedVersion === '')) {
            return null;
        }

        if ($expectedUpdatedAt !== null && $expectedUpdatedAt !== '') {
            $actual = $article->updated_at;
            if ($actual === null) {
                return ActionResult::failure(
                    'conflict_updated_at',
                    'Article updated_at missing; cannot verify expected_updated_at.',
                    error: ['expected_updated_at' => (string) $expectedUpdatedAt],
                );
            }

            try {
                $expected = Carbon::parse((string) $expectedUpdatedAt);
            } catch (\Throwable) {
                return ActionResult::failure(
                    'invalid_expected_updated_at',
                    'expected_updated_at is not a valid datetime.',
                );
            }

            if ($actual->getTimestamp() !== $expected->getTimestamp()) {
                // updated_at lệch nhưng body hash vẫn khớp → meta/touch lệch, không phải writer khác đổi nội dung.
                $hashMatches = is_string($expectedHash)
                    && $expectedHash !== ''
                    && hash_equals($this->contentHash((string) ($article->body ?? '')), $expectedHash);

                // Version already matched → updated_at drift alone is not a body conflict.
                if (! $hashMatches && ! $versionMatched) {
                    return ActionResult::failure(
                        'conflict_updated_at',
                        'Article was modified by another writer (updated_at mismatch).',
                        error: [
                            'expected_updated_at' => $expected->toIso8601String(),
                            'actual_updated_at' => $actual->toIso8601String(),
                        ],
                    );
                }
            }
        }

        if (! $versionMatched && is_string($expectedHash) && $expectedHash !== '') {
            $actualHash = $this->contentHash((string) ($article->body ?? ''));
            if (! hash_equals($expectedHash, $actualHash)) {
                return ActionResult::failure(
                    'conflict_content_hash',
                    'Article body hash mismatch; refusing silent overwrite.',
                    error: [
                        'expected_content_hash' => $expectedHash,
                        'actual_content_hash' => $actualHash,
                    ],
                );
            }
        }

        return null;
    }

    public function contentHash(string $body): string
    {
        return hash('sha256', trim($body));
    }
}
