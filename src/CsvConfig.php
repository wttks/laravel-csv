<?php

namespace Wttks\Csv;

use Wttks\Csv\Exceptions\CsvConfigException;

/**
 * CSV/TSV の読み書き設定クラス。
 *
 * デフォルト値は Excel の動作に準拠:
 *   - 区切り文字: カンマ
 *   - 囲み文字: ダブルクォート
 *   - エスケープ: 空文字列（囲み文字の重複でエスケープ: "" → "）
 *   - 書き込みエンコーディング: UTF-8 BOM付き（Excelで文字化けしない）
 *   - Excel数式形式: 有効（先頭ゼロ数字を ="0120" 形式で保護）
 */
class CsvConfig
{
    /** 書き込みエンコーディングとして指定できる値 */
    private const VALID_WRITE_ENCODINGS = [
        'UTF-8-BOM',
        'UTF-8',
        'SJIS-win',
        'eucJP-win',
    ];

    public function __construct(
        /** 区切り文字（デフォルト: カンマ） */
        public readonly string $delimiter = ',',

        /** フィールド囲み文字（デフォルト: ダブルクォート） */
        public readonly string $enclosure = '"',

        /**
         * エスケープ文字。
         * 空文字列 = Excel互換モード（囲み文字を重複してエスケープ: "" → "）。
         * '\' = PHP標準モード（バックスラッシュでエスケープ）。
         */
        public readonly string $escape = '',

        /**
         * 書き込み時のエンコーディング。
         * 'UTF-8-BOM' / 'UTF-8' / 'SJIS-win' / 'eucJP-win'
         */
        public readonly string $writeEncoding = 'UTF-8-BOM',

        /**
         * Excel数式形式の有効/無効。
         * 有効: 先頭ゼロの数字文字列を ="0120" 形式で書き出し、読み込み時に除去する。
         */
        public readonly bool $excelFormula = true,

        /** 1行目をヘッダー行として扱うか */
        public readonly bool $hasHeader = true,
    ) {
        $this->validateDelimiter($delimiter);
        $this->validateEnclosure($enclosure);
        $this->validateEscape($escape);
        $this->validateWriteEncoding($writeEncoding);
    }

    /**
     * デフォルト設定（Excel互換）でインスタンスを生成する。
     */
    public static function make(): static
    {
        return new static();
    }

    /**
     * TSV用のプリセット設定を返す。
     */
    public static function tsv(): static
    {
        return new static(delimiter: "\t");
    }

    // =========================================================================
    // フルーエントセッター（新しいインスタンスを返す）
    // =========================================================================

    public function delimiter(string $delimiter): static
    {
        return new static($delimiter, $this->enclosure, $this->escape, $this->writeEncoding, $this->excelFormula, $this->hasHeader);
    }

    public function enclosure(string $enclosure): static
    {
        return new static($this->delimiter, $enclosure, $this->escape, $this->writeEncoding, $this->excelFormula, $this->hasHeader);
    }

    public function escape(string $escape): static
    {
        return new static($this->delimiter, $this->enclosure, $escape, $this->writeEncoding, $this->excelFormula, $this->hasHeader);
    }

    public function writeEncoding(string $encoding): static
    {
        return new static($this->delimiter, $this->enclosure, $this->escape, $encoding, $this->excelFormula, $this->hasHeader);
    }

    public function excelFormula(bool $enabled = true): static
    {
        return new static($this->delimiter, $this->enclosure, $this->escape, $this->writeEncoding, $enabled, $this->hasHeader);
    }

    public function hasHeader(bool $enabled = true): static
    {
        return new static($this->delimiter, $this->enclosure, $this->escape, $this->writeEncoding, $this->excelFormula, $enabled);
    }

    // =========================================================================
    // バリデーション
    // =========================================================================

    private function validateDelimiter(string $delimiter): void
    {
        if ($delimiter === '') {
            throw new CsvConfigException('区切り文字（delimiter）に空文字列は指定できません。');
        }

        if (mb_strlen($delimiter, 'UTF-8') !== 1) {
            throw new CsvConfigException(
                "区切り文字（delimiter）は1文字で指定してください。指定値: \"{$delimiter}\""
            );
        }
    }

    private function validateEnclosure(string $enclosure): void
    {
        if ($enclosure === '') {
            throw new CsvConfigException('囲み文字（enclosure）に空文字列は指定できません。');
        }

        if (mb_strlen($enclosure, 'UTF-8') !== 1) {
            throw new CsvConfigException(
                "囲み文字（enclosure）は1文字で指定してください。指定値: \"{$enclosure}\""
            );
        }
    }

    private function validateEscape(string $escape): void
    {
        // 空文字列（Excel互換モード）は有効
        if ($escape === '') {
            return;
        }

        if (mb_strlen($escape, 'UTF-8') !== 1) {
            throw new CsvConfigException(
                "エスケープ文字（escape）は空文字列または1文字で指定してください。指定値: \"{$escape}\""
            );
        }
    }

    private function validateWriteEncoding(string $encoding): void
    {
        if (!in_array($encoding, self::VALID_WRITE_ENCODINGS, true)) {
            $valid = implode(', ', self::VALID_WRITE_ENCODINGS);
            throw new CsvConfigException(
                "未対応の書き込みエンコーディングです: \"{$encoding}\"。指定可能な値: {$valid}"
            );
        }
    }
}
