<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

/**
 * Builtin Agent Skill catalog — presentation mapping only.
 * Capability existence/availability comes from CanonicalCapabilityRegistry + Gateway reads.
 */
final class BuiltinSkillCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return array_merge(
            GeneralSkills::definitions(),
            KnowledgeSkills::definitions(),
            AutomationSkills::definitions(),
            ObservabilitySkills::definitions(),
            PackSkills::definitions(),
            ContentProjectSkills::definitions(),
            KeywordIntelligenceSkills::definitions(),
            SerpIntelligenceSkills::definitions(),
            SeoAuditSkills::definitions(),
            OperationsSkills::definitions(),
        );
    }
}
