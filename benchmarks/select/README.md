# MySQL 8 SELECT → JSON benchmark

比較 `laminas/laminas-db`、本地 `php-entity` 及 `mathsgod/light-db` 的 SELECT → JSON 完整成本。使用獨立 MySQL 8.0 容器及暫存資料庫，預設建立 10,000 筆固定假資料（可指定筆數）；結束後自動移除容器與資料。

```bash
cd benchmarks/select
./run.sh       # 15 rounds，90 independent PHP workers
./run.sh 30    # 30 rounds
./run.sh 15 100000  # 15 rounds，100,000 records
```

需要 Docker Compose、Composer、PHP >= 8.2、pdo_mysql。預設使用本機 port 33388，請先確保未被佔用。依賴安裝在本目錄，不改動主專案的 Composer 依賴。`composer.lock` 固定 Laminas 版本；php-entity/php-linq/light-db 是本地目錄 symlink，使用目前工作目錄的程式。light-db 路徑為 `../../../../light/light-db`（相對本目錄）；在其他目錄結構執行時，需調整 composer.json 的 path repository 並重新 composer update。

三者執行無條件 `SELECT * FROM select_records`，讀取全部欄位並完整 materialize 所有資料。筆數可透過第二個參數或 `BENCH_ROWS` 環境變數指定，預設 10,000；驗證筆數及 ID 總和亦按指定數量計算。表有 INT 主鍵、name、email、age、DECIMAL balance、DATETIME created_at 和 128 字元 payload。Laminas 使用 Sql builder、prepared statement、陣列結果；php-entity 使用 `set(SelectRecord::class)->asNoTracking()->toArray()`，回傳 Entity；light-db 使用 `LightSelectRecord::Query()->toArray()`，回傳 Model，透過 `SetAdapter()` 注入同樣設定的 PDO，略過 `.env`／persistent connection factory。目前測試會再產生完整 JSON：Laminas 陣列直接 json_encode；light-db 透過原有 JsonSerializable（包含其 jsonSerialize 內的 metadata/JSON 處理）；php-entity 透過 Entity 的 JsonSerializable 匯出內部資料及已初始化的 public properties，再 json_encode。早期測試使用手動逐欄匯出，保留於下方歷史紀錄。計時涵蓋 Entity/Model hydration、輸出資料準備及 JSON 編碼，直到回應字串完整產生；不包含 HTTP 傳輸、壓縮或瀏覽器解析。先前歷史測試只計 SELECT/materialization，不能與目前總時間直接比較。

- `cold`：同一 worker 的首次查詢，包含查詢所需 class loading、SQL 建立及 ORM schema discovery。
- `warm`：同一 worker 先完整查詢及產生 JSON 一次，再釋放結果，之後量度第二次查詢；php-entity metadata 已快取。
- 每次樣本使用新的 PHP process，CLI OPcache 關閉。autoload、連線建立及 client/context 建構均在計時外；每輪隨機安排六個測試，逐個執行。
- `Median ms` / `P95 ms`：從建立查詢到完整 JSON 字串產生的 wall time，以 `hrtime()` 量度，P95 使用線性插值。
- `Retained MiB`：查詢結果及 JSON 字串仍在記憶體時的 PHP used-memory 增量。
- `Peak +MiB`：重設 peak 後，相對查詢前的 PHP peak used-memory 增量。包括 buffered PDO 結果、hydration、匯出陣列及 JSON 編碼暫存的最高使用量。
- `results.json` 保存全部樣本、版本、摘要，以及每次查詢的 PHP allocator peak、fetch_ms、json_ms、JSON bytes 與 checksum。這些 PHP memory 指標並非 OS RSS，也不包括 MySQL server 的 RAM。
- 驗證工作在計時與記憶體量度後進行：檢查筆數、ID 總和，並把所有欄位轉為字串及按 ID 排序後比較 SHA-256，確保三者讀到相同內容；另外比較最終 JSON 字串 SHA-256，確保欄位、值、JSON 型別及輸出內容完全一致。驗證改為讀取已產生的 JSON，不再依賴計時外的 Model 屬性存取。

這裡的 cold 只指 PHP 首次查詢，MySQL buffer pool 與 OS cache 並未清空。MySQL 資料放在 tmpfs，適合比較 PHP 端讀取成本，不能代表 production 磁碟 I/O。15 個樣本的 P95 僅供初步參考；結果受主機負載影響。

## 首次兩者實測

2026-11-02，PHP 8.5.12、MySQL 8.0.46、laminas-db 2.22.0；15 輪，合共 60 次獨立 worker，所有結果 checksum 相同。

| Library | Mode | Median ms | P95 ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| Laminas | cold | 23.695 | 25.443 | 7.784 | 9.861 |
| php-entity AsNoTracking | cold | 56.949 | 59.957 | 8.742 | 12.584 |
| Laminas | warm | 17.201 | 19.938 | 7.265 | 9.342 |
| php-entity AsNoTracking | warm | 50.957 | 53.627 | 8.486 | 12.402 |

本次暖查詢 php-entity 耗時約為 Laminas 的 2.96 倍，PHP 峰值增量約為 1.33 倍。這是上述資料形狀、結果類型與測試環境下的結果。


## 加入 light-db 的三者實測

2026-11-02，PHP 8.5.12、MySQL 8.0.46；15 輪，共 90 次獨立 worker，全部資料驗證一致。light-db 使用本機 dev-main `5cc2226`，illuminate/collections 13.39.0，Laminas DB 2.22.0。

| Library | Mode | Median ms | P95 ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| laminas | cold | 23.162 | 26.057 | 7.811 | 9.888 |
| php-entity | cold | 56.641 | 63.491 | 8.764 | 12.606 |
| light-db | cold | 47.828 | 58.245 | 15.131 | 17.202 |
| laminas | warm | 16.754 | 19.123 | 7.265 | 9.342 |
| php-entity | warm | 50.270 | 55.992 | 8.486 | 12.402 |
| light-db | warm | 27.031 | 29.177 | 13.003 | 15.074 |

本次暖查詢 light-db 耗時約為 Laminas 的 1.61 倍、php-entity 的 0.54 倍；PHP 峰值記憶體增量則約為 Laminas 的 1.61 倍、php-entity 的 1.22 倍。light-db 在本次 Model 讀取比較中較 php-entity 快，但使用較多記憶體。首次兩者測試與本次結果應分開看，跨版本或主機不保證相同比例。


## Column 類型大小寫快取 A/B 測試

將 `strtolower()` 移至 metadata 建立時，以修改前 HEAD 的 Column 及修改後 Column 比較。使用 PHP `auto_prepend_file` 預先載入各版本，其他程式相同，不修改工作目錄中的來源檔；同一個 MySQL 容器、30 輪隨機交錯執行 cold/warm × before/after，共 120 次獨立 process。全部 10,000 筆結果 checksum 一致。

| 模式 | 修改前中位數 ms | 修改後中位數 ms | 耗時減少 |
| --- | ---: | ---: | ---: |
| cold | 55.424 | 54.545 | 1.58% |
| warm | 49.312 | 48.504 | 1.64% |

本次改善約 1.6%，暖查詢 PHP peak 增量維持 12.402 MiB。屬小幅改善，未足以解釋或消除與 Laminas 的差距；跨次執行仍會有負載波動。完整樣本保存在 `results-normalized-type-ab.json`。


## 100,000 筆實測

執行 `./run.sh 15 100000`；PHP 8.5.12、MySQL 8.0.46，使用已快取 Column 正規化類型的 php-entity。15 輪，共 90 次獨立 worker，每次 100,000 筆，全部 checksum 一致。

| Library | Mode | Median ms | P95 ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| laminas | cold | 202.078 | 205.738 | 72.732 | 93.118 |
| php-entity | cold | 521.672 | 538.034 | 85.548 | 123.412 |
| light-db | cold | 321.661 | 361.090 | 132.426 | 152.806 |
| laminas | warm | 158.236 | 163.801 | 72.187 | 92.572 |
| php-entity | warm | 468.853 | 485.591 | 84.394 | 122.258 |
| light-db | warm | 249.594 | 295.629 | 129.423 | 149.803 |

暖查詢 php-entity 耗時約為 Laminas 的 2.96 倍、light-db 的 1.88 倍。相對先前同樣快取類型版本的 10,000 筆測試，三者暖查詢時間增加約 8.8–9.5 倍，PHP peak 記憶體增量增加約 9.8–9.9 倍；兩次執行時間不同，比例僅供參考。完整樣本保存在 `results-100000.json`，測試容器已自動移除。


## ReflectionClass 快取 A/B 測試（100,000 筆）

修改 `EntityMapping`，在建構 metadata 時保留原本用於驗證的 ReflectionClass，hydrate 重用它，維持 newInstanceWithoutConstructor 行為。測試新舊 EntityMapping，其餘程式一致（包括已快取大小寫的 Column）；透過 auto_prepend_file 載入指定版本，同一 MySQL 容器、30 輪隨機交錯 cold/warm × before/after，共 120 次獨立 process，全部 100,000 筆資料 checksum 一致。

| 模式 | 修改前中位數 ms | 修改後中位數 ms | 耗時減少 |
| --- | ---: | ---: | ---: |
| cold | 522.120 | 514.827 | 1.40% |
| warm | 478.098 | 469.213 | 1.86% |

暖查詢 PHP peak 增量保持 122.258 MiB。本次暖查詢省約 8.89 ms（1.86%），首次查詢省約 7.29 ms（1.40%）；反映重複建立 ReflectionClass 有成本，但並非整體差距的主要來源。這項變更仍逐筆建立 Entity、轉換欄位及寫入屬性，未測試 prototype clone 或 FETCH_CLASS。完整樣本：`results-reflection-cache-ab-100000.json`。


## SELECT → JSON，10,000 筆實測（目前測試範圍）

15 輪共 90 個獨立 worker；使用目前已快取 Column 類型及 ReflectionClass 的 php-entity。全部 JSON 字串逐 byte checksum 一致，每次輸出 2675573 bytes，均含 10,000 筆、七個欄位。

| Library | Mode | 總時間中位數 ms | P95 ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| laminas | cold | 30.596 | 35.424 | 10.366 | 10.366 |
| php-entity | cold | 71.104 | 81.479 | 11.320 | 15.160 |
| light-db | cold | 75.297 | 80.861 | 17.710 | 17.711 |
| laminas | warm | 23.417 | 26.043 | 9.820 | 9.820 |
| php-entity | warm | 61.414 | 65.471 | 11.041 | 14.881 |
| light-db | warm | 52.254 | 54.410 | 15.558 | 15.559 |

暖查詢各階段中位數（分別取中位數，兩者之和不一定等於總中位數）：

| Library | SELECT / materialization ms | 匯出／JSON 編碼 ms |
| --- | ---: | ---: |
| laminas | 16.677 | 6.744 |
| php-entity | 46.227 | 15.285 |
| light-db | 27.393 | 24.927 |

這組數字包含正常 JSON 輸出所需處理；light-db 使用原有 jsonSerialize，並非強制逐欄呼叫 __get。php-entity 使用顯式七欄輸出，避免 private data 被直接 json_encode 成空物件。完整樣本保存為 `results-json-10000.json`。測試容器已移除；下次 ./run.sh 預設仍建立 10,000 筆。


## MySQL FETCH_CLASS 實作後（10,000 筆 SELECT → JSON）

MySQL 原生 prepare、非字串化 fetch、相容 schema-driven Entity 的 NoTracking table 查詢使用 PDO::FETCH_CLASS，避免建立完整原始 row 陣列及逐欄 convert；只對 metadata 標記的 boolean 欄位進行轉換。建立物件後仍附加關聯 context。帶建構子、宣告 mapped properties、自訂 setter、BIT/YEAR、raw SQL 等情況走原有 hydration。MySQL schema 的 TINYINT(1) 依約定轉 bool，NULL 保留；此規則在 tracking 與 fallback 路徑亦一致。

15 輪、90 次獨立 worker，所有 JSON 完全一致。使用 PHP 8.5.12、MySQL 8.0.46；輸出 2675573 bytes。

| Library | Mode | 總時間中位數 ms | P95 ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| laminas | cold | 30.673 | 32.356 | 10.366 | 10.366 |
| php-entity | cold | 45.832 | 49.263 | 11.333 | 15.172 |
| light-db | cold | 76.373 | 89.032 | 17.710 | 17.711 |
| laminas | warm | 23.335 | 25.332 | 9.820 | 9.820 |
| php-entity | warm | 37.321 | 39.670 | 11.041 | 14.881 |
| light-db | warm | 53.180 | 57.123 | 15.558 | 15.559 |

另做 30 輪新舊實作隨機交錯 A/B：相同資料庫、相同 JSON benchmark，透過 auto_prepend_file 載入修改前四個類別，其餘程式及 Column/SchemaReader 相同。120 次 worker 的 JSON 全部一致。

| 模式 | 修改前中位數 ms | FETCH_CLASS 中位數 ms | 耗時減少 |
| --- | ---: | ---: | ---: |
| cold | 70.614 | 46.627 | 33.97% |
| warm | 62.051 | 37.142 | 40.14% |

暖查詢 SELECT/materialization 中位數由 46.439 ms 降至 22.195 ms；JSON 階段約 15 ms。總峰值增量維持 14.881 MiB，因最終匯出陣列及 JSON 仍需記憶體。這份效能資料的表沒有 TINYINT(1)；boolean、NULL、非零值、大整數、decimal、JSON 字串、建構子及 typed property fallback、raw SQL 與 BIT/YEAR 行為由 MySQL 整合測試驗證。

驗證：65 項 MySQL 整合測試、441 assertions 全數通過；一般測試 265 項（162 項因缺少 SQLite 而跳過）。完整效能樣本：`results-json-fetch-class-10000.json`、`results-fetch-class-ab-json-10000.json`。測試容器均已清理。


## Entity JsonSerializable：合併內部資料與 public properties

Entity 實作 JsonSerializable，先取內部 data，再用 get_mangled_object_vars 取得已初始化的屬性，排除名稱含 NUL 的 private/protected 成員，只合併 public 值（public 優先）。未初始化 typed properties 與 static properties 不會輸出，relation context 與 mapping 亦不輸出。benchmark 改為三者直接 json_encode，包含全部序列化成本；輸出與修改前逐 byte 一致。

10,000 筆、15 輪三者比較：

| Library | Mode | 總時間中位數 ms | P95 ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| laminas | cold | 31.965 | 42.750 | 10.366 | 10.366 |
| php-entity | cold | 43.367 | 54.354 | 14.918 | 14.919 |
| light-db | cold | 81.460 | 89.533 | 17.710 | 17.711 |
| laminas | warm | 24.056 | 27.520 | 9.820 | 9.820 |
| php-entity | warm | 34.428 | 44.994 | 14.627 | 14.627 |
| light-db | warm | 55.453 | 59.127 | 15.558 | 15.559 |

30 輪交錯 A/B 比較手動逐欄匯出與直接 JsonSerializable：相同 Entity 實作、相同 FETCH_CLASS 查詢，只有 benchmark 的 JSON 匯出方式不同，120 個 worker 的完整 JSON 字串 checksum 一致。

| 模式 | 手動匯出總中位數 ms | JsonSerializable 總中位數 ms | 耗時減少 |
| --- | ---: | ---: | ---: |
| cold | 47.936 | 42.875 | 10.56% |
| warm | 37.906 | 33.405 | 11.87% |

暖查詢 JSON 階段中位數由 15.240 ms 降至 10.280 ms。PHP peak 增量由 14.881 降至 14.627 MiB；但 Retained 記憶體增量由先前約 11.041 上升至 14.627 MiB，表示此版本改善時間與少量 peak，未改善結果仍存活時的記憶體佔用。

驗證：一般測試 268 項、249 assertions（162 項因缺少 SQLite 跳過），MySQL 整合測試 65 項、441 assertions 全數通過。新增測試涵蓋 custom/inherited/mapped public 屬性、public 優先、NULL、未初始化 typed properties、private/protected/static 排除、同名 data public 屬性，以及已附加 context 的 JSON 輸出。

完整樣本：`results-json-serializable-10000.json`、`results-json-serializable-ab-10000.json`。測試容器均已移除。


## 純 PDO 對照測試

另有不依賴 ORM 的 `pdo-benchmark.php`，執行 `./run-pdo.sh 30 10000`，比較 FETCH_ASSOC/FETCH_CLASS 的 fetch-only 及 SELECT → JSON。方法與實測見 [PDO-README.md](PDO-README.md)。
