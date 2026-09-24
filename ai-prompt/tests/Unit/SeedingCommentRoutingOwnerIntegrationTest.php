<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingOwnerResolver;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\InteractivePromptExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Live routing regression (optional MySQL / seeded env).
 * Default phpunit sqlite :memory: skips DB-backed cases; contracts still run.
 *
 * QuickFree paid-fallback shape is also covered by AiRoutingPolicyPlannerTest.
 */
final class SeedingCommentRoutingOwnerIntegrationTest extends TestCase
{
    public function test_interactive_executor_resolves_routing_owner_via_prompt_not_auth(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/InteractivePromptExecutor.php',
        );
        self::assertStringContainsString('AiRoutingOwnerResolver', $src);
        self::assertStringContainsString('resolveRoutingOwnerId', $src);
        self::assertStringNotContainsString('auth()->id()', $src);
    }

    public function test_prompt_owner_wins_over_acting_auth_user_id(): void
    {
        $prompt = new SeoPrompt();
        $prompt->user_id = 42;

        $owner = (new AiRoutingOwnerResolver())->resolve(
            explicitUserId: null,
            prompt: $prompt,
            connection: null,
        );
        self::assertSame(42, $owner);

        $executor = new InteractivePromptExecutor(
            canonical: app(\Omnichannel\Addons\AiPrompt\Services\CanonicalAiTextExecutionService::class),
        );
        $method = new ReflectionMethod($executor, 'resolveRoutingOwnerId');
        $method->setAccessible(true);
        self::assertSame(42, $method->invoke($executor, $prompt));
    }

    public function test_staff_actor_still_gets_text_fast_candidates_via_prompt_owner(): void
    {
        if (! $this->hasLiveUsersTable()) {
            self::markTestSkipped('Live users table unavailable (sqlite phpunit default).');
        }

        $staff = User::query()->where('role', User::ROLE_STAFF)->orderBy('id')->first();
        if (! $staff instanceof User) {
            self::markTestSkipped('Need staff user in local DB.');
        }

        $prio = app(AiModelPriorityService::class);
        $staffFast = count($prio->effectiveAreaModels((int) $staff->id, AiModelArea::TextFast));
        if ($staffFast > 0) {
            self::markTestSkipped('Staff already has text.fast membership — cannot prove owner fallback.');
        }

        $shared = app(SeedingSharedCommentPromptResolver::class)->resolveActive();
        $prompt = SeoPrompt::query()->find($shared['prompt_id']);
        self::assertInstanceOf(SeoPrompt::class, $prompt);

        $routingOwner = app(AiRoutingOwnerResolver::class)->resolve(
            explicitUserId: null,
            prompt: $prompt,
            connection: null,
        );
        self::assertGreaterThan(0, $routingOwner);
        self::assertGreaterThan(
            0,
            count($prio->effectiveAreaModels($routingOwner, AiModelArea::TextFast)),
            'Prompt routing owner must have text.fast membership.',
        );

        auth()->login($staff);

        $executor = app(InteractivePromptExecutor::class);
        $method = new ReflectionMethod($executor, 'resolveRoutingOwnerId');
        $method->setAccessible(true);
        $resolved = $method->invoke($executor, $prompt);
        self::assertSame($routingOwner, $resolved);

        $profile = (new PromptExecutionProfileResolver())->resolve(
            $prompt,
            SeedingCommentGenerateCapabilityHandler::KEY,
        );
        self::assertSame(AiExecutionProfile::TextFast, $profile);
        $policy = (new PromptRoutingPolicyResolver())->resolve(
            $prompt,
            SeedingCommentGenerateCapabilityHandler::KEY,
        );
        self::assertSame(AiRoutingPolicy::QuickFree, $policy);

        $ctx = new AiRoutingContext(
            userId: $resolved,
            hookKey: SeedingCommentGenerateCapabilityHandler::KEY,
            routingPolicy: $policy,
            routingPolicyEffective: $policy,
        );
        $eligible = app(AiRoutingTargetService::class)->eligibleCandidates($resolved, $profile, $ctx);
        self::assertNotEmpty($eligible);
    }

    public function test_quick_free_with_only_paid_text_fast_still_has_candidates(): void
    {
        if (! $this->hasLiveUsersTable()) {
            self::markTestSkipped('Live users table unavailable (sqlite phpunit default).');
        }

        $owner = User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->orderBy('id')->first();
        if (! $owner instanceof User) {
            self::markTestSkipped('Need owner user.');
        }

        $uid = (int) $owner->id;
        $profile = AiExecutionProfile::TextFast;
        $ctx = new AiRoutingContext(
            userId: $uid,
            hookKey: SeedingCommentGenerateCapabilityHandler::KEY,
            routingPolicy: AiRoutingPolicy::QuickFree,
            routingPolicyEffective: AiRoutingPolicy::QuickFree,
        );

        $targets = app(AiRoutingTargetService::class);
        $paid = $targets->paidAreaCandidates($uid, $profile, $ctx);
        if ($paid === []) {
            self::markTestSkipped('No paid text.fast candidates in local AI Routing.');
        }

        [$plan, $ordered] = (new AiCandidatePlanner())->plan(
            profile: $profile->value,
            context: $ctx,
            candidates: $paid,
            maxAiAttempts: 6,
            maxFreeAttempts: 3,
            healthSkipReason: static fn ($c) => null,
            modelArea: $profile->value,
            secondaryCandidates: [],
            primaryArea: $profile->value,
            secondaryArea: AiModelArea::TextFast->value,
        );

        self::assertNotEmpty($ordered);
        foreach ($ordered as $candidate) {
            self::assertFalse($candidate->isFree);
        }
        self::assertFalse((bool) ($plan->meta['free_first_secondary_paid'] ?? false));
    }

    public function test_quick_free_with_free_and_paid_tries_free_first(): void
    {
        if (! $this->hasLiveUsersTable()) {
            self::markTestSkipped('Live users table unavailable (sqlite phpunit default).');
        }

        $owner = User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->orderBy('id')->first();
        if (! $owner instanceof User) {
            self::markTestSkipped('Need owner user.');
        }

        $uid = (int) $owner->id;
        $profile = AiExecutionProfile::TextFast;
        $ctx = new AiRoutingContext(
            userId: $uid,
            hookKey: SeedingCommentGenerateCapabilityHandler::KEY,
            routingPolicy: AiRoutingPolicy::QuickFree,
            routingPolicyEffective: AiRoutingPolicy::QuickFree,
        );

        $targets = app(AiRoutingTargetService::class);
        $eligible = $targets->eligibleCandidates($uid, $profile, $ctx);
        $paid = $targets->paidAreaCandidates($uid, $profile, $ctx);
        $hasFree = false;
        foreach ($eligible as $c) {
            if ($c->isFree) {
                $hasFree = true;
                break;
            }
        }
        if (! $hasFree || $paid === []) {
            self::markTestSkipped('Need both free + paid text.fast candidates.');
        }

        [$plan, $ordered] = (new AiCandidatePlanner())->plan(
            profile: $profile->value,
            context: $ctx,
            candidates: $eligible,
            maxAiAttempts: 6,
            maxFreeAttempts: 3,
            healthSkipReason: static fn ($c) => null,
            modelArea: $profile->value,
            secondaryCandidates: $paid,
            primaryArea: $profile->value,
            secondaryArea: AiModelArea::TextFast->value,
        );

        self::assertNotEmpty($ordered);
        self::assertTrue($ordered[0]->isFree);
        self::assertTrue((bool) ($plan->meta['free_first_secondary_paid'] ?? false));
    }

    public function test_free_only_excludes_paid_from_eligible(): void
    {
        if (! $this->hasLiveUsersTable()) {
            self::markTestSkipped('Live users table unavailable (sqlite phpunit default).');
        }

        $owner = User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->orderBy('id')->first();
        if (! $owner instanceof User) {
            self::markTestSkipped('Need owner user.');
        }

        $uid = (int) $owner->id;
        $profile = AiExecutionProfile::TextFast;
        $ctx = new AiRoutingContext(
            userId: $uid,
            hookKey: SeedingCommentGenerateCapabilityHandler::KEY,
            routingPolicy: AiRoutingPolicy::FreeOnly,
            routingPolicyEffective: AiRoutingPolicy::FreeOnly,
            freeOnly: true,
        );

        $eligible = app(AiRoutingTargetService::class)->eligibleCandidates($uid, $profile, $ctx);
        if ($eligible === []) {
            self::markTestSkipped('No free candidates under FreeOnly.');
        }

        foreach ($eligible as $c) {
            self::assertTrue($c->isFree);
        }
    }

    private function hasLiveUsersTable(): bool
    {
        try {
            return Schema::hasTable('users');
        } catch (\Throwable) {
            return false;
        }
    }
}
