<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\ProjectRoot;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Engine attempt accounting: real executions must be exactly 1 → 2 → 3.
 * forceRetry must not bump Pending membership / transient re-queue claims.
 */
final class ContentProjectAttemptAccountingContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_article_runner_passes_force_retry_false_for_engine_claims(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectArticleRunner.php'
        );

        self::assertStringContainsString('forceRetry: false', $src);
        self::assertStringNotContainsString('forceRetry: true', $src);
    }

    public function test_manual_retry_task_keeps_force_retry_true(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php'
        );

        self::assertMatchesRegularExpression(
            '/function retryTask\([\s\S]*?forceRetry:\s*true/u',
            $src,
        );
    }

    public function test_claim_attempt_bump_skips_pending_even_when_force_retry(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectRunItemService.php'
        );

        self::assertStringContainsString('Pending (lazy membership / transient re-queue)', $src);
        self::assertStringContainsString('statusIsRetryableTerminal', $src);
        // forceRetry alone must not appear in the attempt++ condition.
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*\$forceRetry\s*\|\|/u',
            $src,
        );
    }

    public function test_lazy_membership_seeds_attempt_one(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectRunItemService.php'
        );

        self::assertStringContainsString("'attempt' => 1", $src);
    }

    public function test_transient_scheduler_advances_attempt_for_next_execution(): void
    {
        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        $runner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectArticleRunner.php'
        );

        self::assertStringContainsString("'attempt' => \$nextAttempt", $engine);
        self::assertStringContainsString("'next_attempt' => \$nextAttempt", $runner);
        self::assertSame(3, ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS);
    }

    public function test_simulated_pending_claim_sequence_is_one_two_three(): void
    {
        // Pure accounting model mirroring claim (Pending no bump) + transient schedule bump.
        $attempt = 1; // lazy membership
        $executions = [];

        for ($i = 0; $i < ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS; $i++) {
            $status = SeoProjectRunItemStatus::Pending->value;
            $forceRetry = false;
            // claimForExecution bump rules (engine path)
            if (in_array($status, [
                SeoProjectRunItemStatus::Failed->value,
                SeoProjectRunItemStatus::Processing->value,
                SeoProjectRunItemStatus::Success->value,
                SeoProjectRunItemStatus::Skipped->value,
            ], true)) {
                $attempt++;
            } elseif ($forceRetry) {
                // Must not bump Pending.
                self::fail('Pending must not bump via forceRetry');
            }
            $executions[] = $attempt;

            // Transient defer: schedule next attempt
            if ($i < ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS - 1) {
                $attempt = $attempt + 1;
            }
        }

        self::assertSame([1, 2, 3], $executions);
    }

    public function test_force_retry_true_on_pending_would_have_been_the_bug(): void
    {
        // Document the old double-bump path: membership 1 + forceRetry claim + schedule.
        $attempt = 1;
        $forceRetry = true;
        $status = SeoProjectRunItemStatus::Pending->value;

        // OLD buggy rule: forceRetry || terminal → bump
        if (
            $forceRetry
            || in_array($status, [
                SeoProjectRunItemStatus::Failed->value,
                SeoProjectRunItemStatus::Processing->value,
                SeoProjectRunItemStatus::Success->value,
                SeoProjectRunItemStatus::Skipped->value,
            ], true)
        ) {
            $attempt++;
        }
        self::assertSame(2, $attempt, 'Old engine forceRetry on Pending made first execution attempt 2');

        // NEW rule: Pending never bumps on claim
        $attemptFixed = 1;
        if (in_array($status, [
            SeoProjectRunItemStatus::Failed->value,
            SeoProjectRunItemStatus::Processing->value,
            SeoProjectRunItemStatus::Success->value,
            SeoProjectRunItemStatus::Skipped->value,
        ], true)) {
            $attemptFixed++;
        }
        self::assertSame(1, $attemptFixed);
    }

    public function test_claim_for_execution_signature_still_accepts_force_retry(): void
    {
        $method = new ReflectionMethod(SeoProjectRunItemService::class, 'claimForExecution');
        $params = $method->getParameters();
        self::assertSame('forceRetry', $params[3]->getName());
        self::assertFalse($params[3]->getDefaultValue());
    }
}
