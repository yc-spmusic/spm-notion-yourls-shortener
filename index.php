<?php
header('Content-Type: text/plain');
const APP_VERSION = '1.1.0';

// ✅ 載入 .env 常數
function loadEnvToConstants($filename = 'shorten_and_post.env')
{
    // 定義搜尋路徑清單
    $paths = [
        '/volume1/web_packages/spm_env/shorten_and_post.env' . $filename, // 1. 優先搜尋 NAS 指定路徑
    ];

    // 2. 加入當前與上層目錄搜尋 (Local 開發用)
    $dir = __DIR__;
    while (true) {
        $paths[] = $dir . '/' . $filename;
        $parent = dirname($dir);
        if ($parent === $dir)
            break;
        $dir = $parent;
    }

    // 3. 依序檢查並載入
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '='))
                    continue;
                [$key, $value] = explode('=', $line, 2);
                if (!defined($key))
                    define(trim($key), trim($value));
            }
            return; // 找到並載入後結束
        }
    }
}
loadEnvToConstants();

// ✅ 解析 Notion webhook 的 JSON 輸入
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['data']['properties'])) {
    http_response_code(400);
    echo "⚠️ 無效的 JSON 結構或缺少 properties。\n";
    exit;
}

$props = $data['data']['properties'];

// ✅ 抓欄位
$base_url = $props['付款網址']['url'] ?? null;
$order_id = $props['訂單編號']['rich_text'][0]['text']['content'] ?? null;

// ✅ 判斷必要欄位是否存在
if (!$base_url || !$order_id) {
    http_response_code(400);
    echo "❌ 缺少必要參數（付款網址 或 訂單編號）。\n";
    exit;
}

$existing_url = $props['產生短網址']['url'] ?? null;

if ($existing_url) {
    echo "⚠️ 已存在短網址：$existing_url，略過產生但可保留 Notion 補寫機會。\n";
    // 不 return，讓程式繼續走 YOURLS 查詢 + Notion 補寫
}



// ✅ 從付款網址中抓 key（可選）
$key = null;
if (preg_match('/[?&]key=([^&]+)/', $base_url, $matches)) {
    $key = $matches[1];
}

// ✅ 組合完整網址與編碼
$full_url = $base_url;
$encoded_url = urlencode($full_url);

// ✅ 呼叫 YOURLS
$short_url = shortenURL($encoded_url, $order_id);

if ($short_url) {
    $result = updateNotionFields($order_id, $short_url);
    echo "✅ 短網址產生成功：$short_url\n";
    echo "🔄 Notion 已更新：\n" . $result . "\n";
} else {
    echo "❌ 產生短網址失敗。\n";
}

// --- YOURLS ---
function shortenURL($encoded_url, $order_id)
{
    $data = "signature=" . urlencode(YOURLS_SIGNATURE)
        . "&action=shorturl"
        . "&url=" . $encoded_url
        . "&title=" . urlencode("短網址：" . $order_id)
        . "&keyword=" . urlencode($order_id)
        . "&format=json";

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $data
        ]
    ];

    $context = stream_context_create($opts);
    $result = file_get_contents(YOURLS_API, false, $context);
    $json = json_decode($result, true);
    return $json['shorturl'] ?? null;
}

// --- Notion ---
function updateNotionFields($order_id, $short_url)
{
    $query_url = "https://api.notion.com/v1/databases/" . NOTION_DATABASE_ID . "/query";
    $query_payload = [
        "filter" => [
            "property" => "訂單編號",
            "rich_text" => ["equals" => $order_id]
        ]
    ];

    $headers = [
        "Authorization: Bearer " . NOTION_TOKEN,
        "Content-Type: application/json",
        "Notion-Version: " . NOTION_VERSION
    ];

    $query_opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($query_payload)
        ]
    ];

    $query_context = stream_context_create($query_opts);
    $query_result = file_get_contents($query_url, false, $query_context);
    $pages = json_decode($query_result, true)['results'] ?? [];

    if (count($pages) === 0)
        return "查無此訂單 (order_id: $order_id)";

    $page_id = $pages[0]['id'];
    $url = "https://api.notion.com/v1/pages/$page_id";
    $payload = [
        "properties" => [
            "短網址" => [
                "url" => $short_url
            ]
        ]
    ];

    $patch_opts = [
        'http' => [
            'method' => 'PATCH',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($payload)
        ]
    ];

    $patch_context = stream_context_create($patch_opts);
    return file_get_contents($url, false, $patch_context);
}
