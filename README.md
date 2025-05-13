# YOURLS + Notion Webhook Shortener

這是一個 PHP 腳本，接收 Notion Webhook 自動產生短網址，並更新指定的 Notion 資料庫欄位。

---

## 🔧 功能說明

- ✅ 透過 Notion webhook 傳入 JSON，自動擷取「付款網址」與「訂單編號」
- ✅ 若已存在短網址欄位，跳過產生但仍可觸發補寫（YOURLS 查詢 + Notion 補寫）
- ✅ 呼叫 YOURLS API 建立短網址（使用 `訂單編號` 為 keyword）
- ✅ 自動查詢並更新 Notion 資料庫欄位「短網址」

---

## 🧩 部署環境（本專案 v1.0 測試環境）

- 📦 Synology NAS：DS423+
- 🖥️ DSM 版本：7.2.2-72806
- 🌐 Web 伺服器：Web Station + Nginx
- 🐘 PHP 版本：8.1（因 DSM 限制暫不使用 8.2）
- 🔗 YOURLS 安裝：使用 HTTPS 子網域  
  ⚠️ 注意：YOURLS 的 `config.php` 中 `YOURLS_SITE` 必須設為 `http://`，否則樣式表將無法載入

---

## 🚀 Notion Webhook 設定方式

- 採用 Notion 自帶 `Send Webhook` 功能
- 勾選所有欄位傳送
- JSON 結構中：
  - `付款網址` → 取自：`properties.付款網址.url`
  - `訂單編號` → 取自：`properties.訂單編號.rich_text[0].text.content`

---

## 🧠 常見錯誤與踩雷筆記

### 1. WooCommerce 訂單連結中 `key` 無法成功產生短網址

WooCommerce 通常會產生這種格式的連結：
```
https://storypathmusic.com/checkout/order-pay/6830/?pay_for_order=true&key=wc_order_xxx
```

若不處理 `key`，YOURLS 接收到的 URL 會中斷在 `?pay_for_order=true`，導致樣式錯誤或短網址無效。

✅ 解法：

```php
// 抓出 key
if (preg_match('/[?&]key=([^&]+)/', $base_url, $matches)) {
    $key = $matches[1];
}

// 使用完整網址產生短網址
$encoded_url = urlencode($base_url);
