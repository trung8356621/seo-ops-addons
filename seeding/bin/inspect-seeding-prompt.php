<?php

declare(strict_types=1);

$candidates = [
    dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'omnichannel-client',
    'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'omnichannel-client',
];
$clientRoot = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
        $clientRoot = $candidate;
        break;
    }
}
if ($clientRoot === null) {
    fwrite(STDERR, "client root missing\n");
    exit(1);
}

require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $row = Illuminate\Support\Facades\DB::connection('omi_seeding')
        ->table('seeding_comment_prompt_settings')
        ->orderBy('id')
        ->first();
    echo json_encode([
        'found' => $row !== null,
        'id' => $row->id ?? null,
        'len' => $row ? strlen((string) $row->prompt_body) : 0,
        'has_mcp' => $row ? str_contains((string) $row->prompt_body, '{{mcp_context}}') : false,
        'preview' => $row ? mb_substr((string) $row->prompt_body, 0, 280) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}
