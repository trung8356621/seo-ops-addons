<?php

declare(strict_types=1);

$clientRoot = 'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'omnichannel-client';
require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;

$resolver = app(SeedingSharedCommentPromptResolver::class);
$before = $resolver->resolveActive();
$marker = 'SEEDING_PROMPT_EDIT_PROOF_'.time();
$conn = DB::connection('omi_seo_ai');
$orig = (string) $conn->table('prompts')->where('id', 26)->value('markdown_content');

$conn->table('prompts')->where('id', 26)->update([
    'markdown_content' => $orig."\n\n".$marker,
    'updated_at' => now(),
]);

$after = $resolver->resolveActive();
$rendered = $resolver->renderFinalPrompt('MCP_CTX_PROOF');
$ok = str_contains((string) $after['body'], $marker) && str_contains($rendered, $marker);

$conn->table('prompts')->where('id', 26)->update([
    'markdown_content' => $orig,
    'updated_at' => now(),
]);

$restored = $resolver->resolveActive();

echo json_encode([
    'runtime_prompt_id' => $before['prompt_id'],
    'runtime_version_before' => $before['prompt_version_id'],
    'edit_affects_resolve' => $ok,
    'runtime_prompt_id_after' => $after['prompt_id'],
    'restored_has_marker' => str_contains((string) $restored['body'], $marker),
    'still_prompt_26' => (int) $restored['prompt_id'] === 26,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
