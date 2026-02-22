<?php

namespace Wttks\Csv\Exceptions;

/**
 * カラムマッピングのクロージャ内で例外が発生した場合の例外。
 *
 * 発生ケース:
 *   - CsvReader::map() に渡したクロージャが例外をスローした
 *   - CsvWriter::map() に渡したクロージャが例外をスローした
 */
class CsvMappingException extends CsvException {}
