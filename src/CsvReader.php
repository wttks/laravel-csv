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
 * 処理の適用順: map() → filter() → when() → transform()
 *
 * 使用例:
 *   $rows = CsvReader::file('data.csv')
 *       ->map(['氏名' => 'name', '電話番号' => 'phone'])
 *       ->filter(fn($row) => $row['name'] !== '')
 *       ->transform(fn($row) => new StaffData($row))
 *       ->rows();
 *
 *   foreach (CsvReader::file('large.csv')->cursor() as $row) { ... }
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

    /** フィルタクロージャ（map() 適用後の行を受け取り bool を返す） */
    private ?\Closure $filter = null;

    /**
     * 条件分岐。[condition, then, else?] の配列のリスト。
     *
     * @var array<int, array{0: \Closure, 1: \Closure, 2: \Closure|null}>
     */
    private array $whenClauses = [];

    /** 行変換クロージャ（when() 適用後の行全体を任意の値に変換する） */
    private ?\Closure $transform = null;

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
     * クロージャのシグネチャ: fn($value, $row) => ...
     *   $value: その列の値
     *   $row:   行全体の連想配列（ヘッダーなしの場合はインデックス配列）
     *
     * 例:
     *   ->map([
     *       '氏名'   => 'name',                                    // リネーム
     *       '金額'   => fn($v) => (int) $v,                        // 値変換
     *       '姓'     => fn($v, $row) => $row['姓'] . $row['名'],   // 複数列を結合
     *       'code'   => fn($v, $row) => $row['prefix'].$row['id'], // 新規列を追加
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

    /**
     * 行をフィルタするクロージャを設定する。
     *
     * map() 適用後の行が渡される。false を返した行はスキップされる。
     *
     * 例:
     *   ->filter(fn($row) => $row['金額'] > 0)
     *   ->filter(fn($row) => $row['name'] !== '')
     *
     * @param \Closure(array<string|int, mixed>): bool $closure
     */
    public function filter(\Closure $closure): static
    {
        $this->filter = $closure;

        return $this;
    }

    /**
     * 行の条件によって変換処理を分岐する。複数回呼び出し可能（先にマッチした節が適用される）。
     *
     * filter() 適用後の行が渡される。
     * $then / $otherwise の戻り値が次の処理（transform または出力）に渡される。
     * $otherwise を省略した場合、条件が false の行は元の行配列のまま渡される。
     *
     * 例:
     *   ->when(
     *       fn($row) => $row['type'] === 'A',
     *       fn($row) => new TypeAData($row),
     *       fn($row) => new TypeBData($row),
     *   )
     *
     * @param \Closure(array<string|int, mixed>): bool  $condition
     * @param \Closure(array<string|int, mixed>): mixed $then
     * @param \Closure(mixed): mixed|null               $otherwise
     */
    public function when(\Closure $condition, \Closure $then, ?\Closure $otherwise = null): static
    {
        $this->whenClauses[] = [$condition, $then, $otherwise];

        return $this;
    }

    /**
     * 行全体を受け取って任意の値に変換するクロージャを設定する。
     *
     * when() が設定されている場合は when() 適用後の値が渡される。
     * map() が設定されている場合は map() 適用後の配列が渡される。
     *
     * 例:
     *   ->transform(fn($row) => new StaffData($row['姓'], $row['名']))
     *   ->transform(fn($row) => array_values($row))
     *
     * @param \Closure(mixed): mixed $closure
     */
    public function transform(\Closure $closure): static
    {
        $this->transform = $closure;

        return $this;
    }

    // =========================================================================
    // 読み込み
    // =========================================================================

    /**
     * 全行を Collection として返す。
     *
     * @return Collection<int, mixed>
     */
    public function rows(): Collection
    {
        return Collection::make($this->readAll());
    }

    /**
     * 全行に対してクロージャを実行する。戻り値は返さない。
     * cursor() ベースのため大きいファイルでもメモリ効率が良い。
     *
     * 例:
     *   ->each(function ($row) use ($service) { $service->import($row); })
     *
     * @param \Closure(mixed): void $callback
     */
    public function each(\Closure $callback): void
    {
        foreach ($this->cursor() as $row) {
            $callback($row);
        }
    }

    /**
     * 1行ずつ処理する LazyCollection を返す（大きいファイル向け）。
     *
     * @return LazyCollection<int, mixed>
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
     * @return array<int, mixed>
     */
    private function readAll(): array
    {
        return iterator_to_array($this->readGenerator(), false);
    }

    /**
     * ジェネレータで1行ずつ読み込む。
     * 適用順: map → filter → when → transform
     *
     * @return \Generator<int, mixed>
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

                // map()
                $mapped = $this->applyMap($row, $headers, $lineNumber);

                // filter()
                if ($this->filter !== null && !($this->filter)($mapped)) {
                    continue;
                }

                // when()
                $value = $this->applyWhen($mapped);

                // transform()
                yield $this->transform !== null ? ($this->transform)($value) : $value;
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
     * map() を適用してキー付き配列を返す。map が未設定の場合は元の行をそのまま返す。
     *
     * @param  string[]      $row
     * @param  string[]|null $headers
     * @param  int           $lineNumber
     * @return array<string|int, mixed>
     */
    private function applyMap(array $row, ?array $headers, int $lineNumber): array
    {
        // Excel数式形式を除去（="0120" → "0120"）
        $row = array_map(fn(string $v) => $this->stripExcelFormula($v), $row);

        // ヘッダーをキーにした連想配列に変換
        $assoc = null;
        if ($headers !== null) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $row[$i] ?? '';
            }
        }

        if (empty($this->map)) {
            return $assoc ?? $row;
        }

        $mapped = [];

        foreach ($this->map as $source => $target) {
            $value = is_int($source)
                ? ($row[$source] ?? '')
                : ($assoc[$source] ?? '');

            if ($target instanceof \Closure) {
                try {
                    $mapped[$source] = $target($value, $assoc ?? $row);
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
     * when() 節を順に評価し、最初にマッチした then/otherwise の戻り値を返す。
     * どの条件にもマッチしない場合は元の配列をそのまま返す。
     *
     * @param  array<string|int, mixed> $row
     * @return mixed
     */
    private function applyWhen(array $row): mixed
    {
        foreach ($this->whenClauses as [$condition, $then, $otherwise]) {
            if (($condition)($row)) {
                return ($then)($row);
            }

            if ($otherwise !== null) {
                return ($otherwise)($row);
            }
        }

        return $row;
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
