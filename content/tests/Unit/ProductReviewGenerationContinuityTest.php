<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewContentFingerprint;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewCreationPolicy;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewLocalBatchCreator;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewPendingRepository;
use Omnichannel\Addons\Commerce\Services\ProductReview\WordPressProductReviewService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Generation continuity + unique counter / remote dedup invariants.
 */
final class ProductReviewGenerationContinuityTest extends TestCase
{
    public function test_a_widget_lock_cli_unlocks_one_widget_only(): void
    {
        $cli = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/scripts/widget-lock.cjs',
        );
        self::assertStringContainsString('widget.locked = false', $cli);
        self::assertStringContainsString('No unlock-all', $cli);
        self::assertStringContainsString('there is no unlock-all', $cli);
    }

    public function test_b_batch_creator_continues_slot_and_skips_existing_hashes(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ProductReviewLocalBatchCreator::class))->getFileName(),
        );
        self::assertStringContainsString('nextGenerationSlot', $src);
        self::assertStringContainsString('existingContentHashes', $src);
        self::assertStringContainsString('ProductReviewContentFingerprint::hash', $src);
        self::assertStringContainsString('while (count($createdIds) < $count', $src);
        self::assertStringNotContainsString('for ($i = 0; $i < $count; $i++)', $src);
    }

    public function test_c_fingerprint_matches_wp_exists_algorithm(): void
    {
        $hash = ProductReviewContentFingerprint::hash('Lan Anh', 'Mình mua X, chất lượng ổn, giao hàng nhanh.', 5);
        $remote = ProductReviewContentFingerprint::fromRemoteItem([
            'author' => 'Lan Anh',
            'content' => 'Mình mua X, chất lượng ổn, giao hàng nhanh.',
            'rating' => 5,
        ]);
        self::assertSame($hash, $remote);
        self::assertNotSame(
            $hash,
            ProductReviewContentFingerprint::hash('Minh Tuấn', 'Mình mua X, chất lượng ổn, giao hàng nhanh.', 5),
        );
    }

    public function test_d_e_local_counts_use_unique_hashes_not_raw_rows(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ProductReviewCreationPolicy::class))->getFileName(),
        );
        self::assertStringContainsString('distinct()', $src);
        self::assertStringContainsString('count(\'content_hash\')', $src);
        self::assertStringContainsString('unique_fulfilled_count', $src);
        self::assertStringContainsString('cancelDuplicateReviewedRows', $src);
        self::assertStringContainsString('UNIQUE AI reviews', $src);
        self::assertStringContainsString('wordpress_not_synced', $src);
        self::assertSame(3, ProductReviewCreationPolicy::DEFAULT_TARGET_COUNT);
    }

    public function test_f_g_remote_created_vs_duplicate_cancelled(): void
    {
        $create = $this->methodSource(WordPressProductReviewService::class, 'create');
        self::assertStringContainsString('DUPLICATE_CANCELLED', $create);
        self::assertStringContainsString('markCancelledDuplicate', $create);
        self::assertStringContainsString('unique_fulfilled', $create);
        self::assertStringContainsString('DEDUPLICATED', $create);
        self::assertStringContainsString('CREATED', $create);

        $seq = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleWordPressBusinessSequence::class))->getFileName(),
        );
        self::assertStringContainsString('duplicate_cancelled', $seq);
        self::assertStringContainsString('DUPLICATE_CANCELLED', $seq);
        self::assertStringContainsString('fresh: true', $seq);
        self::assertStringContainsString('ProductReviewGenerationHistoryRecorder', $seq);
    }

    public function test_h_reviewed_invariant_helpers_exist(): void
    {
        self::assertTrue(method_exists(ProductReviewPendingRepository::class, 'markCancelledDuplicate'));
        self::assertTrue(method_exists(ProductReviewPendingRepository::class, 'findCanonicalByContentHash'));
        self::assertTrue(ArticleProductReviewStatus::Reviewed->isReviewed());
        self::assertSame('cancelled', ArticleProductReviewStatus::Cancelled->value);
    }

    public function test_i_bounded_retry_constant_present(): void
    {
        $ref = new ReflectionClass(ProductReviewLocalBatchCreator::class);
        self::assertTrue($ref->hasConstant('MAX_ATTEMPTS_MULTIPLIER'));
        self::assertSame(5, $ref->getConstant('MAX_ATTEMPTS_MULTIPLIER'));
    }

    public function test_j_template_slot_helpers_are_public_for_continuity(): void
    {
        $creator = (new ReflectionClass(ProductReviewLocalBatchCreator::class))
            ->newInstanceWithoutConstructor();
        self::assertSame('Lan Anh', $creator->authorName(0));
        self::assertSame('Minh Tuấn', $creator->authorName(1));
        self::assertNotSame($creator->contentFor('SP', 0), $creator->contentFor('SP', 1));
        self::assertSame($creator->authorName(0), $creator->authorName(10));
    }

    /**
     * @param  class-string  $class
     */
    private function methodSource(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $lines = file((string) $ref->getFileName());
        self::assertNotFalse($lines);

        return implode('', array_slice(
            $lines,
            ((int) $ref->getStartLine()) - 1,
            ((int) $ref->getEndLine()) - ((int) $ref->getStartLine()) + 1,
        ));
    }
}
