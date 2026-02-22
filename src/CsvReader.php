<?php

namespace Wttks\Csv;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Wttks\Csv\Exceptions\CsvEncodingException;
use Wttks\Csv\Exceptions\CsvFileNotFoundException;
use Wttks\Csv\Exceptions\CsvFileNotReadableException;
use Wttks\Csv\Exceptions\CsvMappingException;
use Wttks\Csv\Exceptions\CsvParseException;

/**
 * CSV/TSV ファイルまたは文字列の読み込みクラス。
 *
 * 使用例:
 *   // ファイルから読み込み（エンコーディング自動判定）
 *   $rows = CsvReader::file('data.csv')
 *       ->map(['氏名' => 'name', '電話番号' => 'phone'])
 *       ->rows();
 *
 *   // ストリーミング読み込み（大きいファイル向け）
 *   foreach (CsvReader::file('large.csv')->cursor() as $row) {
 *       // 1行ずつ処理
 *   }
 *
 * @throws \Wttks\Csv\Exceptions\CsvFileNotFoundException     ファイルが見つからない
 * @throws \Wttks\Csv\Exceptions\CsvFileNotReadableException  読み取り権限がない
 * @throws \Wttks\Csv\Exceptions\CsvEncodingException         エンコーディング変換失敗
 * @throws \Wttks\Csv\Exceptions\CsvParseException            CSV解析エラー
 * @throws \Wttks\Csv\Exceptions\CsvMappingException          マッピングクロージャの例外
 */
class CsvReader
{
    /** 読み込みエンコーディングとして指定できる値 */
    private const VALID_ENCODINGS = ['UTF-8', 'SJIS-win', 'eucJP-win', 'ASCII'];

    private CsvConfig $config;

    /** ファイルパス（ファイル読み込み時） */
    private ?string $path = null;

    /** 文字列コンテンツ（文字列読み込み時） */
    private ?string $content = null;

    /** エンコーディング上書き（nullの場合は自動判定） */
    private ?string $encoding = null;

    /**
     * カラムマッピング。
     * キー: CSVヘッダー名（文字列）または列インデックス（整数・0始まり）
     * 値: 出力配列のキー名（文字列）またはクロージャ（値変換）
     *
     * @var array<string|int, string|\Closure>
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
     * ファイルから読み込む。
     *
     * @throws \Wttks\Csv\Exceptions\CsvFileNotFoundException    ファイルが存在しない
     * @throws \Wttks\Csv\Exceptions\CsvFileNotReadableException 読み取り権限がない
     */
    public static function file(string $path, ?CsvConfig $config = null): static
    {
        if (!file_exists($path)) {
            throw new CsvFileNotFoundException("CSVファイルが見つかりません: {$path}");
        }

        if (!is_readable($path)) {
            throw new CsvFileNotReadableException("CSVファイルを読み取る権限がありません: {$path}");
        }

        $instance = new static($config ?? new CsvConfig());
        $instance->path = $path;
        return $instance;
    }

    /**
     * 文字列から読み込む。
     */
    public static function string(string $content, ?CsvConfig $config = null): static
    {
        $instance = new static($config ?? new CsvConfig());
        $instance->content = $content;
        return $instance;
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
        // CsvConfig コンストラクタ内でバリデーションされる（CsvConfigException）
        $this->config = $this->config->delimiter($delimiter);
        return $this;
    }

    public function enclosure(string $enclosure): static
    {
        $this->config = $this->config->enclosure($enclosure);
        return $this;
    }

    public function hasHeader(bool $enabled = true): static
    {
        $this->config = $this->config->hasHeader($enabled);
        return $this;
    }

    /**
     * エンコーディングを明示指定する（自動判定を上書き）。
     *
     * @param  string $encoding 'UTF-8' / 'SJIS-win' / 'eucJP-win' / 'ASCII'
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException 未対応のエンコーディングを指定した場合
     */
    public function encoding(string $encoding): static
    {
        if (!in_array($encoding, self::VALID_ENCODINGS, true)) {
            $valid = implode(', ', self::VALID_ENCODINGS);
            throw new CsvEncodingException(
                "未対応のエンコーディングです: \"{$encoding}\"。指定可能な値: {$valid}"
            );
        }

        $this->encoding = $encoding;
        return $this;
    }

    /**
     * カラムマッピングを設定する。
     *
     * キーにはヘッダー名（文字列）または列インデックス（0始まりの整数）を指定できる。
     * 値には出力キー名（文字列）または値変換クロージャを指定できる。
     *
     * 例:
     *   ->map([
     *       '氏名'   => 'name',              // ヘッダー名 → 出力キー名
     *       '金額'   => fn($v) => (int) $v,  // ヘッダー名 → 値変換（出力キーはヘッダー名のまま）
     *       0        => 'name',              // 列インデックス → 出力キー名
     *       2        => fn($v) => (int) $v,  // 列インデックス → 値変換（出力キーはインデックス番号）
     *   ])
     *
     * @throws \Wttks\Csv\Exceptions\CsvMappingException マップの値が文字列でもクロージャでもない場合
     */
    public function map(array $map): static
    {
        foreach ($map as $source => $target) {
            if (!is_string($target) && !($target instanceof \Closure)) {
                $type = get_debug_type($target);
                throw new CsvMappingException(
                    "map() の値には文字列またはクロージャを指定してください。キー \"{$source}\" に {$type} が指定されています。"
                );
            }
        }

        $this->map = $map;
        return $this;
    }

    // =========================================================================
    // 読み込み
    // =========================================================================

    /**
     * 全行を Collection として返す。
     *
     * @return Collection<int, array<string|int, mixed>>
     */
    public function rows(): Collection
    {
        return Collection::make($this->readAll());
    }

    /**
     * 1行ずつ処理する LazyCollection を返す（大きいファイル向け）。
     *
     * @return LazyCollection<int, array<string|int, mixed>>
     */
    public function cursor(): LazyCollection
    {
        return LazyCollection::make(function () {
            yield from $this->readGenerator();
        });
    }

    // =========================================================================
    // 内部処理
    // =========================================================================

    /**
     * 全行を配列として読み込む。
     *
     * @return array<int, array<string|int, mixed>>
     */
    private function readAll(): array
    {
        return iterator_to_array($this->readGenerator(), false);
    }

    /**
     * ジェネレータで1行ずつ読み込む。
     *
     * @return \Generator<int, array<string|int, mixed>>
     */
    private function readGenerator(): \Generator
    {
        $handle = $this->openHandle();

        try {
            $headers    = null;
            $lineNumber = 0;

            while (true) {
                $row = fgetcsv(
                    $handle,
                    0,
                    $this->config->delimiter,
                    $this->config->enclosure,
                    $this->config->escape
                );

                if ($row === false) {
                    if (!feof($handle)) {
                        // EOF ではなくエラーで false が返った
                        throw new CsvParseException(
                            $this->path !== null
                                ? "CSVファイルの読み込み中にエラーが発生しました（{$lineNumber}行目付近）: {$this->path}"
                                : "CSV文字列の読み込み中にエラーが発生しました（{$lineNumber}行目付近）"
                        );
                    }
                    break;
                }

                $lineNumber++;

                // 空行をスキップ
                if ($row === [null]) {
                    continue;
                }

                // ヘッダー行の処理
                if ($headers === null && $this->config->hasHeader) {
                    $headers = array_map(
                        fn(string $h) => $this->stripExcelFormula($h),
                        $row
                    );
                    continue;
                }

                yield $this->processRow($row, $headers, $lineNumber);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * ストリームハンドルを開く（エンコーディング変換込み）。
     *
     * @return resource
     * @throws \Wttks\Csv\Exceptions\CsvFileNotReadableException ファイル読み込み失敗
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException        エンコーディング変換失敗
     */
    private function openHandle()
    {
        if ($this->path !== null) {
            $raw = file_get_contents($this->path);
            if ($raw === false) {
                throw new CsvFileNotReadableException(
                    "CSVファイルの読み込みに失敗しました: {$this->path}"
                );
            }
        } else {
            $raw = $this->content ?? '';
        }

        $utf8 = $this->convertToUtf8($raw);

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new CsvParseException('メモリストリームのオープンに失敗しました。');
        }

        fwrite($handle, $utf8);
        rewind($handle);

        return $handle;
    }

    /**
     * 文字列を UTF-8 に変換する。
     *
     * @throws \Wttks\Csv\Exceptions\CsvEncodingException エンコーディング判定・変換失敗時
     */
    private function convertToUtf8(string $raw): string
    {
        // UTF-8 BOM を検出して除去
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        $encoding = $this->encoding ?? $this->detectEncoding($raw);

        if ($encoding === 'UTF-8' || $encoding === 'ASCII') {
            return $raw;
        }

        $converted = mb_convert_encoding($raw, 'UTF-8', $encoding);
        if ($converted === false) {
            throw new CsvEncodingException(
                "エンコーディング変換に失敗しました: {$encoding} → UTF-8"
                . ($this->path !== null ? "（ファイル: {$this->path}）" : '')
            );
        }

        return $converted;
    }

    /**
     * エンコーディングを自動判定する。
     * 判定不能の場合は UTF-8 を返す。
     */
    private function detectEncoding(string $str): string
    {
        if ($str === '') {
            return 'ASCII';
        }

        $detected = mb_detect_encoding($str, ['ASCII', 'UTF-8', 'SJIS-win', 'eucJP-win'], strict: true);

        return $detected !== false ? $detected : 'UTF-8';
    }

    /**
     * 1行分のデータを処理してキー付き配列に変換する。
     *
     * @param  string[]      $row
     * @param  string[]|null $headers
     * @param  int           $lineNumber エラーメッセージ用の行番号
     * @return array<string|int, mixed>
     */
    private function processRow(array $row, ?array $headers, int $lineNumber): array
    {
        // Excel数式形式を除去（="0120" → "0120"）
        $row = array_map(fn(string $v) => $this->stripExcelFormula($v), $row);

        if ($headers === null) {
            if (empty($this->map)) {
                return $row;
            }
            return $this->applyMap($row, assoc: null, lineNumber: $lineNumber);
        }

        // ヘッダーをキーにした連想配列に変換
        $assoc = [];
        foreach ($headers as $i => $header) {
            $assoc[$header] = $row[$i] ?? '';
        }

        if (empty($this->map)) {
            return $assoc;
        }

        return $this->applyMap($row, assoc: $assoc, lineNumber: $lineNumber);
    }

    /**
     * カラムマッピングを適用して出力配列を返す。
     *
     * @param  string[]                    $row
     * @param  array<string, string>|null  $assoc
     * @param  int                         $lineNumber
     * @return array<string|int, mixed>
     * @throws \Wttks\Csv\Exceptions\CsvMappingException クロージャ内で例外が発生した場合
     */
    private function applyMap(array $row, ?array $assoc, int $lineNumber): array
    {
        $mapped = [];

        foreach ($this->map as $source => $target) {
            $value = is_int($source)
                ? ($row[$source] ?? '')
                : ($assoc[$source] ?? '');

            if ($target instanceof \Closure) {
                try {
                    $mapped[$source] = $target($value);
                } catch (\Throwable $e) {
                    throw new CsvMappingException(
                        "マッピングのクロージャで例外が発生しました（{$lineNumber}行目、キー: \"{$source}\"）: {$e->getMessage()}",
                        previous: $e
                    );
                }
            } else {
                $mapped[$target] = $value;
            }
        }

        return $mapped;
    }

    /**
     * Excel数式形式（="..."）を除去して内側の値を返す。
     */
    private function stripExcelFormula(string $value): string
    {
        if (!$this->config->excelFormula) {
            return $value;
        }

        if (preg_match('/\A="(.*)"\z/s', $value, $m)) {
            return $m[1];
        }

        return $value;
    }
}
