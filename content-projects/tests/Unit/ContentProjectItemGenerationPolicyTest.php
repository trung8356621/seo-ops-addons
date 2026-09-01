<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\AutomaticVarietyToneResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentLengthPresetResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentProjectItemGenerationPolicy;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentProjectItemGenerationPolicyApplier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentProjectItemGenerationPolicyResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemContentLengthMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationRoutingPreference;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemModelOverrideMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemTitleProtection;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure unit coverage for the Content Project item generation policy.
 * No DB: tasks are raw attribute bags, prompt settings come from withDefaults().
 */
final class ContentProjectItemGenerationPolicyTest extends TestCase
{
    /** Tone the legacy site-level setting would have injected — must never win. */
    private const LEGACY_SITE_TONE = 'Friendly (legacy site tone)';

    private SeoPromptSettingsService $promptSettings;

    private ContentProjectItemGenerationPolicyResolver $resolver;

    private ContentProjectItemGenerationPolicyApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->promptSettings = SeoPromptSettingsService::withDefaults();
        $this->resolver = ContentProjectItemGenerationPolicyResolver::withPromptSettings($this->promptSettings);
        $this->applier = new ContentProjectItemGenerationPolicyApplier($this->resolver);
    }

    // 1. No tone override → Automatic variety, never the legacy site tone.

    public function test_item_without_tone_override_uses_automatic_variety_not_site_tone(): void
    {
        $task = $this->task([
            'id' => 101,
            'keyword' => 'balo da nam',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
        ]);

        $policy = $this->resolver->resolve($task);

        self::assertTrue($policy->toneIsAutomaticVariety);
        self::assertFalse($policy->hasToneOverride());
        self::assertNotSame(self::LEGACY_SITE_TONE, $policy->tone);
        self::assertContains($policy->tone, $this->promptSettings->getToneOfVoiceOptions());

        $expected = (new AutomaticVarietyToneResolver($this->promptSettings))
            ->resolve(101, 'balo da nam', SeoProjectTask::POST_TYPE_ARTICLE, null);
        self::assertSame($expected, $policy->tone);

        // Legacy site tone already on the context is replaced, not inherited.
        $applied = $this->applier->apply(
            $this->context(['tone' => self::LEGACY_SITE_TONE]),
            $task,
        );
        self::assertSame($policy->tone, $applied->variables['tone']);
        self::assertSame('automatic_variety', $applied->variables['_item_tone_mode']);
    }

    // 2. Automatic variety spreads different items across different tones.

    public function test_automatic_variety_can_resolve_different_tones_for_different_items(): void
    {
        $tones = [];

        foreach (range(1, 24) as $id) {
            $tones[] = $this->resolver->resolve($this->task([
                'id' => $id,
                'keyword' => 'từ khóa '.$id,
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            ]))->tone;
        }

        self::assertGreaterThan(
            1,
            count(array_unique($tones)),
            'Automatic variety must not collapse every item onto one tone.',
        );
    }

    // 3. Same item stays on its stickied tone across reruns.

    public function test_resolved_tone_is_sticky_for_the_same_item_on_rerun(): void
    {
        $first = $this->resolver->resolve($this->task([
            'id' => 555,
            'keyword' => 'giày chạy bộ',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
        ]));

        self::assertTrue($first->shouldPersistResolvedTone());
        self::assertFalse($first->toneWasSticky);

        $rerun = $this->resolver->resolve($this->task([
            'id' => 555,
            'keyword' => 'giày chạy bộ',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'resolved_tone' => $first->tone,
        ]));

        self::assertSame($first->tone, $rerun->tone);
        self::assertTrue($rerun->toneIsAutomaticVariety);
        self::assertTrue($rerun->toneWasSticky);
        self::assertFalse($rerun->shouldPersistResolvedTone());
        self::assertSame(0, $rerun->countOverrides(), 'Sticky automatic tone is not an operator override.');
    }

    // 4. Explicit tone override wins outright.

    public function test_specific_tone_override_wins(): void
    {
        $policy = $this->resolver->resolve($this->task([
            'id' => 7,
            'keyword' => 'máy lọc nước',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'tone_override' => 'Expert',
            'resolved_tone' => 'Chuyên nghiệp',
        ]));

        self::assertSame('Expert', $policy->tone);
        self::assertTrue($policy->hasToneOverride());
        self::assertFalse($policy->toneIsAutomaticVariety);
        self::assertFalse($policy->shouldPersistResolvedTone());
        self::assertSame(1, $policy->countOverrides());

        $applied = $this->applier->apply($this->context(['tone' => self::LEGACY_SITE_TONE]), $this->task([
            'id' => 7,
            'tone_override' => 'Expert',
        ]));
        self::assertSame('Expert', $applied->variables['tone']);
        self::assertSame('override', $applied->variables['_item_tone_mode']);
    }

    // 5. No length preset → inherit the domain target (nothing stamped).

    public function test_null_content_length_inherits_domain_target(): void
    {
        $task = $this->task([
            'id' => 12,
            'keyword' => 'bàn phím cơ',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
        ]);

        $policy = $this->resolver->resolve($task);

        self::assertNull($policy->contentLengthMode);
        self::assertNull($policy->contentLengthTargetWords);
        self::assertTrue($policy->inheritsContentLength());

        $applied = $this->applier->apply($this->context(['article_length' => '2000']), $task);
        self::assertSame('2000', $applied->variables['article_length'], 'Inherited length must be left untouched.');
        self::assertArrayNotHasKey('_item_content_length_mode', $applied->variables);
    }

    // 6. Long / custom presets win over the inherited target.

    public function test_long_and_custom_content_length_override_the_inherited_target(): void
    {
        $base = $this->promptSettings->resolveArticleLengthTarget(SeoProjectTask::POST_TYPE_ARTICLE);

        $long = $this->resolver->resolve($this->task([
            'id' => 21,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'content_length_override' => ItemContentLengthMode::Long->value,
        ]));
        self::assertSame(ItemContentLengthMode::Long, $long->contentLengthMode);
        self::assertSame((int) round($base * ContentLengthPresetResolver::MULTIPLIER_LONG), $long->contentLengthTargetWords);
        self::assertFalse($long->inheritsContentLength());

        $short = $this->resolver->resolve($this->task([
            'id' => 22,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'content_length_override' => ItemContentLengthMode::Short->value,
        ]));
        self::assertSame((int) round($base * ContentLengthPresetResolver::MULTIPLIER_SHORT), $short->contentLengthTargetWords);

        $customTask = $this->task([
            'id' => 23,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'content_length_override' => ItemContentLengthMode::Custom->value,
            'content_length_target_words' => 850,
        ]);
        $custom = $this->resolver->resolve($customTask);
        self::assertSame(850, $custom->contentLengthTargetWords);

        $applied = $this->applier->apply($this->context(['article_length' => (string) $base]), $customTask);
        self::assertSame('850', $applied->variables['article_length']);
        self::assertSame('custom', $applied->variables['_item_content_length_mode']);
        self::assertSame('850', $applied->variables['_item_content_length_target_words']);
    }

    // 7. Generation mode override is read and stamped.

    public function test_generation_mode_override_is_resolved_and_stamped(): void
    {
        $task = $this->task([
            'id' => 31,
            'generation_mode_override' => ItemGenerationMode::BestQuality->value,
        ]);

        $policy = $this->resolver->resolve($task);
        self::assertSame(ItemGenerationMode::BestQuality, $policy->generationMode);
        self::assertSame(1, $policy->countOverrides());

        $applied = $this->applier->apply($this->context(), $task);
        self::assertSame('best_quality', $applied->variables['_item_generation_mode']);

        self::assertNull($this->resolver->resolve($this->task(['id' => 32]))->generationMode);
        self::assertNull(ItemGenerationMode::tryFromMixed('nonsense'));
        self::assertSame(ItemGenerationMode::FastEconomy, ItemGenerationMode::tryFromMixed(' Fast_Economy '));
    }

    // 8. Candidate ordering per generation mode.

    public function test_routing_preference_orders_candidates_for_mode(): void
    {
        $candidates = ['cheap', 'mid', 'flagship'];

        self::assertSame($candidates, ItemGenerationRoutingPreference::orderCandidates($candidates, null));
        self::assertSame($candidates, ItemGenerationRoutingPreference::orderCandidates(
            $candidates,
            ItemGenerationMode::FastEconomy,
        ));
        self::assertSame(['flagship', 'mid', 'cheap'], ItemGenerationRoutingPreference::orderCandidates(
            $candidates,
            ItemGenerationMode::BestQuality,
        ));

        $paid = $this->modelCandidate(1, false);
        $free = $this->modelCandidate(2, true);

        self::assertSame([$free, $paid], ItemGenerationRoutingPreference::orderCandidates(
            [$paid, $free],
            ItemGenerationMode::FastEconomy,
        ));
        self::assertSame([$free, $paid], ItemGenerationRoutingPreference::orderCandidates(
            [$paid, $free],
            ItemGenerationMode::BestQuality,
        ));
    }

    // 9. Preferred model is floated to the front without dropping anyone.

    public function test_routing_preference_prepends_preferred_model(): void
    {
        $candidates = [
            $this->modelCandidate(11, false),
            $this->modelCandidate(12, false),
            $this->modelCandidate(13, false),
        ];
        $idOf = static fn (object $candidate): int => $candidate->id;

        $ordered = ItemGenerationRoutingPreference::prependPreferred($candidates, 13, $idOf);
        self::assertSame([13, 11, 12], array_map($idOf, $ordered));

        self::assertSame(
            [11, 12, 13],
            array_map($idOf, ItemGenerationRoutingPreference::prependPreferred($candidates, null, $idOf)),
        );
        self::assertSame(
            [11, 12, 13],
            array_map($idOf, ItemGenerationRoutingPreference::prependPreferred($candidates, 999, $idOf)),
            'An unknown preferred id must not drop candidates.',
        );
    }

    // 10. Model override id + binding strength.

    public function test_model_override_defaults_to_preferred_and_supports_required(): void
    {
        $preferred = $this->resolver->resolve($this->task([
            'id' => 41,
            'model_override_id' => 77,
        ]));
        self::assertTrue($preferred->hasModelOverride());
        self::assertSame(77, $preferred->modelOverrideId);
        self::assertSame(ItemModelOverrideMode::Preferred, $preferred->modelOverrideMode);
        self::assertFalse($preferred->requiresModelOverride());

        $requiredTask = $this->task([
            'id' => 42,
            'model_override_id' => 88,
            'model_override_mode' => ItemModelOverrideMode::Required->value,
        ]);
        $required = $this->resolver->resolve($requiredTask);
        self::assertTrue($required->requiresModelOverride());

        $applied = $this->applier->apply($this->context(), $requiredTask);
        self::assertSame('88', $applied->variables['_item_model_override_id']);
        self::assertSame('required', $applied->variables['_item_model_override_mode']);

        // Mode without an id is meaningless and must not count as an override.
        $orphan = $this->resolver->resolve($this->task([
            'id' => 43,
            'model_override_mode' => ItemModelOverrideMode::Required->value,
        ]));
        self::assertFalse($orphan->hasModelOverride());
        self::assertNull($orphan->modelOverrideMode);
        self::assertSame(0, $orphan->countOverrides());
    }

    // 11. Explicit title protection stamps the guard variables.

    public function test_title_protection_stamps_guard_variables(): void
    {
        $userTask = $this->task([
            'id' => 51,
            'title' => 'Tiêu đề do biên tập viên đặt',
            'title_protection' => ItemTitleProtection::User->value,
        ]);
        $userPolicy = $this->resolver->resolve($userTask);
        self::assertTrue($userPolicy->protectTitle);
        self::assertSame(ItemTitleProtection::User, $userPolicy->effectiveTitleProtection());

        $userVars = $this->applier->apply($this->context(), $userTask)->variables;
        self::assertSame('1', $userVars[ContentProjectItemGenerationPolicyApplier::VAR_PROTECT_TITLE]);
        self::assertSame('user', $userVars['_item_title_protection']);
        self::assertSame('1', $userVars['_item_title_is_user_defined']);

        $reviewedVars = $this->applier->apply($this->context(), $this->task([
            'id' => 52,
            'title' => 'Tiêu đề đã duyệt',
            'title_protection' => ItemTitleProtection::Reviewed->value,
        ]))->variables;
        self::assertSame('1', $reviewedVars[ContentProjectItemGenerationPolicyApplier::VAR_PROTECT_TITLE]);
        self::assertSame('reviewed', $reviewedVars['_item_title_protection']);
        self::assertSame('1', $reviewedVars['_item_title_is_user_defined']);

        $generatedVars = $this->applier->apply($this->context(), $this->task([
            'id' => 53,
            'title' => 'Tiêu đề AI sinh',
            'title_protection' => ItemTitleProtection::Generated->value,
        ]))->variables;
        self::assertSame('1', $generatedVars[ContentProjectItemGenerationPolicyApplier::VAR_PROTECT_TITLE]);
        self::assertSame('generated', $generatedVars['_item_title_protection']);
        self::assertArrayNotHasKey('_item_title_is_user_defined', $generatedVars);
    }

    // 12. Filled title without stored protection is treated as user-owned; empty title is free.

    public function test_filled_title_without_protection_is_protected_as_user_title(): void
    {
        $filled = $this->resolver->resolve($this->task([
            'id' => 61,
            'title' => 'Tiêu đề nhập tay',
        ]));
        self::assertTrue($filled->protectTitle);
        self::assertNull($filled->titleProtection);
        self::assertSame(ItemTitleProtection::User, $filled->effectiveTitleProtection());
        self::assertSame(0, $filled->countOverrides(), 'Implicit protection is not an explicit override.');

        $filledVars = $this->applier->apply($this->context(), $this->task([
            'id' => 61,
            'title' => 'Tiêu đề nhập tay',
        ]))->variables;
        self::assertSame('1', $filledVars[ContentProjectItemGenerationPolicyApplier::VAR_PROTECT_TITLE]);
        self::assertSame('user', $filledVars['_item_title_protection']);

        $keywordOnly = $this->resolver->resolve($this->task([
            'id' => 62,
            'keyword' => 'balo chống nước',
            'title' => '   ',
        ]));
        self::assertFalse($keywordOnly->protectTitle);
        self::assertNull($keywordOnly->effectiveTitleProtection());

        $keywordVars = $this->applier->apply($this->context(), $this->task([
            'id' => 62,
            'keyword' => 'balo chống nước',
        ]))->variables;
        self::assertArrayNotHasKey(ContentProjectItemGenerationPolicyApplier::VAR_PROTECT_TITLE, $keywordVars);
    }

    // 13. Rows loaded before the policy migration must still resolve.

    public function test_legacy_task_without_policy_columns_still_resolves(): void
    {
        $legacy = $this->task([
            'id' => 71,
            'keyword' => 'ghế công thái học',
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);

        $policy = $this->resolver->resolve($legacy);

        self::assertInstanceOf(ContentProjectItemGenerationPolicy::class, $policy);
        self::assertTrue($policy->toneIsAutomaticVariety);
        self::assertNotNull($policy->tone);
        self::assertNull($policy->contentLengthMode);
        self::assertNull($policy->contentLengthTargetWords);
        self::assertNull($policy->generationMode);
        self::assertNull($policy->modelOverrideId);
        self::assertNull($policy->modelOverrideMode);
        self::assertNull($policy->titleProtection);
        self::assertFalse($policy->protectTitle);
        self::assertSame(0, $policy->countOverrides());

        // Nothing to persist against a row that was never saved.
        $applied = $this->applier->apply($this->context(), $legacy);
        self::assertSame($policy->tone, $applied->variables['tone']);
    }

    // 14. The resolver has no site coupling at all.

    public function test_resolver_never_reads_a_legacy_site_tone(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectItemGenerationPolicyResolver::class))->getFileName() ?: '',
        );

        self::assertStringNotContainsString('Site::', $source);
        self::assertStringNotContainsString('SitePromptContext', $source);
        self::assertStringNotContainsString('site_id', $source);
        self::assertStringNotContainsString('resolveToneForSite', $source);

        $withSiteContext = $this->applier->apply(
            $this->context([
                'tone' => self::LEGACY_SITE_TONE,
                'site_name' => 'Legacy Shop',
            ]),
            $this->task([
                'id' => 81,
                'site_id' => 9,
                'keyword' => 'nồi chiên không dầu',
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            ]),
        );

        self::assertNotSame(self::LEGACY_SITE_TONE, $withSiteContext->variables['tone']);
        self::assertContains($withSiteContext->variables['tone'], $this->promptSettings->getToneOfVoiceOptions());
        self::assertSame('Legacy Shop', $withSiteContext->variables['site_name'], 'Unrelated variables survive.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes): SeoProjectTask
    {
        // setRawAttributes — pure PHPUnit has no DB connection, so skip casts/persistence.
        $task = new SeoProjectTask;
        $task->setRawAttributes($attributes, true);

        return $task;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function context(array $variables = []): TaskTestContext
    {
        return new TaskTestContext(
            article: null,
            isNewArticle: true,
            matchedBy: null,
            variables: $variables,
            summary: 'policy test',
            siteId: 9,
            postType: SeoProjectTask::POST_TYPE_ARTICLE,
            projectTaskType: SeoProjectTask::TYPE_CREATE,
        );
    }

    private function modelCandidate(int $id, bool $free): object
    {
        return new class($id, $free)
        {
            public function __construct(public int $id, private bool $free) {}

            public function isFree(): bool
            {
                return $this->free;
            }
        };
    }
}
