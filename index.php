<?php
header('Content-Type: text/plain; charset=utf-8');
header('X-Version: 2.1.0');

/**
 * SPM Notion → YOURLS Webhook (Prefer page_id patch)
 * - 先用 Notion webhook 的 data.id 直接 PATCH 「短網址」
 * - 無 page_id 才回退查詢（rich_text → title → contains）
 * - 正確回 HTTP 狀態碼讓 Notion 自動化能判斷成功/失敗
 */

// ────────────────────────────────────────────────────────
// Polyfills（舊版 PHP 相容）
// ────────────────────────────────────────────────────────
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// ────────────────────────────────────────────────────────
/** 讀取 .env 常數（多路徑） */
function loadEnvToConstants()
{
    $candidates = [
        '/volume1/web_packages/spm_env/shorten_and_post.env',
        __DIR__ . '/shorten_and_post.env',
    ];
    foreach ($candidates as $path) {
        if (!is_file($path) || !is_readable($path))
            continue;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '='))
                continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (
                (strlen($v) >= 2) && (
                    ($v[0] === '"' && substr($v, -1) === '"') ||
                    ($v[0] === "'" && substr($v, -1) === "'")
                )
            ) {
                $v = substr($v, 1, -1);
            }
            if (!defined($k))
                define($k, $v);
        }
        break; // 第一個載入成功就停止
    }
}
loadEnvToConstants();

// 檢查必要常數
$required = ['YOURLS_API', 'YOURLS_SIGNATURE', 'NOTION_TOKEN', 'NOTION_DATABASE_ID', 'NOTION_VERSION'];
foreach ($required as $c) {
    if (!defined($c) || constant($c) === '') {
        http_response_code(500);
        echo "❌ Server misconfigured: missing env {$c}\n";
        exit;
    }
}

// ✅ 解析 Notion webhook 的 JSON 輸入
$raw = file_get_contents('php://input');
writeLog("received-webhook", "Length: " . strlen($raw));

$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    writeLog("error", "JSON Decode Error: " . json_last_error_msg());
    http_response_code(400);
    echo "⚠️ 無效的 JSON：" . json_last_error_msg() . "\n";
    exit;
}

if (!$data || !isset($data['data']['properties'])) {
    writeLog("error", "Missing properties in JSON");
    http_response_code(400);
    echo "⚠️ 缺少必要節點：data.properties\n";
    exit;
}

$props = $data['data']['properties'];
$page_id = $data['data']['id'] ?? null;
writeLog("info", "Page ID: " . ($page_id ?? "NONE"));

// 取欄位
$base_url = $props['付款網址']['url'] ?? null;
$order_id = $props['訂單編號']['rich_text'][0]['text']['content'] ?? null;

writeLog("info", "Extract: OrderID={$order_id}, URL=" . substr($base_url, 0, 30) . "...");

if (!$base_url || !$order_id) {
    writeLog("error", "Missing OrderID or URL");
    http_response_code(400);
    echo "❌ 缺少必要參數：付款網址 或 訂單編號。\n";
    exit;
}

// ────────────────────────────────────────────────────────
// 呼叫 YOURLS 產生短網址
// ────────────────────────────────────────────────────────
$encoded_url = urlencode($base_url);
$y_http = 0;
$y_body = '';
$short_url = shortenURL($encoded_url, $order_id, $y_http, $y_body);

if (!$short_url) {
    writeLog("error", "YOURLS Failed. HTTP: $y_http, Body: $y_body");
    http_response_code(502);
    echo "❌ 產生短網址失敗（YOURLS）。\n";
    exit;
}
writeLog("info", "Short URL Generated: $short_url");

// ────────────────────────────────────────────────────────
// 方案 A：若帶有 page_id，直接 PATCH
// ────────────────────────────────────────────────────────
if ($page_id) {
    $p_http = 0;
    $p_body = '';
    $res = notionPatchByPageId($page_id, $short_url, $p_http, $p_body);

    writeLog("patch-status", "HTTP: $p_http");
    writeLog("patch-response", $p_body);

    // ✅ 改進判斷：如果 HTTP 讀不到但 Body 是成功物件，視為成功
    $is_success = ($p_http >= 200 && $p_http < 300);
    $json_res = json_decode($p_body, true);
    if (isset($json_res['object']) && $json_res['object'] === 'page') {
        $is_success = true;
    }

    if (!$is_success) {
        http_response_code(500);
        echo "❌ Notion 更新失敗（直寫 page_id）。\n";
        exit;
    }

    // Discord Notification
    sendToDiscord($order_id, $short_url, "Direct Patch (PageID)", $p_body);

    http_response_code(200);
    echo "✅ 短網址產生成功：{$short_url}\n";
    echo "🔄 Notion 已更新（page_id 直寫）。\n";
    exit;
}

// 回退：查詢
writeLog("info", "Fallback to Query mode");
$q_http = 0;
$q_body = '';
$p_http = 0;
$p_body = '';
$result = updateNotionFields($order_id, $short_url, $q_http, $q_body, $p_http, $p_body);

if ($result === '__NOT_FOUND__') {
    writeLog("error", "Notion Query Not Found. Body: $q_body");
    http_response_code(422);
    echo "❌ Notion 查無此訂單。\n";
    exit;
}
if ($result === '__PATCH_FAIL__') {
    writeLog("error", "Notion Patch Failed (Fallback). HTTP: $p_http, Body: $p_body");
    http_response_code(500);
    echo "❌ Notion 更新與網址失敗（回退模式）。\n";
    exit;
}

writeLog("success", "Update Success (Fallback)");

// Discord Notification
sendToDiscord($order_id, $short_url, "Fallback Query", $result);

http_response_code(200);
echo "✅ 短網址產生成功：{$short_url}\n";
exit;

// ─────────────────────────── Func ───────────────────────────
function writeLog($type, $msg)
{
    $line = "[" . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents(__DIR__ . '/debug_spm.log', $line, FILE_APPEND);
}

function sendToDiscord($order_id, $short_url, $mode, $notion_response)
{
    if (!defined('DISCORD_WEBHOOK_URL') || empty(DISCORD_WEBHOOK_URL)) {
        return;
    }

    // Truncate Notion response if too long (Discord limit 2000 chars total)
    $snippet = mb_substr($notion_response, 0, 1500);
    if (strlen($notion_response) > 1500)
        $snippet .= "... (truncated)";

    $content = "✅ **Short URL Created!**\n"
        . "**Order ID**: `$order_id`\n"
        . "**Short URL**: $short_url\n"
        . "**Mode**: $mode\n\n"
        . "**Notion Response**:\n"
        . "```json\n" . $snippet . "\n```";

    $data = json_encode(['content' => $content]);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $data,
            'ignore_errors' => true
        ]
    ]);

    file_get_contents(DISCORD_WEBHOOK_URL, false, $ctx);
    writeLog("info", "Sent to Discord");
}


// ─────────────────────────── Functions ───────────────────────────
/** 呼叫 YOURLS 產生短網址（保留舊版習慣：先 urlencode） */
function shortenURL($encoded_url, $order_id, &$http = 0, &$body = '')
{
    $data = "signature=" . urlencode(YOURLS_SIGNATURE)
        . "&action=shorturl"
        . "&url=" . $encoded_url
        . "&title=" . urlencode("短網址：" . $order_id)
        . "&keyword=" . urlencode($order_id)
        . "&format=json";

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $data,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $resp = @file_get_contents(YOURLS_API, false, $ctx);
    $body = $resp === false ? '' : $resp;

    global $http_response_header;
    $http = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $http = (int) $m[1];
    }

    $j = json_decode($body, true);
    if (is_array($j)) {
        if (!empty($j['shorturl']))
            return $j['shorturl'];
        if (!empty($j['link']))
            return $j['link']; // 某些版本用 link
    }
    return null;
}

/** 以 page_id 直接 PATCH 「短網址」 */
function notionPatchByPageId($page_id, $short_url, &$http = 0, &$body = '')
{
    $headers = [
        "Authorization: Bearer " . NOTION_TOKEN,
        "Content-Type: application/json",
        "Notion-Version: " . NOTION_VERSION
    ];
    $url = "https://api.notion.com/v1/pages/$page_id";
    $payload = ["properties" => ["短網址" => ["url" => $short_url]]];

    $ctx = stream_context_create([
        'http' => [
            'method' => 'PATCH',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'ignore_errors' => true
        ]
    ]);

    $res = @file_get_contents($url, false, $ctx);
    $body = $res === false ? '' : $res;

    global $http_response_header;
    $http = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $http = (int) $m[1];
    }
    return $res;
}

/**
 * 回退查詢：嘗試 1) rich_text.equals → 2) title.equals → 3) rich_text.contains
 * 找到第一筆後 PATCH 「短網址」
 * 回傳：
 *  - '__NOT_FOUND__'   查不到
 *  - '__PATCH_FAIL__'  Patch 失敗
 *  - string            Notion Patch 的回應 JSON
 */
function updateNotionFields($order_id, $short_url, &$q_http = 0, &$q_body = '', &$p_http = 0, &$p_body = '')
{
    $headers = [
        "Authorization: Bearer " . NOTION_TOKEN,
        "Content-Type: application/json",
        "Notion-Version: " . NOTION_VERSION
    ];
    $qurl = "https://api.notion.com/v1/databases/" . NOTION_DATABASE_ID . "/query";

    $tries = [
        ["filter" => ["property" => "訂單編號", "rich_text" => ["equals" => $order_id]]], // 常見情況一
        ["filter" => ["property" => "訂單編號", "title" => ["equals" => $order_id]]], // 若實際是 Title
        ["filter" => ["property" => "訂單編號", "rich_text" => ["contains" => $order_id]]], // 放寬比對
    ];

    $pages = [];
    foreach ($tries as $try) {
        $qctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($try),
                'ignore_errors' => true
            ]
        ]);
        $qres = @file_get_contents($qurl, false, $qctx);
        $q_body = $qres === false ? '' : $qres;

        global $http_response_header;
        $q_http = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $q_http = (int) $m[1];
        }
        $pages = json_decode($q_body, true)['results'] ?? [];
        if (!empty($pages))
            break;
    }

    if (empty($pages))
        return '__NOT_FOUND__';

    $page_id = $pages[0]['id'];
    $url = "https://api.notion.com/v1/pages/$page_id";
    $payload = ["properties" => ["短網址" => ["url" => $short_url]]];

    $pctx = stream_context_create([
        'http' => [
            'method' => 'PATCH',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'ignore_errors' => true
        ]
    ]);
    $pres = @file_get_contents($url, false, $pctx);
    $p_body = $pres === false ? '' : $pres;

    $p_http = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $p_http = (int) $m[1];
    }

    if ($pres === false || $p_http < 200 || $p_http >= 300)
        return '__PATCH_FAIL__';
    return $pres;
}
