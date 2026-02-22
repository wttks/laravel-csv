<?php

namespace Wttks\Csv;

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
    ) {}

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
}
