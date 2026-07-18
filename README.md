# PartnerOps

> 顧問團隊與客戶共用的服務營運平台（Partner Service Operations Portal）

PartnerOps 是一個可部署、可稽核的 Symfony 後端作品：顧問團隊可集中處理客戶需求、內外部討論、服務額度與工時，客戶則只會看到屬於自己組織且允許公開的內容。核心流程採 Twig 伺服器端渲染，即使停用 JavaScript 仍可完成工作。

## 產品重點 / Highlights

- 需求從建立、指派、優先級調整到狀態轉換的完整工作流
- 客戶隔離與 internal/client-visible 討論邊界
- 以整數分鐘計算服務額度、剩餘量與超額使用
- 不可變更的工時與 audit event，保留操作脈絡
- Client-scoped JSON API、雜湊 token、rate limiting 與 24 小時 idempotency replay
- 獨立 liveness/readiness probe、PostgreSQL migration 與 production image
- PostgreSQL-backed PHPUnit、Twig/YAML/container lint、Composer audit 與 axe smoke gate

完整行為規格請見 [feature spec](specs/001-partner-operations/spec.md)，HTTP 介面以 [OpenAPI 3.1 contract](specs/001-partner-operations/contracts/openapi.yaml) 為準。

## 架構 / Architecture

```mermaid
flowchart LR
    Browser["Browser · Twig / HTML"] --> Web["Symfony Web Controllers"]
    Client["Client Integration · JSON"] --> API["/api/v1 Controllers"]
    Web --> Core["Application Services · Validation · Voters"]
    API --> Core
    Core --> Doctrine["Doctrine ORM + Migrations"]
    Doctrine --> PostgreSQL[("PostgreSQL 16")]
    Core --> Audit["Immutable Audit Events"]
```

Web 與 API 僅在 HTTP adapter 分流，共用同一套 service、repository、validation 與 authorization policy，避免兩份商業規則逐漸不一致。

| Layer | Choice |
|---|---|
| Runtime | PHP 8.4, Symfony 7.4 LTS, Apache |
| UI | Twig, semantic HTML, modern CSS, AssetMapper |
| Data | PostgreSQL 16, Doctrine ORM / Migrations |
| Security | Symfony Security, CSRF, voters, hashed opaque API tokens |
| Verification | PHPUnit 13, PHPStan level 8, Symfony linters, Composer audit, Redocly OpenAPI lint, axe-core CLI |
| Delivery | Docker multi-stage image, Compose, GitHub Actions |

## 5 分鐘啟動 / Quick demo

需求：Docker Desktop/Engine 27+ 與 Compose v2。

```bash
docker compose up --build -d db app
docker compose exec app php bin/console doctrine:database:drop --force --if-exists
docker compose exec app php bin/console doctrine:database:create
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:fixtures:load --group=AppFixtures --append --no-interaction
curl --fail http://localhost:8080/health/ready
```

開啟 <http://localhost:8080/login>：

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@partnerops.test` | `PartnerOps!2026` |
| Team member | `agent@partnerops.test` | `PartnerOps!2026` |
| Acme client | `client@acme.test` | `PartnerOps!2026` |
| Globex client | `client@globex.test` | `PartnerOps!2026` |

這些帳密及 fixture token 僅供合成資料的本機展示。上述流程每次都會先完整重建本機 `partnerops` 資料庫，因此可以安全重跑；這也是必要步驟，因為 append-only audit log 會拒絕 fixtures 預設 purge 所發出的 `DELETE`。不要單獨省略 `--append` 執行 fixture，也絕對不要在正式環境執行 drop 或 fixture；production migration 應依下方 release job 流程單獨執行。容器啟動刻意不自動執行這些命令，避免部署時發生未審核的資料變更。

更完整的瀏覽器、隔離、額度與 API 驗收流程收錄於 [quickstart validation guide](specs/001-partner-operations/quickstart.md)。

## 常用指令 / Commands

```bash
# Backend release gate
docker compose exec app composer verify

# API contract（Node.js 22+）
npm ci && npm run api:lint

# Accessibility smoke（在 app 已啟動時，Node.js 22+）
npm ci
npm run a11y

# Health probes
curl --fail http://localhost:8080/health/live
curl --fail http://localhost:8080/health/ready

# Cleanup local synthetic data
docker compose down --volumes
```

CI 使用 PostgreSQL 16 service 執行 migration/schema 驗證、lint、測試與 production asset compile，最後再建置 production Docker target。axe 會掃描實際啟動的 public login page；登入後的 client isolation 與語意結構由 HTTP/DOM tests 驗證，避免把登入重導頁誤當成受保護頁面的掃描結果。

## API

公開介面目前為：

- `GET /health/live`
- `GET /health/ready`
- `POST /api/v1/requests`
- `GET /api/v1/requests/{publicId}`

Authentication、idempotency header、Problem Details 錯誤格式與 schema 範例請直接參閱 [OpenAPI contract](specs/001-partner-operations/contracts/openapi.yaml)。可重現的 `curl` 範例位於 [quickstart](specs/001-partner-operations/quickstart.md#6-validate-the-json-contract-and-idempotency)。
請求詳情的公開留言以 `commentsPage` 分頁，每頁 50 筆；回應內 `commentsPagination` 提供總筆數與總頁數，因此長期案件不會靜默遺失舊討論。

## 安全與資料完整性 / Security

- Client ownership 永遠由登入身份或 API credential 推導，不接受 request body 的 `client_id`
- Collection query scope、object voter 與 service guard 共同防止 cross-client access
- Browser mutation 使用 CSRF；API secret 僅保存 keyed digest，plaintext 只顯示一次
- PostgreSQL constraint 負責唯一性、正數分鐘、額度區間不重疊與 idempotency concurrency
- Optimistic locking 防止瀏覽器長時間停留造成 silent overwrite
- Audit metadata 採 allow-list，不保存 token、密碼、header、request body 或私密留言
- `health/live` 不依賴資料庫；`health/ready` 的交易內 `SELECT 1` 有 2 秒 PostgreSQL statement timeout，production image 另以 3 秒 `PGCONNECT_TIMEOUT` 限制建立連線

## 效能驗證 / Performance

只在可丟棄的本機資料庫載入 performance fixture：

```bash
docker compose exec app php bin/console doctrine:fixtures:load --group=performance --append --no-interaction
./scripts/benchmark.sh http://localhost:8080
```

獨立的 `performance` fixture 會附加 10,000 筆可辨識的合成請求；重複執行不會再次插入。腳本會以 demo team member 登入，暖機後量測 dashboard 與 `in_progress` 篩選清單第一頁的 HTTP time-to-first-byte p95；預設各 30 次、門檻 750 ms。路徑、帳密、樣本數與門檻皆可透過 `BENCH_*` 環境變數覆寫。

## Production image

```bash
docker build --target production -t partnerops:latest .
```

Production target 使用 non-root `www-data`、root-owned application code、OPcache、Apache security headers 與獨立 writable `var/`。部署時至少注入以下 secrets/config：

- `APP_ENV=prod`、`APP_DEBUG=0`
- 高熵 `APP_SECRET`
- PostgreSQL `DATABASE_URL`
- 與資料庫分開保管的高熵 `API_TOKEN_PEPPER`
- `APP_TIMEZONE=Asia/Taipei` 與 HTTPS 對外 `DEFAULT_URI`
- 僅列出實際 edge/load balancer 位址或 CIDR 的 `SYMFONY_TRUSTED_PROXIES`（逗號分隔）

TLS/HSTS、edge rate limiting、secret rotation、備份與 migration rollout 應由部署平台處理。application container 應只接受受控 edge 的私有網路流量；edge 必須移除外部傳入值後重寫 `X-Forwarded-For`、`X-Forwarded-Proto` 與 `X-Forwarded-Port`。例如 edge 位於 `10.20.0.0/24` 時設定 `SYMFONY_TRUSTED_PROXIES=10.20.0.0/24`；不要使用 `0.0.0.0/0`、所有 private ranges 或未受控的 `REMOTE_ADDR`。正式環境 session cookie 無條件標記 `Secure`，未列入信任的來源所送 forwarded headers 不會影響 client IP 或 scheme 判斷。

Production image 已將 libpq `PGCONNECT_TIMEOUT` 預設為 3 秒；若不用此 image 部署，必須在 PHP/Apache 的實際 process environment 設定等效上限（只寫在未匯出至 process 的 dotenv 檔不保證 libpq 會讀取）。每次 release 先以一次性 job 明確執行 `php bin/console doctrine:migrations:migrate --no-interaction`，成功後再切換 application traffic；production container 本身不會修改 schema。

## Scope

第一版刻意不加入 SPA、queue、Redis、billing、email、attachment、webhook 與 realtime。當量測結果或明確產品需求需要時再擴充，避免增加目前無法驗證的營運成本。

License: [MIT](LICENSE).
