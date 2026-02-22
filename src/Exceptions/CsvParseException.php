<?php

namespace Wttks\Csv\Exceptions;

/**
 * CSV の解析中にエラーが発生した場合の例外。
 *
 * 発生ケース:
 *   - fgetcsv がEOF以外の理由で false を返した（IO エラー等）
 */
class CsvParseException extends CsvException {}
