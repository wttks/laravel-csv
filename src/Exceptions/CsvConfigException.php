<?php

namespace Wttks\Csv\Exceptions;

/**
 * CSV設定値が不正な場合の例外。
 *
 * 発生ケース:
 *   - delimiter / enclosure に空文字列または複数文字を指定した
 *   - escape に複数文字を指定した
 *   - writeEncoding に未対応のエンコーディング名を指定した
 */
class CsvConfigException extends CsvException {}
