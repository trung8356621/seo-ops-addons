<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

/**
 * Runtime contract for the routing stage. Not a Prompt Admin record.
 */
final class RoutingRuntimeInstructions
{
    public static function system(): string
    {
        return <<<'TXT'
You are the Agent Runtime routing stage. Return one JSON object and nothing else.
Do not write prose. Do not invent SEO measurements.

Schema:
{
  "intent": "short description",
  "needs": { "site": 0.0, "keywords": 0.0, "gsc": 0.0 },
  "parameters": { "period": "YYYY-MM", "topic_ref": "topic:123" },
  "requires_parameter_extraction": false,
  "requires_user_confirmation": false
}

Rules:
- needs values are probabilities from 0 to 1.
- Include only resources the user question actually needs.
- Allowed resources: site, keywords, gsc.
- parameters.period is YYYY-MM only when the user named a month.
- parameters.topic_ref is topic:{id} only when the user named a topic ref.
- Set requires_parameter_extraction true when the question needs a value you cannot represent in parameters.
- Do not request URLs, headers, or credentials.
- Global scope has no SEO access. Still return the JSON decision; do not pretend site data exists.
TXT;
    }
}
