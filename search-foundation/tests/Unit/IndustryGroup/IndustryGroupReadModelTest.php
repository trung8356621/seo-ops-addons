<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\IndustryGroup;

use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\MatchResearchRegistry;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchCapabilities;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;
use Omnichannel\Addons\SearchFoundation\Services\IndustryGroup\IndustryGroupReadModel;
use PHPUnit\Framework\TestCase;

final class IndustryGroupReadModelTest extends TestCase
{
    public function test_taxonomy_product_projects_to_industry_group_reusing_stable_key(): void
    {
        $product = $this->resource('industry.products.balo.abc12345', 'products', 'balo', ['ba lô', 'backpack']);
        $model = new IndustryGroupReadModel($this->registry([$product]));

        $groups = $model->list(1, 'bags');
        self::assertCount(1, $groups);
        self::assertSame('industry.products.balo.abc12345', $groups[0]->key);
        self::assertSame(IndustryGroupType::Products, $groups[0]->groupType);
        self::assertSame('balo', $groups[0]->name);
        self::assertSame(['ba lô', 'backpack'], $groups[0]->aliases);
        self::assertSame('bags', $groups[0]->industryContextKey);
        self::assertSame(42, $groups[0]->provenance['match_revision_id']);
        self::assertFalse($groups[0]->stale);

        $found = $model->find('industry.products.balo.abc12345', 1, 'bags');
        self::assertNotNull($found);
        self::assertSame($product->key, $found->key);
    }

    public function test_all_eight_taxonomy_groups_qualify(): void
    {
        $resources = [];
        foreach (IndustryGroupType::cases() as $type) {
            $resources[] = $this->resource(
                'industry.'.$type->value.'.x.suffix',
                $type->value,
                $type->value.'-name',
            );
        }
        $model = new IndustryGroupReadModel($this->registry($resources));
        $groups = $model->list();
        self::assertCount(8, $groups);
        self::assertSame(IndustryGroupType::values(), array_map(
            static fn ($g) => $g->groupType->value,
            $groups,
        ));
    }

    public function test_non_taxonomy_resources_do_not_qualify(): void
    {
        $resources = [
            $this->resource('industry.generic_cores.x.s', 'generic_cores', 'gia cong'),
            $this->resource('industry.service_intent_terms.x.s', 'service_intent_terms', 'gia cong may'),
            $this->resource('industry.aliases.x.s', 'aliases', 'alias term'),
            new MatchResearchResource(
                key: 'industry.ambiguities.du.s',
                origin: MatchResearchOrigin::Industry,
                kind: MatchResearchKind::Ambiguity,
                sourceLocale: 'vi',
                label: 'dù',
                description: 'amb',
                matchMode: 'token',
                payload: ['group' => 'ambiguities', 'name' => 'dù'],
                capabilities: new MatchResearchCapabilities(true, false, true, false),
                editable: false,
                deletable: false,
                provenance: ['group' => 'ambiguities', 'industry_context_key' => 'bags'],
            ),
            $this->resource('industry.products.balo.s', 'products', 'balo'),
        ];
        $model = new IndustryGroupReadModel($this->registry($resources));
        $groups = $model->list();
        self::assertCount(1, $groups);
        self::assertSame('products', $groups[0]->groupType->value);

        self::assertFalse(IndustryGroupReadModel::qualifies($resources[0]));
        self::assertFalse(IndustryGroupReadModel::qualifies($resources[1]));
        self::assertFalse(IndustryGroupReadModel::qualifies($resources[2]));
        self::assertFalse(IndustryGroupReadModel::qualifies($resources[3]));
        self::assertTrue(IndustryGroupReadModel::qualifies($resources[4]));
    }

    public function test_locale_overlay_and_missing_localization_are_explicit(): void
    {
        $product = new MatchResearchResource(
            key: 'industry.products.balo.s',
            origin: MatchResearchOrigin::Industry,
            kind: MatchResearchKind::Concept,
            sourceLocale: 'vi',
            label: 'balo',
            description: 'Industry Group',
            matchMode: 'phrase',
            payload: [
                'group' => 'products',
                'name' => 'balo',
                'aliases' => ['ba lô'],
                'positive_examples' => [],
                'negative_examples' => [],
            ],
            capabilities: new MatchResearchCapabilities(true, false, false, false),
            editable: false,
            deletable: false,
            provenance: [
                'group' => 'products',
                'industry_context_key' => 'bags',
                'match_revision_id' => 1,
                'stale' => false,
            ],
            locales: [
                'en' => [
                    'name' => 'backpack',
                    'aliases' => ['school bag'],
                ],
            ],
        );
        $model = new IndustryGroupReadModel($this->registry([$product]));

        $en = $model->find($product->key, null, 'bags', 'en');
        self::assertNotNull($en);
        self::assertTrue($en->localized);
        self::assertSame('en', $en->requestedLocale);
        self::assertSame('en', $en->effectiveLocale);
        self::assertSame('backpack', $en->name);
        self::assertSame(['school bag'], $en->aliases);

        $ja = $model->find($product->key, null, 'bags', 'ja');
        self::assertNotNull($ja);
        self::assertFalse($ja->localized);
        self::assertSame('ja', $ja->requestedLocale);
        self::assertSame('vi', $ja->effectiveLocale);
        self::assertSame('balo', $ja->name);
        self::assertSame(['ba lô'], $ja->aliases);
    }

    public function test_semantic_definition_includes_canonical_aliases_and_dedupes(): void
    {
        $product = $this->resource(
            'industry.products.balo.s',
            'products',
            'balo',
            ['ba lô', 'BALO', 'backpack', 'ba lô'],
            ['balo', 'xưởng may'],
        );
        $def = (new IndustryGroupReadModel($this->registry([$product])))
            ->find($product->key)
            ?->semanticDefinition();

        self::assertNotNull($def);
        self::assertSame('industry.products.balo.s', $def->key);
        self::assertSame('balo', $def->name);
        self::assertSame(['balo', 'ba lô', 'backpack', 'xưởng may'], $def->positiveExamples);
        self::assertSame([], $def->negativeExamples);
        self::assertSame('phrase', $def->matchMode);

        $defs = (new IndustryGroupReadModel($this->registry([$product])))->semanticDefinitions();
        self::assertCount(1, $defs);
        self::assertSame($def->toArray(), $defs[0]->toArray());
    }

    public function test_no_industry_group_persistence_surface(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root.'/src/Services/IndustryGroup/IndustryGroupReadModel.php');
        self::assertStringNotContainsString('Schema::', $source);
        self::assertStringNotContainsString('industry_groups', $source);
        self::assertStringNotContainsString('DB::', $source);
        self::assertStringNotContainsString('::create(', $source);
        $migrations = glob($root.'/database/migrations/*industry_group*') ?: [];
        self::assertSame([], $migrations);
        self::assertFileDoesNotExist($root.'/src/Models/IndustryGroup.php');
    }

    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $positive
     */
    private function resource(
        string $key,
        string $group,
        string $name,
        array $aliases = [],
        array $positive = [],
    ): MatchResearchResource {
        return new MatchResearchResource(
            key: $key,
            origin: MatchResearchOrigin::Industry,
            kind: MatchResearchKind::Concept,
            sourceLocale: 'vi',
            label: $name,
            description: 'Industry Group',
            matchMode: 'phrase',
            payload: [
                'group' => $group,
                'name' => $name,
                'aliases' => $aliases,
                'positive_examples' => $positive,
                'negative_examples' => [],
            ],
            capabilities: new MatchResearchCapabilities(true, false, false, false),
            editable: false,
            deletable: false,
            provenance: [
                'group' => $group,
                'industry_context_key' => 'bags',
                'match_revision_id' => 42,
                'stale' => false,
                'industry_group' => IndustryGroupType::tryFromGroup($group) !== null,
            ],
        );
    }

    /** @param list<MatchResearchResource> $resources */
    private function registry(array $resources): MatchResearchRegistry
    {
        return new class($resources) implements MatchResearchRegistry
        {
            /** @param list<MatchResearchResource> $resources */
            public function __construct(private array $resources) {}

            public function list(?int $siteId = null, ?MatchResearchOrigin $origin = null, ?string $industryContextKey = null): array
            {
                return array_values(array_filter(
                    $this->resources,
                    static fn (MatchResearchResource $r): bool => $origin === null || $r->origin === $origin,
                ));
            }

            public function find(string $key, ?int $siteId = null, ?string $industryContextKey = null): ?MatchResearchResource
            {
                foreach ($this->resources as $resource) {
                    if ($resource->key === $key) {
                        return $resource;
                    }
                }

                return null;
            }
        };
    }
}
