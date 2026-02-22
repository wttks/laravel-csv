<?php

namespace Wttks\Csv\Exceptions;

/**
 * メソッドの呼び出し順序が不正な場合の例外。
 *
 * 発生ケース:
 *   - open() を呼ぶ前に add() を呼んだ
 *   - すでに open() 済みの状態で再度 open() を呼んだ
 *   - open() 後に設定変更メソッド（delimiter() 等）を呼んだ
 *   - file() を指定せずに write() / open() を呼んだ
 */
class CsvStateException extends CsvException {}
