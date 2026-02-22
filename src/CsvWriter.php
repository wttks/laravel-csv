<?php

namespace Wttks\Csv;

use RuntimeException;

/**
 * CSV/TSV ファイルまたは文字列への書き込みクラス。
 *
 * 使用例:
 *   CsvWriter::file('output.csv')
 *       ->map([
 *           '氏名'     => 'name',
 *           '電話番号' => fn($item) => $item['phone'],
 *       ])
 *       ->write($rows);
 *
 *   // 文字列として取得
 *   $csv = CsvWriter::toString()
 *       ->map(['Name' => 'name'])
 *       ->get($rows);
 */
class CsvWriter
{
    private CsvConfig $config;

    /** 書き込み先ファイルパス（nullの場合は文字列出力） */
    private ?string $path = null;

    /**
     * カラムマッピング。
     * キー: CSVヘッダー名
     * 値: データソースのキー/プロパティ名（文字列）またはクロージャ（function(mixed $item): mixed）
     *
     * @var array<string, string|\Closure>
     */
    private array $map = [];

    private function __construct(CsvConfig $config)
    {
        $this->config = $config;
    }

    // =========================================================================
    // ファクトリメソッド
    // =========================================================================

    /**
     * ファイルに書き出す。
     */
    public static function file(string $path, ?CsvConfig $config = null): static
    {
        $instance = new static($config ?? new CsvConfig());
        $instance->path = $path;
        return $instance;
    }

    /**
     * 文字列として取得する（get() で返す）。
     */
    public static function toString(?CsvConfig $config = null): static
    {
        return new static($config ?? new CsvConfig());
    }

    // =========================================================================
    // フルーエント設定
    // =========================================================================

    public function config(CsvConfig $config): static
    {
        $this->config = $config;
        return $this;
    }

    public function delimiter(string $delimiter): static
    {
        $this->config = $this->config->delimiter($delimiter);
        return $this;
    }

    public function enclosure(string $enclosure): static
    {
        $this->config = $this->config->enclosure($enclosure);
        return $this;
    }

    public function encoding(string $encoding): static
    {
        $this->config = $this->config->writeEncoding($encoding);
        return $this;
    }

    public function excelFormula(bool $enabled = true): static
    {
        $this->config = $this->config->excelFormula($enabled);
        return $this;
    }

    public function hasHeader(bool $enabled = true): static
    {
        $this->config = $this->config->hasHeader($enabled);
        return $this;
    }

    /**
     * カラムマッピングを設定する。
     *
     * キー: CSVヘッダー名
     * 値: データソースのキー/プロパティ名（文字列）または取得クロージャ（function(mixed $item): mixed）
     *
     * 例:
     *   ->map([
     *       '氏名'     => 'name',
     *       '電話番号' => fn($item) => $item['phone'] ?? '',
     *       '金額'     => fn($item) => number_format($item->price),
     *   ])
     */
    public function map(array $map): static
    {
        $this->map = $map;
        return $this;
    }

    // =========================================================================
    // 書き込み
    // =========================================================================

    /**
     * ファイルに書き出す。
     *
     * @param iterable<mixed> $rows Model / 連想配列 / オブジェクトの iterable
     */
    public function write(iterable $rows): void
    {
        if ($this->path === null) {
            throw new RuntimeException('書き込み先ファイルパスが設定されていません。file() を使用してください。');
        }

        $csv = $this->buildCsvString($rows);
        $encoded = $this->encodeOutput($csv);

        if (file_put_contents($this->path, $encoded) === false) {
            throw new RuntimeException("CSVファイルへの書き込みに失敗しました: {$this->path}");
        }
    }

    /**
     * CSV文字列として返す。
     *
     * @param iterable<mixed> $rows Model / 連想配列 / オブジェクトの iterable
     */
    public function get(iterable $rows): string
    {
        return $this->buildCsvString($rows);
    }

    // =========================================================================
    // 内部処理
    // =========================================================================

    /**
     * CSV文字列を構築する（UTF-8）。
     */
    private function buildCsvString(iterable $rows): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new RuntimeException('メモリストリームのオープンに失敗しました');
        }

        try {
            // ヘッダー行を書き出す
            if ($this->config->hasHeader && !empty($this->map)) {
                $this->writeLine($handle, array_keys($this->map));
            }

            // データ行を書き出す
            foreach ($rows as $item) {
                $this->writeLine($handle, $this->extractRow($item));
            }

            rewind($handle);
            return stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }
    }

    /**
     * 1行分を書き出す。
     *
     * Excel数式形式（="..."）のフィールドは fputcsv を使うと二重クォートされてしまうため、
     * そのようなフィールドが含まれる行は手動でCSV行を構築する。
     *
     * @param resource $handle
     * @param string[] $fields
     */
    private function writeLine($handle, array $fields): void
    {
        // Excel数式形式のフィールドが含まれる場合は手動で行を構築
        $hasFormula = array_any($fields, fn(string $f) => str_starts_with($f, '='));

        if ($hasFormula) {
            fwrite($handle, $this->buildLine($fields));
        } else {
            fputcsv($handle, $fields, $this->config->delimiter, $this->config->enclosure, $this->config->escape);
        }
    }

    /**
     * CSV行を手動で構築する。
     * Excel数式形式（="..."）はそのまま出力し、その他のフィールドは適切にクォートする。
     *
     * @param  string[] $fields
     */
    private function buildLine(array $fields): string
    {
        $parts = array_map(fn(string $f) => $this->quoteField($f), $fields);
        return implode($this->config->delimiter, $parts) . "\n";
    }

    /**
     * 1フィールドを必要に応じてクォートする。
     * Excel数式形式（="..."）はそのまま返す（クォート不要）。
     */
    private function quoteField(string $field): string
    {
        // Excel数式形式はそのまま（fputcsvに渡すと二重クォートされるため手動で処理）
        if (str_starts_with($field, '=')) {
            return $field;
        }

        $enc = $this->config->enclosure;
        $needsQuoting = str_contains($field, $this->config->delimiter)
            || str_contains($field, $enc)
            || str_contains($field, "\n")
            || str_contains($field, "\r");

        if (!$needsQuoting) {
            return $field;
        }

        // 囲み文字を重複してエスケープ（Excel互換）
        return $enc . str_replace($enc, $enc . $enc, $field) . $enc;
    }

    /**
     * 1件のデータからCSV行の配列を生成する。
     *
     * @param  mixed    $item
     * @return string[]
     */
    private function extractRow(mixed $item): array
    {
        if (empty($this->map)) {
            // マッピング未設定: 配列またはオブジェクトをそのまま文字列配列に変換
            $values = is_array($item) ? array_values($item) : (array) $item;
            return array_map(fn($v) => $this->formatValue($v), $values);
        }

        $row = [];
        foreach ($this->map as $header => $source) {
            if ($source instanceof \Closure) {
                $value = $source($item);
            } else {
                $value = $this->extractValue($item, $source);
            }
            $row[] = $this->formatValue($value);
        }

        return $row;
    }

    /**
     * アイテムから指定キー/プロパティの値を取得する。
     * 配列アクセス → プロパティアクセスの順で試みる。
     */
    private function extractValue(mixed $item, string $key): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? '';
        }

        if (is_object($item)) {
            // 配列アクセス可能（ArrayAccess）
            if ($item instanceof \ArrayAccess) {
                return $item[$key] ?? '';
            }

            // パブリックプロパティまたはゲッター
            if (property_exists($item, $key)) {
                return $item->$key;
            }

            // getXxx() 形式のゲッター
            $getter = 'get' . ucfirst($key);
            if (method_exists($item, $getter)) {
                return $item->$getter();
            }
        }

        return '';
    }

    /**
     * 値を CSV 出力用の文字列にフォーマットする。
     * Excel数式形式が有効な場合、先頭ゼロの数字文字列を ="0120" 形式に変換する。
     */
    private function formatValue(mixed $value): string
    {
        $str = (string) ($value ?? '');

        if ($this->config->excelFormula && $this->needsExcelFormula($str)) {
            return '="' . $str . '"';
        }

        return $str;
    }

    /**
     * Excel数式形式（="..."）が必要かどうか判定する。
     * 先頭がゼロの数字文字列（"0120" 等）が対象。
     */
    private function needsExcelFormula(string $value): bool
    {
        // 2文字以上、全て数字、先頭がゼロ
        return strlen($value) >= 2
            && ctype_digit($value)
            && $value[0] === '0';
    }

    /**
     * CSV文字列を指定エンコーディングに変換する（BOM付与含む）。
     */
    private function encodeOutput(string $utf8): string
    {
        return match ($this->config->writeEncoding) {
            'UTF-8-BOM' => "\xEF\xBB\xBF" . $utf8,
            'UTF-8'     => $utf8,
            default     => mb_convert_encoding($utf8, $this->config->writeEncoding, 'UTF-8') ?: $utf8,
        };
    }
}
