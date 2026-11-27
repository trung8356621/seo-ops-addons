<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerDistributionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Reusable Ideas: distribution/Generate copy-never-consume + semantic advisory.
 */
final class IdeaManagerReusableCopyContractTest extends TestCase
{
    public function test_confirm_never_saves_or_mutates_source_workspace(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName()
        );
        self::assertStringContainsString('source_unchanged', $src);
        self::assertStringContainsString('source_consumed', $src);
        self::assertStringContainsString('COPY into destination workspace only', $src);
        self::assertStringContainsString('never deletes/hides/marks-used source rows', $src);
        // Only destination site_id is passed to workspaces->save.
        self::assertMatchesRegularExpression(
            '/\$this->workspaces->save\(\s*\$targetSiteId/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workspaces->save\(\s*\$sourceSiteId/',
            $src,
        );
        self::assertStringNotContainsString('workspaces->save($sourceSiteId', $src);
    }

    public function test_destination_same_name_is_idempotent_already_present_not_infinite_clone(): void
    {
        $existing = [
            AuditNoteDnaNormalizer::normalizeNoteItem([
                'source_type' => 'cluster',
                'cluster_ref' => 'cluster:canvas',
                'cluster_name_snapshot' => 'Vải Canvas',
                'target_dna_count' => 5,
                'dna' => [],
            ]),
        ];
        self::assertNotNull($existing[0]);
        self::assertNotNull(IdeaWorkspaceItemOps::findDuplicate($existing, 'cluster:other', 'vải canvas'));
        self::assertNull(IdeaWorkspaceItemOps::findDuplicate($existing, 'cluster:new', 'Balo học sinh'));

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName()
        );
        self::assertStringContainsString('STATUS_ALREADY', $src);
        self::assertStringContainsString('already_in_destination_workspace', $src);
        self::assertStringContainsString('not infinite clones', $src);
    }

    public function test_semantic_never_blocks_confirm_and_unknown_has_no_fake_score(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName()
        );
        self::assertStringContainsString("'can_confirm' => count(\$rows) > 0", $src);
        self::assertStringContainsString('NEVER block Confirm', $src);
        self::assertStringContainsString('semantic_client_unavailable', $src);
        self::assertStringContainsString("'warning' => false", $src);
        self::assertStringContainsString("'similarity_score' => null", $src);
        self::assertStringContainsString('withDestinationContentInventory', $src);
        self::assertStringContainsString('planning', $src);
        self::assertStringContainsString('article', $src);
        self::assertStringContainsString('TopicGroupRetrievalClient', $src);

        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('canConfirmNow()', $blade);
        self::assertStringContainsString('idea_manager_distribution_unknown', $blade);
        self::assertStringContainsString('source_unchanged', $blade);
        // Confirm disabled only for missing target/selection/inflight — not semantic.
        self::assertStringContainsString('this.targetSiteId > 0', $blade);
        self::assertStringContainsString('this.items.length > 0', $blade);
        self::assertStringContainsString('!this.confirming', $blade);
        self::assertStringNotContainsString('!it.exact_match', $this->canConfirmBody($blade));
        self::assertStringNotContainsString('similarity_score', $this->canConfirmBody($blade));
    }

    public function test_untick_only_clears_selection_not_warehouse_item(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('untickFromDistribution(ref)', $blade);
        self::assertMatchesRegularExpression(
            '/untickFromDistribution\(ref\)\s*\{[\s\S]*?delete this\.selected\[ref\];[\s\S]*?emitSelection\(\);/',
            $blade,
        );
        $untick = $this->extractJsMethod($blade, 'untickFromDistribution');
        self::assertStringNotContainsString('this.items =', $untick);
        self::assertStringNotContainsString('removeItem', $untick);
        self::assertStringNotContainsString('markDirty', $untick);
    }

    public function test_generate_not_wired_to_consume_idea_manager_warehouse(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        self::assertStringNotContainsString('generateIdeas', $page);
        self::assertStringNotContainsString(NewContentSuggestionPlannerService::class, $page);
        self::assertStringContainsString('confirmDistribution', $page);
        self::assertStringContainsString('Source unchanged', $page);
    }

    public function test_source_filter_and_active_month_do_not_delete_ideas(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        $setSource = $this->extractPhpMethod($page, 'setSourceSite');
        self::assertStringNotContainsString('refreshWorkspaceFromServer', $setSource);
        self::assertStringNotContainsString('note_items', $setSource);
        $setMonth = $this->extractPhpMethod($page, 'setActiveMonth');
        self::assertStringNotContainsString('workspaces->', $setMonth);
        self::assertStringNotContainsString('note_items', $setMonth);
        self::assertStringContainsString('do not touch workspaces or planning_month', $setMonth);
    }

    public function test_seo_audit_untouched_by_reusable_copy_change(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $blade);
        self::assertStringNotContainsString('source_unchanged', $blade);
        self::assertStringNotContainsString('withDestinationContentInventory', $blade);
    }

    public function test_lang_keys_for_unknown_and_reuse_hint(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        foreach (['idea_manager_distribution_unknown', 'idea_manager_distribution_contract_hint', 'idea_manager_distribution_need_valid'] as $key) {
            self::assertStringContainsString("'{$key}'", $en);
            self::assertStringContainsString("'{$key}'", $vi);
        }
        self::assertStringContainsString('never deletes or consumes', $en);
        self::assertStringContainsString('không xóa/tiêu thụ', $vi);
    }

    private function canConfirmBody(string $blade): string
    {
        return $this->extractJsMethod($blade, 'canConfirmNow');
    }

    private function extractJsMethod(string $blade, string $name): string
    {
        if (! preg_match('/'.$name.'\s*\([^)]*\)\s*\{/', $blade, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = (int) $m[0][1];
        $i = strpos($blade, '{', $start);
        if ($i === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($blade);
        for ($p = $i; $p < $len; $p++) {
            $ch = $blade[$p];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($blade, $start, $p - $start + 1);
                }
            }
        }

        return '';
    }

    private function extractPhpMethod(string $src, string $name): string
    {
        if (! preg_match('/function '.$name.'\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = (int) $m[0][1];
        $i = strpos($src, '{', $start);
        if ($i === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($src);
        for ($p = $i; $p < $len; $p++) {
            $ch = $src[$p];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $p - $start + 1);
                }
            }
        }

        return '';
    }
}
