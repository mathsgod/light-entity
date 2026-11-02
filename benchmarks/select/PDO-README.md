# 純 PDO：FETCH_ASSOC vs FETCH_CLASS

不載入 Composer、ORM 或其他套件。使用相同 MySQL 8 表、10,000 筆固定假資料、七個欄位；完整讀入結果。

```bash
cd benchmarks/select
./run-pdo.sh             # 30 rounds，10,000 records
./run-pdo.sh 30 10000
```

需要 PHP pdo_mysql 和 Docker Compose，使用本機 port 33388。獨立建立測試容器，結束後移除容器及資料。

- `FETCH_ASSOC`：`fetchAll(PDO::FETCH_ASSOC)`。
- `FETCH_CLASS`：`fetchAll(PDO::FETCH_CLASS, PdoBenchmarkRecord::class)`；class 宣告七個未指定型別的 public properties，沒有建構子、魔術 setter、JsonSerializable 或 ORM context。
- 使用相同 `SELECT * FROM select_records ORDER BY id`、native prepares、非字串化 fetch、buffered query。
- 目前八組案例：四個 modes × 只 SELECT/fetch 或 SELECT/fetch/JSON；最初兩者結果保留於下方。每個樣本都是新的 PHP process，先做一次相同 workload 暖機，釋放結果後開始量度；每輪隨機交錯、逐個執行，CLI OPcache 關閉，連線建立排除在計時外。
- `Total ms`：包含 prepare、execute、完整 fetch，JSON workload 額外包含 json_encode。`Fetch ms` 單獨量度 fetchAll 與 closeCursor；`JSON ms` 為編碼階段。分段各自取中位數，加總可能與總中位數略有不同。
- PHP RAM 指標不是 OS RSS，亦不包括 MySQL server RAM；peak 在計時前重設，驗證與 checksum 在時間/RAM 快照之後才進行。JSON workload 包含 JSON 字串存活時的記憶體。
- 每次驗證 10,000 個連續 ID，並比較完整 JSON 字串 SHA-256，確保欄位、值、型別與次序一致。

## 最初兩者結果

PHP 8.5.12、MySQL 8.0.46，30 輪共 120 個 worker；全部結果一致，JSON 每次 2675573 bytes。

| Mode | Workload | Total median ms | P95 ms | Fetch median ms | JSON median ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| assoc | fetch | 15.100 | 16.775 | 4.701 | 0.001 | 7.286 | 9.333 |
| class | fetch | 16.139 | 17.378 | 5.447 | 0.001 | 5.226 | 7.273 |
| assoc | json | 22.214 | 24.178 | 4.641 | 6.680 | 9.841 | 9.841 |
| class | json | 22.275 | 24.151 | 5.296 | 6.346 | 7.781 | 7.781 |

只 SELECT/fetch 時，FETCH_CLASS 總時間多約 1.039 ms（6.88%）。SELECT → JSON 總時間差約 0.061 ms（0.27%），本次可以視為相近。

結論僅適用於這種簡單 public-property class，不能等同 Entity（魔術屬性、關聯 context、自訂 serializer）的成本；這個測試顯示單純 FETCH_CLASS 並不必然帶來之前 ORM 比較中那種明顯差距。完整樣本：`results-pdo-10000.json`。


## FETCH_FUNC 實驗（10,000 筆）

目前純 PDO benchmark 增加兩組，合共四種模式、兩種 workload：

- `assoc`：FETCH_ASSOC 陣列。
- `class`：FETCH_CLASS，七個已宣告的 public properties。
- `magic`：FETCH_CLASS，逐欄透過 __set 寫入 PdoBenchmarkBag 的 private data。
- `func`：FETCH_FUNC，每列一次 callback，array_combine 欄名與值後，由同一個 PdoBenchmarkBag 一次保存完整 row。欄名從 statement metadata 讀取，成本亦在量度內。

`magic` 與 `func` 使用相同類別及 jsonSerialize，兩者均無 ORM context；方便比較逐欄 setter 與 bulk assignment。

30 輪共 240 個 worker，全部 JSON 一致。

| Mode | Workload | Total median ms | Fetch median ms | JSON median ms | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| assoc | fetch | 15.050 | 4.705 | 0.001 | 9.333 |
| class | fetch | 15.587 | 5.408 | 0.001 | 7.273 |
| magic | fetch | 20.658 | 10.420 | 0.001 | 10.096 |
| func | fetch | 18.794 | 8.493 | 0.001 | 10.102 |
| assoc | json | 22.121 | 4.653 | 6.719 | 9.841 |
| class | json | 22.253 | 5.378 | 6.514 | 7.781 |
| magic | json | 29.681 | 10.514 | 8.457 | 10.609 |
| func | json | 26.221 | 8.516 | 7.185 | 10.604 |

完整樣本：`results-pdo-fetch-func-10000.json`。

## 實際 php-entity 的 FETCH_CLASS / FETCH_FUNC 交錯 A/B（早期 prototype）

```bash
python3 compare-fetch-func.py 30
```

需要先在本目錄 composer install。腳本讀取目前 SqlQueryProvider，只在暫存副本替換 FETCH_CLASS 那一行，透過 auto_prepend_file 載入測試版本；正式 src 不修改。FETCH_FUNC callback 在 Entity scope 一次指定 private data；既有 eligibility guards 限制無 constructor、無宣告 mapped properties、無自訂 setter 的 Entity。其他 metadata 驗證、boolean 轉換、關聯 context、DbSet iterator、JsonSerializable 均相同。這是量度用 prototype，並非正式公開 bulk-hydration API。

30 輪、120 個 worker、每次 10,000 筆 SELECT → JSON，輸出完全一致。

| Mode | Variant | Total median ms | Fetch/materialization ms | JSON ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| cold | fetch-class | 42.294 | 29.400 | 12.570 | 14.918 | 14.919 |
| cold | fetch-func | 39.391 | 26.762 | 12.393 | 14.918 | 14.919 |
| warm | fetch-class | 33.340 | 22.728 | 10.621 | 14.627 | 14.627 |
| warm | fetch-func | 30.144 | 19.567 | 10.682 | 14.627 | 14.627 |

暖查詢總耗時減少 9.59%，約省 3.196 ms，RAM 基本相同。FETCH_FUNC callback 與組合陣列仍有成本，未能完全消除 Entity 相對直接陣列的額外工作。

完整樣本：`results-entity-fetch-func-10000.json`。原生 PDO與 ORM A/B 均使用同一輪中的新舊對照；兩組不同測試之間的時間不應直接相減。所有容器及暫存 provider 已清理。


## FETCH_FUNC 正式實作

目前正式 ORM 已改用 Entity::initializeFromRow 與 FETCH_FUNC；compare-fetch-func.py 改為對照正式 provider 與臨時 FETCH_CLASS baseline，不再使用早期 prototype 的 scope-bound closure。最新結果與測試見 [README.md](README.md#正式接入-fetch_func-後的-select--json-benchmark)。結果保存為 `results-entity-fetch-func-production-10000.json`，歷史 prototype 結果仍保留。
