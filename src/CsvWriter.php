<?php

namespace Wttks\Csv;

use Wttks\Csv\Exceptions\CsvEncodingException;
use Wttks\Csv\Exceptions\CsvFileNotWritableException;
use Wttks\Csv\Exceptions\CsvMappingException;
use Wttks\Csv\Exceptions\CsvStateException;

/**
 * CSV/TSV ファイルまたは文字列への書き込みクラス。
 *
 * 一括書き込み:
 *   CsvWriter::file('output.csv')
 *       ->map(['氏名' => 'name', '電話番号' => fn($item) => $item['phone']])
 *       ->write($rows);
 *
 * 分割書き込み（大量データ・チャンク処理向け）:
 *   $writer = CsvWriter::file('output.csv')
 *       ->map(['氏名' => 'name'])
 *       ->open();
 *
 *   $writer->add($chunk1);
 *   $writer->add($chunk2);
 *   $writer->close();
 *
 * 文字列として取得:
 *   $csv = CsvWriter::toString()->map(['Name' => 'name'])->get($rows);
 *
 * @throws \Wttks\Csv\Exceptions\CsvFileNotWritableException  書き込み先の権限・IO エラー
 * @throws \Wttks\Csv\Exceptions\CsvEncodingException         エンコーディング変換失敗
 * @throws \Wttks\Csv\Exceptions\CsvMappingException          マッピングクロージャの例外
 * @throws \Wttks\Csv\Exceptions\CsvStateException            操作順序エラー
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

    /** open() で開いたファイルハンドル */
    private mixed $handle = null;

    /** open() / add() による直接書き込みモード中は true */
    private bool $streaming = false;

    private function __construct(CsvConfig $config)
    {
        $this->config = $config;
    }

    // =========================================================================
    // ファクトリメソッド
    // =========================================================================

    /**
     * ファイルに書き出す。
     *
     * @throws \Wttks\Csv\Exceptions\CsvFileNotWritableException 書き込み先ディレクトリが存在しない場合
     */
    public static function file(string $path, ?CsvConfig $config = null): static
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new CsvFileNotWritableException(
                "書き込み先ディレクトリが存在しません: {$dir}"
            );
        }

        if (file_exists($path) && !is_writable($path)) {
            throw new CsvFileNotWritableException(
                "CSVファイルへの書き込み権限がありません: {$path}"
            );
        }

        if (!file_exists($path) && !is_writable($dir)) {
            throw new CsvFileNotWritableException(
                "書き込み先ディレクトリへの書き込み権限がありません: {$dir}"
            );
        }

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

    /**
     * @throws \Wttks\Csv\Exceptions\CsvStateException open() 後に呼び出した場合
     */
    public function config(CsvConfig $config): static
    {
        $this->assertNotStreaming('config()');
        $this->config = $config;
        return $this;
    }

    /**
     * @throws \Wttks\Csv\Exceptions\CsvStateException open() 後に呼び出した場合
     */
    public function delimiter(string $delimiter): static
    {
        $this->assertNotStreaming('delimiter()');
        $this->config = $this->config->delimiter($delimiter);
        return $this;
    }

    /**
     * @throws \Wttks\Csv\Exceptions\CsvStateException open() 後に呼び出した場合
     */
    public function enclosure(string $enclosure): static
    {
        $this->assertNotStreaming('enclosure()');
        $this->config = $this->config->enclosure($enclosure);
        return $this;
    }

    /**
     * @throws \Wttks\Csv\Exceptions\CsvStateException open() 後に呼び出した場合
     */
    public function encoding(string $encoding): static
    {
        $this->assertNotStreaming('encoding()');
        $this->config = $this->config->writeEncoding($encoding);
        return $this;
    }

    /**
     * @throws \Wttks\Csv\Exceptions\CsvStateException open() 後に呼び出した場合
     */
    public function excelFormula(bool $enabled = true): static
    {
        $this->assertNotStreaming('excelFormula()');
        $this->config = $this->config->excelFormula($enabled);
        return $this;
    }

    /**
     * @throws \Wttks\Csv\Exceptions\CsvStateException open() 後に呼び出した場合
     */
    public function hasHeader(bool $enabled = true): static
    {
        $this->assertNotStreaming('hasHeader()');
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
     *
     * @throws \Wttks\Csv\Exceptions\CsvMappingException  値が文字列でもクロージャでもない場合
     * @throws \Wttks\Csv\Exceptions\CsvStateException    open() 後に呼び出した場合
     */
    public function map(array $map): static
    {
        $this->assertNotStreaming('map()');

        foreach ($map as $header => $source) {
            if (!is_string($source) && !($source instanceof \Closure)) {
                $type = get_debug_type($source);
                throw new CsvMappingException(
                    "map() の値には文字列またはクロージャを指定してください。キー \"{$header}\" に {$type} が指定されています。"
                );
            }
        }

        $this->map = $map;
        return $this;
    }

    // =========================================================================
    // 分割書き込み（open / add / close）
    // =========================================================================

    /**
     * ファイルを開いてヘッダー行を書き出す。
     * 以降は add() でデータを追加し、最後に close() で閉じる。
     *
     * @return $this
     * @throws \Wttks\Csv\Exceptions\CsvStateException          file() なしで呼んだ / すでに open() 済み
     * @throws \Wttks\Csv\Exceptions\CsvFileNotWritableException ファイルのオープン失敗
     */
    public function open(): static
    {
        if ($this->path === null) {
            throw new CsvStateException(
                'open() を使うには file() でファイルパスを指定してください。'
            );
        }

        if ($this->handle !== null) {
            throw new CsvStateException(
                'すでにファイルが開かれています。close() を呼び出してから再度 open() してください。'
            );
        }

        $handle = fopen($this->path, 'w');
        if ($handle === false) {
            throw new CsvFileNotWritableException(
                "ファイルのオープンに失敗しました: {$this->path}"
            );
        }

        // UTF-8 BOM を先頭に書き出す
        if ($this->config->writeEncoding === 'UTF-8-BOM') {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        $this->handle    = $handle;
        $this->streaming = true;

        // ヘッダー行を書き出す
        if ($this->config->hasHeader && !empty($this->map)) {
            $this->writeLineToHandle($this->handle, array_keys($this->map));
        }

        return $this;
    }

    /**
     * 開いているファイルにデータ行を追加する。
     * open() を呼び出してから使用すること。
     *
     * @param  iterable<mixed> $rows Model / 連想配列 / オブジェクトの iterable
     * @return $this
     * @throws \Wttks\Csv\Exceptions\CsvStateException    open() 前に呼んだ場合
     * @throws \Wttks\Csv\Exceptions\CsvMappingException  クロージャ内で例外が発生した場合
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException エンコーディング変換失敗
     */
    public function add(iterable $rows): static
    {
        if ($this->handle === null) {
            throw new CsvStateException(
                'add() を呼ぶ前に open() でファイルを開いてください。'
            );
        }

        foreach ($rows as $item) {
            $this->writeLineToHandle($this->handle, $this->extractRow($item));
        }

        return $this;
    }

    /**
     * ファイルを閉じる。
     * open() / add() による分割書き込み後に呼び出すこと。
     */
    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        fclose($this->handle);
        $this->handle    = null;
        $this->streaming = false;
    }

    /**
     * close() が呼ばれないままインスタンスが破棄された場合に自動でファイルを閉じる。
     */
    public function __destruct()
    {
        $this->close();
    }

    // =========================================================================
    // 一括書き込み
    // =========================================================================

    /**
     * ファイルに書き出す。
     *
     * @param  iterable<mixed> $rows Model / 連想配列 / オブジェクトの iterable
     * @throws \Wttks\Csv\Exceptions\CsvStateException          file() なしで呼んだ場合
     * @throws \Wttks\Csv\Exceptions\CsvFileNotWritableException ファイル書き込み失敗
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException        エンコーディング変換失敗
     * @throws \Wttks\Csv\Exceptions\CsvMappingException         クロージャ内で例外が発生した場合
     */
    public function write(iterable $rows): void
    {
        if ($this->path === null) {
            throw new CsvStateException(
                'write() を使うには file() でファイルパスを指定してください。'
            );
        }

        $csv     = $this->buildCsvString($rows);
        $encoded = $this->encodeOutput($csv);

        if (file_put_contents($this->path, $encoded) === false) {
            throw new CsvFileNotWritableException(
                "CSVファイルへの書き込みに失敗しました: {$this->path}"
            );
        }
    }

    /**
     * CSV文字列として返す。
     *
     * @param  iterable<mixed> $rows Model / 連想配列 / オブジェクトの iterable
     * @throws \Wttks\Csv\Exceptions\CsvMappingException クロージャ内で例外が発生した場合
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
     *
     * @throws \Wttks\Csv\Exceptions\CsvMappingException クロージャ内で例外が発生した場合
     */
    private function buildCsvString(iterable $rows): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new CsvFileNotWritableException('メモリストリームのオープンに失敗しました。');
        }

        try {
            if ($this->config->hasHeader && !empty($this->map)) {
                $this->writeLineToHandle($handle, array_keys($this->map));
            }

            foreach ($rows as $item) {
                $this->writeLineToHandle($handle, $this->extractRow($item));
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
     * @param  resource $handle
     * @param  string[] $fields
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException エンコーディング変換失敗
     */
    private function writeLineToHandle($handle, array $fields): void
    {
        $hasFormula = array_any($fields, fn(string $f) => str_starts_with($f, '='));

        if ($hasFormula) {
            $line = $this->buildLine($fields);
        } else {
            $tmp = fopen('php://memory', 'r+');
            if ($tmp === false) {
                throw new CsvFileNotWritableException('メモリストリームのオープンに失敗しました。');
            }
            fputcsv($tmp, $fields, $this->config->delimiter, $this->config->enclosure, $this->config->escape);
            rewind($tmp);
            $line = stream_get_contents($tmp) ?: '';
            fclose($tmp);
        }

        // ストリーミングモード（open/add）の場合は行単位でエンコーディング変換する
        // （buildCsvString() 経由の場合は encodeOutput() で後処理するので変換不要）
        if ($this->streaming && !in_array($this->config->writeEncoding, ['UTF-8', 'UTF-8-BOM'], true)) {
            $converted = mb_convert_encoding($line, $this->config->writeEncoding, 'UTF-8');
            if ($converted === false) {
                throw new CsvEncodingException(
                    "エンコーディング変換に失敗しました: UTF-8 → {$this->config->writeEncoding}"
                    . ($this->path !== null ? "（ファイル: {$this->path}）" : '')
                );
            }
            $line = $converted;
        }

        fwrite($handle, $line);
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
        if (str_starts_with($field, '=')) {
            return $field;
        }

        $enc          = $this->config->enclosure;
        $needsQuoting = str_contains($field, $this->config->delimiter)
            || str_contains($field, $enc)
            || str_contains($field, "\n")
            || str_contains($field, "\r");

        if (!$needsQuoting) {
            return $field;
        }

        return $enc . str_replace($enc, $enc . $enc, $field) . $enc;
    }

    /**
     * 1件のデータからCSV行の配列を生成する。
     *
     * @param  mixed    $item
     * @return string[]
     * @throws \Wttks\Csv\Exceptions\CsvMappingException クロージャ内で例外が発生した場合
     */
    private function extractRow(mixed $item): array
    {
        if (empty($this->map)) {
            $values = is_array($item) ? array_values($item) : (array) $item;
            return array_map(fn($v) => $this->formatValue($v), $values);
        }

        $row = [];
        foreach ($this->map as $header => $source) {
            if ($source instanceof \Closure) {
                try {
                    $value = $source($item);
                } catch (\Throwable $e) {
                    throw new CsvMappingException(
                        "マッピングのクロージャで例外が発生しました（ヘッダー: \"{$header}\"）: {$e->getMessage()}",
                        previous: $e
                    );
                }
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
            if ($item instanceof \ArrayAccess) {
                return $item[$key] ?? '';
            }

            if (property_exists($item, $key)) {
                return $item->$key;
            }

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
        return strlen($value) >= 2
            && ctype_digit($value)
            && $value[0] === '0';
    }

    /**
     * CSV文字列を指定エンコーディングに変換する（BOM付与含む）。
     *
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException 変換失敗時
     */
    private function encodeOutput(string $utf8): string
    {
        return match ($this->config->writeEncoding) {
            'UTF-8-BOM' => "\xEF\xBB\xBF" . $utf8,
            'UTF-8'     => $utf8,
            default     => $this->convertFromUtf8($utf8, $this->config->writeEncoding),
        };
    }

    /**
     * UTF-8 文字列を指定エンコーディングに変換する。
     *
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException 変換失敗時
     */
    private function convertFromUtf8(string $utf8, string $encoding): string
    {
        $converted = mb_convert_encoding($utf8, $encoding, 'UTF-8');
        if ($converted === false) {
            throw new CsvEncodingException(
                "エンコーディング変換に失敗しました: UTF-8 → {$encoding}"
                . ($this->path !== null ? "（ファイル: {$this->path}）" : '')
            );
        }
        return $converted;
    }

    /**
     * open() / add() 中に設定変更しようとした場合に例外を投げる。
     *
     * @throws \Wttks\Csv\Exceptions\CsvStateException
     */
    private function assertNotStreaming(string $method): void
    {
        if ($this->streaming) {
            throw new CsvStateException(
                "open() 後に {$method} で設定を変更することはできません。open() の前に設定してください。"
            );
        }
    }
}
