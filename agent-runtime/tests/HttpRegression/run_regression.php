<?php

/**
 * SEO-OPS Agent HTTP Regression Test Runner
 *
 * Runs end-to-end regression tests across all 3 key diagnostic cases:
 *   1. Topic Coverage statistics (PASS expected)
 *   2. Topic SEO listing (Ambiguous routing failure)
 *   3. Site-wide SEO improvement (Answer Model rejection + Draft fallback)
 */

declare(strict_types=1);

require_once 'D:/work/omnichannel-client/vendor/autoload.php';
$app = require_once 'D:/work/omnichannel-client/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

$baseUrl = getenv('BASE_URL') ?: 'http://seo-ops.test';
$siteId = (int)(getenv('SITE_ID') ?: 4);

// Auto-derive valid manager session for Site 4
$session = DB::table('sessions')->whereNotNull('payload')->latest('last_activity')->first();
if (!$session) {
    fwrite(STDERR, "Error: No session record found in database.\n");
    exit(1);
}
$sessionData = unserialize(base64_decode($session->payload));
$cookieSession = Crypt::encrypt(CookieValuePrefix::create('laravel-session', Crypt::getKey()) . $session->id, false);
$csrfToken = $sessionData['_token'] ?? '';

echo "============================================================\n";
echo " SEO-OPS AGENT API REGRESSION SUITE\n";
echo " Base URL: {$baseUrl} | Site ID: {$siteId}\n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck(string $label, bool $condition, ?string $detail = null): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$label}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$label}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

function httpPost(string $url, array $payload): array {
    global $cookieSession, $csrfToken;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-CSRF-TOKEN: ' . $csrfToken,
        'Cookie: laravel-session=' . $cookieSession,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode((string)$response, true) ?? [],
        'raw' => (string)$response,
        'error' => $err,
    ];
}

// -------------------------------------------------------------
// Case 1: Topic Coverage statistics
// -------------------------------------------------------------
echo "[CASE 1] Topic Coverage statistics: 'Thống kê số chủ đề Strong, Medium và Weak của website.'\n";
$res1 = httpPost("{$baseUrl}/agent-runtime/turns", [
    'message' => 'Thống kê số chủ đề Strong, Medium và Weak của website.',
    'scope' => ['type' => 'site', 'site_id' => $siteId, 'ref' => "site:{$siteId}"],
    'hostContext' => [
        'appKey' => 'seo-ops',
        'scope' => ['type' => 'site', 'siteId' => $siteId, 'ref' => "site:{$siteId}"],
    ],
    'history' => [],
    'debug_mode' => false,
    'diagnostics' => true,
]);

$d1 = $res1['body']['data'] ?? [];
$selectedCand1 = $d1['execution']['routing_review']['selected_candidate_id'] ?? '';
$capabilities1 = $d1['execution']['capabilities'] ?? [];

assertCheck("HTTP status is 200", $res1['code'] === 200, "Got HTTP {$res1['code']}");
assertCheck("Routing outcome is confident", ($d1['execution']['outcome'] ?? '') === 'confident', "Got outcome=" . ($d1['execution']['outcome'] ?? 'none'));
assertCheck("Selected candidate is 'keywords.landscape'", str_contains($selectedCand1, 'keywords.landscape'), "Got {$selectedCand1}");
assertCheck("Capability is 'keywords.landscape'", in_array('keywords.landscape', $capabilities1, true));
assertCheck("No failure code present", empty($d1['failure_code']));
assertCheck("No access_tmp leaked in payload", !str_contains($res1['raw'], 'access_tmp'));
echo "\n";

// -------------------------------------------------------------
// Case 2: Topic SEO listing
// -------------------------------------------------------------
echo "[CASE 2] Topic SEO listing: 'Cho tôi xem danh sách các Topic SEO của website.'\n";
$res2 = httpPost("{$baseUrl}/agent-runtime/turns", [
    'message' => 'Cho tôi xem danh sách các Topic SEO của website.',
    'scope' => ['type' => 'site', 'site_id' => $siteId, 'ref' => "site:{$siteId}"],
    'hostContext' => [
        'appKey' => 'seo-ops',
        'scope' => ['type' => 'site', 'siteId' => $siteId, 'ref' => "site:{$siteId}"],
    ],
    'history' => [],
    'debug_mode' => false,
    'diagnostics' => true,
]);

$d2 = $res2['body']['data'] ?? [];
$runUlid2 = $d2['run_ulid'] ?? '';
$dbRun2 = $runUlid2 ? DB::table('agent_runs')->where('ulid', $runUlid2)->first() : null;

assertCheck("HTTP status is 200", $res2['code'] === 200, "Got HTTP {$res2['code']}");
assertCheck("Routing outcome is ambiguous", ($d2['execution']['outcome'] ?? '') === 'ambiguous', "Got outcome=" . ($d2['execution']['outcome'] ?? 'none'));
assertCheck("DB failure code is 'local_tool_router_ambiguous'", ($dbRun2->failure_code ?? '') === 'local_tool_router_ambiguous', "Got " . ($dbRun2->failure_code ?? 'none'));
assertCheck("Returns clarification prompt in message", str_contains($d2['message'] ?? '', 'Bạn muốn xem dữ liệu hiện có hay nhận đề xuất cải thiện?'), "Message: " . ($d2['message'] ?? ''));
assertCheck("No execution capabilities executed", empty($d2['execution']['capabilities']));
assertCheck("No access_tmp leaked in payload", !str_contains($res2['raw'], 'access_tmp'));
echo "\n";

// -------------------------------------------------------------
// Case 3: SEO Improvement
// -------------------------------------------------------------
echo "[CASE 3] Site-wide SEO improvement: 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.'\n";
$res3Step1 = httpPost("{$baseUrl}/agent-runtime/turns", [
    'message' => 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
    'scope' => ['type' => 'site', 'site_id' => $siteId, 'ref' => "site:{$siteId}"],
    'hostContext' => [
        'appKey' => 'seo-ops',
        'scope' => ['type' => 'site', 'siteId' => $siteId, 'ref' => "site:{$siteId}"],
    ],
    'history' => [],
    'debug_mode' => true,
    'diagnostics' => true,
]);

$d3_1 = $res3Step1['body']['data'] ?? [];
$runUlid3 = $d3_1['run_ulid'] ?? '';
$execution3_1 = $d3_1['model_call']['execution'] ?? [];
$selectedCand3 = $execution3_1['routing_review']['selected_candidate_id'] ?? '';

assertCheck("Step 1 HTTP status is 200", $res3Step1['code'] === 200, "Got HTTP {$res3Step1['code']}");
assertCheck("Step 1 run status is 'paused' (debug interception)", ($d3_1['status'] ?? '') === 'paused', "Got status=" . ($d3_1['status'] ?? 'none'));
assertCheck("Selected candidate is 'seo_audit.site_improve'", str_contains($selectedCand3, 'seo_audit.site_improve'), "Got {$selectedCand3}");
assertCheck("Capability mapped is 'seo_audit.worst_articles'", in_array('seo_audit.worst_articles', $execution3_1['capabilities'] ?? [], true));
assertCheck("Model call key is 'answer'", ($d3_1['model_call']['key'] ?? '') === 'answer');
assertCheck("Step 1 payload has no access_tmp", !str_contains($res3Step1['raw'], 'access_tmp'));

// Mock invalid model completion (unverified metrics / generic recommendation table)
$mockModelCompletion = json_encode([
    'message' => 'Đề xuất cải thiện SEO tổng thể cho website.',
    'blocks' => [
        [
            'type' => 'table',
            'title' => 'Kế hoạch cải thiện SEO',
            'columns' => [
                ['key' => 'title', 'label' => 'Nội dung cần tối ưu'],
                ['key' => 'priority', 'label' => 'Mức độ ưu tiên'],
            ],
            'rows' => [
                ['title' => 'Tối ưu thẻ tiêu đề và meta description cho 50 bài viết', 'priority' => 1],
                ['title' => 'Bổ sung liên kết nội bộ giữa các chủ đề', 'priority' => 2],
            ],
        ],
    ],
    'actions' => [],
], JSON_UNESCAPED_UNICODE);

$res3Step2 = httpPost("{$baseUrl}/agent-runtime/model-debug/apply", [
    'run_ulid' => $runUlid3,
    'manual_result' => $mockModelCompletion,
    'use_verified' => false,
    'recover_stranded' => false,
]);

$d3_2 = $res3Step2['body']['data'] ?? [];
$blocks3_2 = $d3_2['blocks'] ?? [];
$tableBlock = null;
$warningBlock = null;
foreach ($blocks3_2 as $block) {
    if (($block['type'] ?? '') === 'table') {
        $tableBlock = $block;
    }
    if (($block['type'] ?? '') === 'warning') {
        $warningBlock = $block;
    }
}
$is50RowTable = $tableBlock !== null && count($tableBlock['rows'] ?? []) === 50;

assertCheck("Step 2 HTTP status is 200", $res3Step2['code'] === 200, "Got HTTP {$res3Step2['code']}");
assertCheck("Model response was rejected by parser", ($d3_2['execution']['answer_status'] ?? '') === 'rejected', "Got answer_status=" . ($d3_2['execution']['answer_status'] ?? ''));
assertCheck("Fallback warning block is displayed", $warningBlock !== null || str_contains($d3_2['message'] ?? '', 'omitted') || str_contains($d3_2['message'] ?? '', 'kết quả mô hình'));
assertCheck("Reverted to 50-row Draft Intake table", $is50RowTable, "Got rows count=" . ($tableBlock ? count($tableBlock['rows'] ?? []) : 0));
assertCheck("Step 2 payload has no access_tmp", !str_contains($res3Step2['raw'], 'access_tmp'));
echo "\n";

// -------------------------------------------------------------
// Summary
// -------------------------------------------------------------
echo "============================================================\n";
echo " REGRESSION SUMMARY: PASS = {$passCount}, FAIL = {$failCount}\n";
echo "============================================================\n";

exit($failCount === 0 ? 0 : 1);
