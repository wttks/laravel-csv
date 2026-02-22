<?php

namespace Wttks\Csv\Exceptions;

/**
 * エンコーディングの判定・変換に失敗した場合の例外。
 *
 * 発生ケース:
 *   - 読み込み時のエンコーディング自動判定に失敗した
 *   - SJIS-win / eucJP-win → UTF-8 変換に失敗した
 *   - UTF-8 → SJIS-win / eucJP-win 変換に失敗した（変換不可能な文字を含む場合）
 */
class CsvEncodingException extends CsvException {}
