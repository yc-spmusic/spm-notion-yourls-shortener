# 🔧 Synology DSM + Nginx YOURLS Rewrite 設定筆記
在 Synology NAS（Web Station + Nginx）環境中，預設的 Nginx 設定**並不支援 rewrite 或短網址轉址功能**，這將導致 YOURLS 無法正確處理 `/abc123` 類型的短網址。

本教學提供一個**實測成功且不會被 DSM 更新覆蓋**的永久解法，協助你啟用 YOURLS 的 rewrite 功能。

---

## ⚠️ 問題說明

在 Synology DSM 使用 Web Station + Nginx 時，YOURLS 短網址預設會失效，原因如下：

- YOURLS 依賴 Nginx 的 `try_files` 將短網址（如 `/abc123`）重新導向至 `yourls-loader.php`
- Web Station 預設未啟用 rewrite 或 router 支援
- 即便修改 `/etc/nginx/app.d/server.webstation*` 設定，**也會在 DSM 重啟或套件更新時被還原**

---

## ✅ 解法：自訂 `conf.d` 資料夾中的 Rewrite 設定

### 📂 1. 建立 Rewrite 設定資料夾

```bash
cd /usr/local/etc/nginx/conf.d/
mkdir 04a20e9c-97f4-4e9c-976a-bca897c95ae2
cd 04a20e9c-97f4-4e9c-976a-bca897c95ae2
```

> 📌 此資料夾名稱可自由定義，但建議使用唯一 UUID 避免被其他服務誤判為系統設定。

---

### ✏️ 2. 建立 `user.conf` 並寫入 Rewrite 規則

```bash
sudo vim user.conf
```

內容如下：

```nginx
location / {
    try_files $uri $uri/ /yourls-loader.php?$args;
}
```

> 適用於：YOURLS 安裝於 `/web/yourls`，並使用子網域根目錄（如 `https://link.storypathmusic.com/abc123`）

---

### 🔁 3. 套用設定並重新載入 Nginx

```bash
synow3tool --gen-all && systemctl reload nginx
```

---

### 🧪 4. 測試短網址

訪問：

```
https://link.storypathmusic.com/abc123
```

若能成功轉址至原始長網址，表示 Rewrite 設定已生效 ✅

---

## 📌 備註與推薦

- 此方法為 **Synology DSM 下不會被重啟/更新覆蓋的永久 rewrite 設定**
- 對 YOURLS 的 `yourls-loader.php` 模式完全相容
- 推薦納入 YOURLS 部署初始步驟
