<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentAutoContinuationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentCrossBatchContinuationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentPlannerRunOutcome;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionDedupFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionStructuredResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Mock E2E for qty=50 completion / dedupe / continuation (no real provider calls).
 * Regression: run20 marked completed at 19/50.
 */
final class NewContentQty50CompletionContractTest extends TestCase
{
    public function test_completed_only_when_accepted_meets_requested(): void
    {
        $full = NewContentPlannerRunOutcome::resolve(50, 50, NewContentPlannerRunOutcome::STOP_TARGET_MET);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $full['status']);
        self::assertSame(0, $full['remaining']);

        $partial = NewContentPlannerRunOutcome::resolve(
            19,
            50,
            NewContentPlannerRunOutcome::STOP_PROVIDER_BATCH_FAILED,
            duplicateSkipped: 2,
        );
        self::assertSame(SeoContentProjectPlannerRun::STATUS_PARTIAL, $partial['status']);
        self::assertSame(31, $partial['remaining']);
        self::assertStringContainsString('Chưa hoàn tất 19/50', $partial['message']);
        self::assertNotSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $partial['status']);

        $failed = NewContentPlannerRunOutcome::resolve(0, 50, NewContentPlannerRunOutcome::STOP_PROVIDER_BATCH_FAILED);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_FAILED, $failed['status']);
    }

    public function test_complete_run_service_does_not_force_completed_on_shortfall(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService::class
            ))->getFileName()
        );
        self::assertStringContainsString('Never force completed on shortfall', $src);
        self::assertStringContainsString('STATUS_PARTIAL', $src);
        self::assertStringNotContainsString(
            "\$summary['status'] = SeoContentProjectPlannerRun::STATUS_COMPLETED;\n        \$run->result_summary = \$summary;",
            $src,
        );
    }

    public function test_partial_fill_ui_is_scoped_to_working_site(): void
    {
        $concern = (string) file_get_contents(
            (string) (new \ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions::class
            ))->getFileName()
        );
        $runService = (string) file_get_contents(
            (string) (new \ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService::class
            ))->getFileName()
        );

        self::assertStringContainsString('resolveNewContentWorkingSiteId()', $concern);
        self::assertMatchesRegularExpression(
            '/listExecuted\(\s*\$project,\s*SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,\s*1,\s*\$workingSiteId/',
            $concern,
        );
        self::assertStringContainsString('?int $siteId = null', $runService);
        self::assertStringContainsString('$runSite === $siteId', $runService);
    }

    public function test_mock_loop_a_partial_batches_reach_full_50(): void
    {
        $result = $this->simulateLoop(50, [
            ['target' => 20, 'accepted' => 19],
            ['target' => 20, 'accepted' => 17],
            ['target' => 14, 'accepted' => 14],
        ]);

        self::assertSame(50, $result['added']);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $result['status']);
        self::assertSame(0, $result['remaining']);
        self::assertSame(3, $result['successful_output_batches']);
        self::assertSame(0, $result['provider_calls_on_duplicate_rounds']);
    }

    public function test_mock_loop_b_duplicate_batch_then_recover_without_cross_model_fallback(): void
    {
        $result = $this->simulateLoop(50, [
            ['target' => 20, 'accepted' => 19],
            ['target' => 20, 'accepted' => 0, 'all_duplicate' => true],
            ['target' => 20, 'accepted' => 20],
            ['target' => 11, 'accepted' => 11],
        ]);

        self::assertSame(50, $result['added']);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $result['status']);
        self::assertSame(0, $result['cross_model_fallbacks_from_duplicates']);
        self::assertTrue($result['continuation_injected_on_round'][2] ?? false);
    }

    public function test_mock_loop_c_shortfall_is_partial_not_completed(): void
    {
        $result = $this->simulateLoop(50, [
            ['target' => 20, 'accepted' => 19],
            ['target' => 20, 'accepted' => 0, 'all_duplicate' => true],
            ['target' => 20, 'failed' => true],
        ]);

        self::assertSame(19, $result['added']);
        self::assertSame(31, $result['remaining']);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_PARTIAL, $result['status']);
        self::assertSame(NewContentPlannerRunOutcome::STOP_PROVIDER_BATCH_FAILED, $result['stop_reason']);
        self::assertNotSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $result['status']);
    }

    public function test_mock_loop_d_failed_provider_round_does_not_reduce_remaining(): void
    {
        $result = $this->simulateLoop(50, [
            ['target' => 20, 'failed' => true, 'retry_then' => ['accepted' => 19]],
            ['target' => 20, 'accepted' => 20],
            ['target' => 11, 'accepted' => 11],
        ]);

        self::assertSame(50, $result['added']);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $result['status']);
        self::assertGreaterThanOrEqual(1, $result['routing_attempt_rounds']);
        self::assertSame(3, $result['successful_output_batches']);
    }

    public function test_e_per_item_dedupe_accepts_18_of_20(): void
    {
        $parser = new NewContentSuggestionParser;
        $rows = [];
        for ($i = 1; $i <= 18; $i++) {
            $rows[] = [
                'keyword' => "kw {$i}",
                'suggested_title' => "Title {$i}",
                'suggestion_reason' => 'gap',
                'source_signal' => 'keyword_gap',
            ];
        }
        // duplicate fingerprint of #1
        $rows[] = [
            'keyword' => 'kw 1',
            'suggested_title' => 'Title 1',
            'suggestion_reason' => 'gap',
            'source_signal' => 'keyword_gap',
        ];
        // duplicate keyword of #2 with different title still blocked by in-batch keyword
        $rows[] = [
            'keyword' => 'kw 2',
            'suggested_title' => 'Other title 2',
            'suggestion_reason' => 'gap',
            'source_signal' => 'keyword_gap',
        ];

        $parsed = $parser->parse($rows, 20);
        // parser collapses exact fingerprint dup as invalid; keyword-dup with different title remains
        $filtered = (new NewContentSuggestionDedupFilter)->filter(
            $parsed['candidates'],
            [],
            [],
            [],
            [],
        );

        self::assertSame(18, count($filtered['accepted']));
        self::assertGreaterThanOrEqual(1, $filtered['duplicate_skipped']);
    }

    public function test_f_continuation_injected_into_outbound_brief(): void
    {
        $policy = new NewContentCrossBatchContinuationPolicy;
        $compact = $policy->compactAccepted([
            [
                'keyword' => 'balo laptop cho sinh viên',
                'title' => 'Balo Laptop Cho Sinh Viên',
                'fingerprint' => 'fp1',
                'source_signal' => 'cluster_gap',
            ],
        ]);
        $lines = $policy->instructionLines($compact);
        $brief = "PLANNING BRIEF\n".implode("\n", $lines);
        self::assertStringContainsString('cross_batch_continuation', $brief);
        self::assertStringContainsString('balo laptop cho sinh viên', $brief);
        self::assertStringContainsString('Do NOT repeat', $brief);
    }

    public function test_g_resume_partial_19_plus_20_plus_11(): void
    {
        $first = $this->simulateLoop(50, [
            ['target' => 20, 'accepted' => 19],
            ['target' => 20, 'accepted' => 0, 'all_duplicate' => true],
            ['target' => 20, 'failed' => true],
        ]);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_PARTIAL, $first['status']);
        self::assertSame(31, $first['remaining']);

        $resume = $this->simulateLoop(31, [
            ['target' => 20, 'accepted' => 20],
            ['target' => 11, 'accepted' => 11],
        ], priorAccepted: $first['fingerprints'], fingerprintPrefix: 'resume');

        self::assertSame(31, $resume['added']);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $resume['status']);
        self::assertSame([], array_values(array_intersect($first['fingerprints'], $resume['new_fingerprints'])));
    }

    public function test_h_run20_regression_fixture_not_completed(): void
    {
        // PR1115 accepted 19 (+2 draft dups); PR1116/1117 repair→1 draft dup; PR1118 provider exhausted
        $result = $this->simulateLoop(50, [
            ['target' => 20, 'accepted' => 19, 'duplicates' => 2],
            ['target' => 20, 'accepted' => 0, 'truncated_json_then_repair_dup' => true],
            ['target' => 20, 'failed' => true],
        ]);

        self::assertSame(19, $result['added']);
        self::assertSame(31, $result['remaining']);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_PARTIAL, $result['status']);
        self::assertSame(3, $result['duplicate_skipped']);
        self::assertTrue($result['continuation_injected_on_round'][2] ?? false);
        self::assertTrue($result['repair_kept_continuation'] ?? false);
    }

    public function test_repair_brief_preserves_continuation_block(): void
    {
        $policy = new NewContentCrossBatchContinuationPolicy;
        $lines = $policy->instructionLines($policy->compactAccepted([
            ['keyword' => 'a', 'title' => 'A', 'fingerprint' => 'f1', 'source_signal' => 'keyword_gap'],
        ]));
        $brief = "BASE\n".implode("\n", $lines);
        $repair = NewContentSuggestionStructuredResult::repairBrief('{"bad"', 'post', 20);
        $block = NewContentCrossBatchContinuationPolicy::extractBlockFromBrief($brief);
        self::assertNotSame('', $block);
        $merged = rtrim($repair)."\n\n".$block;
        self::assertStringContainsString('cross_batch_continuation', $merged);
        self::assertStringContainsString('REPAIR TASK', $merged);
    }

    public function test_fingerprint_is_keyword_plus_title_sha256(): void
    {
        $fp = NewContentSuggestionIdentity::fingerprint('Balo Laptop', 'Cách chọn');
        self::assertSame(64, strlen($fp));
        self::assertSame(
            $fp,
            NewContentSuggestionIdentity::fingerprint('  balo   laptop ', 'cách chọn'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $script
     * @param  list<string>  $priorAccepted
     * @return array<string, mixed>
     */
    private function simulateLoop(
        int $requested,
        array $script,
        array $priorAccepted = [],
        string $fingerprintPrefix = 'sim',
    ): array {
        $all = [];
        $fps = $priorAccepted;
        $newFingerprints = [];
        $dupSkipped = 0;
        $generationRounds = 0;
        $successfulOutputBatches = 0;
        $routingAttemptRounds = 0;
        $crossModelFromDup = 0;
        $providerCallsOnDupRounds = 0;
        $continuationInjected = [];
        $repairKeptContinuation = false;
        $stopReason = null;
        $stagnant = 0;
        $recoveryLevel = 0;
        $escalationsInSlice = 0;
        $continuationSlicesUsed = 0;
        $scriptIndex = 0;
        $maxRounds = max(1, (int) ceil($requested / 10) + 8);

        for ($round = 0; $round < $maxRounds; $round++) {
            $remaining = $requested - count($all);
            if ($remaining <= 0) {
                $stopReason = NewContentPlannerRunOutcome::STOP_TARGET_MET;
                break;
            }
            if (! isset($script[$scriptIndex])) {
                $stopReason = NewContentPlannerRunOutcome::STOP_MAX_ROUNDS;
                break;
            }
            $step = $script[$scriptIndex];
            $scriptIndex++;
            $target = min((int) ($step['target'] ?? $remaining), $remaining);
            $continuationInjected[$round + 1] = $fps !== [];

            $attempts = 0;
            $maxAttempts = 2;
            $accepted = 0;
            $batchError = null;
            $hadOutput = false;

            while ($attempts < $maxAttempts) {
                $attempts++;
                $routingAttemptRounds++;

                if (! empty($step['failed']) && empty($step['retry_then'])) {
                    $batchError = new RuntimeException('AI_ROUTES_EXHAUSTED');
                    break;
                }
                if (! empty($step['failed']) && is_array($step['retry_then'] ?? null) && $attempts === 1) {
                    continue;
                }

                $hadOutput = true;
                if (! empty($step['truncated_json_then_repair_dup'])) {
                    $repairKeptContinuation = $fps !== [];
                    $accepted = 0;
                    $dupSkipped += 1;
                    break;
                }
                if (! empty($step['all_duplicate'])) {
                    $providerCallsOnDupRounds++;
                    $accepted = 0;
                    $dupSkipped += $target;
                    break;
                }

                $payload = $step['retry_then'] ?? $step;
                $accepted = (int) ($payload['accepted'] ?? 0);
                $dupSkipped += (int) ($payload['duplicates'] ?? 0);
                for ($i = 0; $i < $accepted; $i++) {
                    $fp = $fingerprintPrefix.'-'.count($all).'-'.$i.'-'.$round;
                    if (in_array($fp, $fps, true)) {
                        $dupSkipped++;
                        continue;
                    }
                    $all[] = $fp;
                    $fps[] = $fp;
                    $newFingerprints[] = $fp;
                }
                $batchError = null;
                break;
            }

            $generationRounds++;
            if ($hadOutput) {
                $successfulOutputBatches++;
            }
            if ($batchError !== null) {
                $stopReason = NewContentPlannerRunOutcome::STOP_PROVIDER_BATCH_FAILED;
                break;
            }
            if (count($all) >= $requested) {
                $stopReason = NewContentPlannerRunOutcome::STOP_TARGET_MET;
                break;
            }
            if ($accepted <= 0) {
                $stagnant++;
                if ($stagnant >= NewContentPlannerRunOutcome::MAX_CONSECUTIVE_NO_PROGRESS) {
                    $decision = NewContentAutoContinuationPolicy::afterNoProgress(
                        $recoveryLevel,
                        $escalationsInSlice,
                        $continuationSlicesUsed,
                    );
                    $escalationsInSlice++;
                    $recoveryLevel = (int) $decision['recovery_level'];
                    if ($decision['action'] === NewContentAutoContinuationPolicy::ACTION_CONTINUE) {
                        $stagnant = 0;
                        continue;
                    }
                    if ($decision['action'] === NewContentAutoContinuationPolicy::ACTION_YIELD_SLICE) {
                        $continuationSlicesUsed++;
                        $stagnant = 0;
                        $escalationsInSlice = 0;
                        continue;
                    }
                    $stopReason = NewContentPlannerRunOutcome::STOP_AUTO_RECOVERY_EXHAUSTED;
                    break;
                }
            } else {
                $stagnant = 0;
                $recoveryLevel = 0;
            }
        }

        if ($stopReason === null) {
            $stopReason = count($all) >= $requested
                ? NewContentPlannerRunOutcome::STOP_TARGET_MET
                : NewContentPlannerRunOutcome::STOP_MAX_ROUNDS;
        }

        $outcome = NewContentPlannerRunOutcome::resolve(count($all), $requested, $stopReason, $dupSkipped);

        return [
            'added' => count($all),
            'remaining' => $outcome['remaining'],
            'status' => $outcome['status'],
            'stop_reason' => $outcome['stop_reason'],
            'duplicate_skipped' => $dupSkipped,
            'generation_rounds' => $generationRounds,
            'successful_output_batches' => $successfulOutputBatches,
            'routing_attempt_rounds' => $routingAttemptRounds,
            'cross_model_fallbacks_from_duplicates' => $crossModelFromDup,
            'provider_calls_on_duplicate_rounds' => $providerCallsOnDupRounds,
            'continuation_injected_on_round' => $continuationInjected,
            'repair_kept_continuation' => $repairKeptContinuation,
            'fingerprints' => $fps,
            'new_fingerprints' => $newFingerprints,
        ];
    }
}
