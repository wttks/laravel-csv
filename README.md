# wttks/laravel-csv

Laravel 向け CSV/TSV 読み書きパッケージ。

- UTF-8（BOM有無）・SJIS-win・eucJP-win の自動判定読み込み
- 書き込みエンコーディング指定（デフォルト: UTF-8 BOM）
- 先頭ゼロの数字文字列を Excel 数式形式（`="0120"`）で保護
- ヘッダー行対応・カラムマッピング（文字列キー変換・クロージャ値変換）
- Model / 連想配列 / オブジェクトをそのまま書き込み可能
- 全件読み込み（`Collection`）とストリーミング読み込み（`LazyCollection`）
- 区切り文字・囲み文字・エスケープ文字のカスタマイズ

## インストール

```bash
composer require wttks/laravel-csv
```

Laravel 11以降はパッケージ自動検出により、ServiceProviderは自動登録されます。

## 読み込み（CsvReader）

### 基本

```php
use Wttks\Csv\CsvReader;

// ファイルから読み込み（エンコーディング自動判定）
$rows = CsvReader::file('data.csv')->rows();
// → Collection<int, array<string, string>>

// 文字列から読み込み
$rows = CsvReader::string($csvString)->rows();
```

### エンコーディング

読み込み時のエンコーディングは自動判定します。判定順序:

1. UTF-8 BOM（先頭の `EF BB BF` を検出）
2. UTF-8
3. SJIS-win
4. eucJP-win

```php
// 自動判定（推奨）
$rows = CsvReader::file('sjis_data.csv')->rows();

// 明示指定
$rows = CsvReader::file('sjis_data.csv')
    ->encoding('SJIS-win')
    ->rows();
```

### ヘッダー行

デフォルトで1行目をヘッダーとして扱い、キー付き連想配列を返します。

```php
// ヘッダーあり（デフォルト）
$rows = CsvReader::file('data.csv')->rows();
// → [['氏名' => '山田太郎', '電話番号' => '090-...'], ...]

// ヘッダーなし（インデックス配列）
$rows = CsvReader::file('data.csv')->hasHeader(false)->rows();
// → [['山田太郎', '090-...'], ...]
```

### カラムマッピング

CSV のヘッダー名をアプリ側のキー名に変換したり、値を加工できます。

```php
$rows = CsvReader::file('data.csv')
    ->map([
        // 文字列: CSVヘッダー名 → 出力キー名
        '氏名'     => 'name',
        '電話番号' => 'phone',
        // クロージャ: 値を加工（出力キーはCSVヘッダー名になる）
        '郵便番号' => fn($v) => str_replace('-', '', $v),
        '金額'     => fn($v) => (int) $v,
    ])
    ->rows();
// → [['name' => '山田太郎', 'phone' => '090-...', '郵便番号' => '1234567', '金額' => 1000], ...]
```

マッピング未設定の場合はヘッダー名をそのままキーとして使用します。

### Excel 数式形式（先頭ゼロの保護）

Excel が書き出す `="0120"` 形式を自動的に除去して値を返します。

```php
// ="0120" → '0120'、="001" → '001' として取得
$rows = CsvReader::file('data.csv')->rows();

// 無効にする（="0120" をそのまま返す）
use Wttks\Csv\CsvConfig;

$rows = CsvReader::file('data.csv', CsvConfig::make()->excelFormula(false))->rows();
```

### ストリーミング読み込み（大きいファイル向け）

`cursor()` は `LazyCollection` を返し、1行ずつ処理するためメモリを節約できます。

```php
// 全件読み込み: 全行分メモリに展開
$rows = CsvReader::file('data.csv')->rows();  // Collection

// ストリーミング: 常に1行分だけメモリに展開
foreach (CsvReader::file('large.csv')->cursor() as $row) {
    // 1行ずつ処理
}

// LazyCollection のメソッドも使える
CsvReader::file('large.csv')
    ->cursor()
    ->each(fn($row) => /* 処理 */);
```

| | `rows()` | `cursor()` |
|---|---|---|
| 戻り値 | `Collection` | `LazyCollection` |
| メモリ | 全行分 | 常に1行分 |
| 10万行の目安 | ~50MB | ~1MB |

### TSV

```php
use Wttks\Csv\CsvConfig;

$rows = CsvReader::file('data.tsv', CsvConfig::tsv())->rows();

// または
$rows = CsvReader::file('data.tsv')->delimiter("\t")->rows();
```

---

## 書き込み（CsvWriter）

### 基本

```php
use Wttks\Csv\CsvWriter;

// ファイルに書き出す
CsvWriter::file('output.csv')
    ->map(['氏名' => 'name', '電話番号' => 'phone'])
    ->write($rows);

// CSV 文字列として取得
$csv = CsvWriter::toString()
    ->map(['氏名' => 'name', '電話番号' => 'phone'])
    ->get($rows);
```

### カラムマッピング

マッピングのキーが CSV ヘッダー名、値がデータソースのキー/プロパティ名です。

```php
CsvWriter::file('output.csv')
    ->map([
        // 文字列: 連想配列のキー名またはオブジェクトのプロパティ名
        '氏名'     => 'name',
        '電話番号' => 'phone',

        // クロージャ: 任意の加工
        '郵便番号' => fn($item) => $item['zip'] ?? '',
        '金額'     => fn($item) => number_format($item->price),
    ])
    ->write($rows);
```

データソースとして以下が使えます:

```php
// 連想配列
$rows = [['name' => '山田太郎', 'phone' => '090-...']];

// stdClass / オブジェクト
$obj = new stdClass();
$obj->name = '山田太郎';
$rows = [$obj];

// Eloquent Model（プロパティアクセス）
$rows = User::all();

// Collection
$rows = collect([...]);
```

### エンコーディング

デフォルトは UTF-8 BOM 付き（Excel で開いたとき文字化けしない）。

```php
// UTF-8 BOM（デフォルト・Excel向け）
CsvWriter::file('output.csv')->write($rows);

// UTF-8（BOMなし）
CsvWriter::file('output.csv')->encoding('UTF-8')->write($rows);

// SJIS-win
CsvWriter::file('output.csv')->encoding('SJIS-win')->write($rows);

// eucJP-win
CsvWriter::file('output.csv')->encoding('eucJP-win')->write($rows);
```

### Excel 数式形式（先頭ゼロの保護）

先頭がゼロの数字文字列（郵便番号・フリーダイヤル番号等）は、そのままCSVに書くと
Excel で開いたときに先頭ゼロが消えてしまいます。デフォルトでは `="0120"` 形式で書き出して保護します。

```php
$rows = [
    ['code' => '0120', 'name' => 'フリーダイヤル'],
    ['code' => '1234', 'name' => '通常番号'],       // 先頭ゼロなし → そのまま
];

CsvWriter::file('output.csv')
    ->map(['コード' => 'code', '名前' => 'name'])
    ->write($rows);

// 出力:
// コード,名前
// ="0120",フリーダイヤル
// 1234,通常番号
```

無効にする場合:

```php
CsvWriter::file('output.csv')->excelFormula(false)->map([...])->write($rows);
```

### ヘッダー行の制御

```php
// ヘッダーあり（デフォルト）: map のキーがヘッダー行として出力される
CsvWriter::file('output.csv')->map(['氏名' => 'name'])->write($rows);

// ヘッダーなし
CsvWriter::file('output.csv')->hasHeader(false)->map(['氏名' => 'name'])->write($rows);
```

### TSV

```php
use Wttks\Csv\CsvConfig;

CsvWriter::file('output.tsv', CsvConfig::tsv())
    ->map(['名前' => 'name', '年齢' => 'age'])
    ->write($rows);

// または
CsvWriter::file('output.tsv')->delimiter("\t")->map([...])->write($rows);
```

---

## 設定（CsvConfig）

```php
use Wttks\Csv\CsvConfig;

$config = CsvConfig::make()
    ->delimiter(',')           // 区切り文字（デフォルト: ,）
    ->enclosure('"')           // 囲み文字（デフォルト: "）
    ->escape('')               // エスケープ文字（デフォルト: '' = Excel互換・囲み文字重複方式）
    ->writeEncoding('UTF-8-BOM') // 書き込みエンコーディング
    ->excelFormula(true)       // Excel数式形式の有効/無効
    ->hasHeader(true);         // ヘッダー行の有効/無効

// プリセット
CsvConfig::make();    // デフォルト（Excel互換CSV）
CsvConfig::tsv();     // TSV（タブ区切り）
```

### エスケープ文字について

| 設定 | 動作 | 用途 |
|---|---|---|
| `''`（空文字列・デフォルト） | 囲み文字を重複してエスケープ（`"` → `""`） | Excel / 標準CSV |
| `'\\'` | バックスラッシュでエスケープ | PHP標準 / 一部のシステム |

---

## 読み書きの往復

書き出したCSVをそのまま読み込んで元のデータに戻ります（先頭ゼロも保持）。

```php
$original = [
    ['code' => '0120', 'name' => 'フリーダイヤル'],
    ['code' => '001',  'name' => 'テスト'],
];

// 書き出し
$csv = CsvWriter::toString(CsvConfig::make()->writeEncoding('UTF-8'))
    ->map(['コード' => 'code', '名前' => 'name'])
    ->get($original);

// 読み込み
$rows = CsvReader::string($csv)->rows();

$rows[0]['コード']; // → '0120'（先頭ゼロが保持されている）
$rows[1]['コード']; // → '001'
```

---

## テスト

```bash
vendor/bin/phpunit
```

## ライセンス

MIT
