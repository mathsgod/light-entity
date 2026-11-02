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
- 四組案例：兩個 fetch modes × 只 SELECT/fetch 或 SELECT/fetch/JSON。每個樣本都是新的 PHP process，先做一次相同 workload 暖機，釋放結果後開始量度；每輪隨機交錯、逐個執行，CLI OPcache 關閉，連線建立排除在計時外。
- `Total ms`：包含 prepare、execute、完整 fetch，JSON workload 額外包含 json_encode。`Fetch ms` 單獨量度 fetchAll 與 closeCursor；`JSON ms` 為編碼階段。分段各自取中位數，加總可能與總中位數略有不同。
- PHP RAM 指標不是 OS RSS，亦不包括 MySQL server RAM；peak 在計時前重設，驗證與 checksum 在時間/RAM 快照之後才進行。JSON workload 包含 JSON 字串存活時的記憶體。
- 每次驗證 10,000 個連續 ID，並比較完整 JSON 字串 SHA-256，確保欄位、值、型別與次序一致。

## 本次結果

PHP 8.5.12、MySQL 8.0.46，30 輪共 120 個 worker；全部結果一致，JSON 每次 2675573 bytes。

| Mode | Workload | Total median ms | P95 ms | Fetch median ms | JSON median ms | Retained MiB | Peak +MiB |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| assoc | fetch | 15.100 | 16.775 | 4.701 | 0.001 | 7.286 | 9.333 |
| class | fetch | 16.139 | 17.378 | 5.447 | 0.001 | 5.226 | 7.273 |
| assoc | json | 22.214 | 24.178 | 4.641 | 6.680 | 9.841 | 9.841 |
| class | json | 22.275 | 24.151 | 5.296 | 6.346 | 7.781 | 7.781 |

只 SELECT/fetch 時，FETCH_CLASS 總時間多約 1.039 ms（6.88%）。SELECT → JSON 總時間差約 0.061 ms（0.27%），本次可以視為相近。

結論僅適用於這種簡單 public-property class，不能等同 Entity（魔術屬性、關聯 context、自訂 serializer）的成本；這個測試顯示單純 FETCH_CLASS 並不必然帶來之前 ORM 比較中那種明顯差距。完整樣本：`results-pdo-10000.json`。
