<?php

namespace Wttks\Csv\Exceptions;

/**
 * CSVファイルへの書き込みができない場合の例外。
 *
 * 発生ケース:
 *   - 書き込み先ディレクトリが存在しない
 *   - 書き込み権限がない
 *   - ファイルのオープン・書き込みに失敗した
 */
class CsvFileNotWritableException extends CsvException {}
