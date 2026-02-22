<?php

namespace Wttks\Csv\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wttks\Csv\CsvConfig;
use Wttks\Csv\CsvReader;
use Wttks\Csv\CsvWriter;

class CsvWriterTest extends TestCase
{
    // =========================================================================
    // 基本的な書き込み
    // =========================================================================

    #[Test]
    public function 連想配列をCSV文字列に変換できる(): void
    {
        $rows = [
            ['name' => '山田太郎', 'phone' => '090-1234-5678'],
            ['name' => '鈴木花子', 'phone' => '080-9876-5432'],
        ];

        $csv = CsvWriter::toString()
            ->map(['氏名' => 'name', '電話番号' => 'phone'])
            ->get($rows);

        $this->assertStringContainsString('氏名,電話番号', $csv);
        $this->assertStringContainsString('山田太郎,090-1234-5678', $csv);
        $this->assertStringContainsString('鈴木花子,080-9876-5432', $csv);
    }

    #[Test]
    public function オブジェクトのプロパティからCSVを生成できる(): void
    {
        $item = new \stdClass();
        $item->name = '山田太郎';
        $item->age  = 30;

        $csv = CsvWriter::toString()
            ->map(['氏名' => 'name', '年齢' => 'age'])
            ->get([$item]);

        $this->assertStringContainsString('山田太郎', $csv);
        $this->assertStringContainsString('30', $csv);
    }

    #[Test]
    public function クロージャで値を加工できる(): void
    {
        $rows = [['zip' => '1234567']];

        $csv = CsvWriter::toString()
            ->map([
                '郵便番号' => fn($item) => substr($item['zip'], 0, 3) . '-' . substr($item['zip'], 3),
            ])
            ->get($rows);

        $this->assertStringContainsString('123-4567', $csv);
    }

    // =========================================================================
    // Excel数式形式（先頭ゼロ）
    // =========================================================================

    #[Test]
    public function 先頭ゼロの数字文字列をExcel数式形式で出力する(): void
    {
        $rows = [['code' => '0120', 'name' => 'フリーダイヤル']];

        $csv = CsvWriter::toString()
            ->map(['コード' => 'code', '名前' => 'name'])
            ->get($rows);

        $this->assertStringContainsString('="0120"', $csv);
        $this->assertStringContainsString('フリーダイヤル', $csv);
    }

    #[Test]
    public function 先頭ゼロなし数字はExcel数式形式にしない(): void
    {
        $rows = [['code' => '1234']];

        $csv = CsvWriter::toString()
            ->map(['コード' => 'code'])
            ->get($rows);

        $this->assertStringNotContainsString('="', $csv);
        $this->assertStringContainsString('1234', $csv);
    }

    #[Test]
    public function excelFormula_falseで先頭ゼロもそのまま出力する(): void
    {
        $rows = [['code' => '0120']];

        $csv = CsvWriter::toString()
            ->excelFormula(false)
            ->map(['コード' => 'code'])
            ->get($rows);

        $this->assertStringNotContainsString('="', $csv);
        $this->assertStringContainsString('0120', $csv);
    }

    // =========================================================================
    // ヘッダー
    // =========================================================================

    #[Test]
    public function hasHeader_falseでヘッダー行なしで出力する(): void
    {
        $rows = [['name' => '山田太郎']];

        $csv = CsvWriter::toString()
            ->hasHeader(false)
            ->map(['氏名' => 'name'])
            ->get($rows);

        $this->assertStringNotContainsString('氏名', $csv);
        $this->assertStringContainsString('山田太郎', $csv);
    }

    // =========================================================================
    // エンコーディング
    // =========================================================================

    #[Test]
    public function デフォルトでUTF8BOM付きで書き出す(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');

        try {
            CsvWriter::file($tmpFile)
                ->map(['名前' => 'name'])
                ->write([['name' => '山田太郎']]);

            $content = file_get_contents($tmpFile);
            // UTF-8 BOM（EF BB BF）で始まる
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function SJISで書き出せる(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');

        try {
            CsvWriter::file($tmpFile)
                ->encoding('SJIS-win')
                ->map(['名前' => 'name'])
                ->write([['name' => '山田太郎']]);

            $content = file_get_contents($tmpFile);
            // SJIS にデコードできる
            $decoded = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
            $this->assertStringContainsString('山田太郎', $decoded);
            // UTF-8 バイト列ではない
            $this->assertStringNotContainsString('山田太郎', $content);
        } finally {
            unlink($tmpFile);
        }
    }

    // =========================================================================
    // TSV
    // =========================================================================

    #[Test]
    public function TSV形式で出力できる(): void
    {
        $rows = [['name' => '山田太郎', 'age' => '30']];

        $tsv = CsvWriter::toString(CsvConfig::tsv())
            ->map(['名前' => 'name', '年齢' => 'age'])
            ->get($rows);

        $this->assertStringContainsString("名前\t年齢", $tsv);
        $this->assertStringContainsString("山田太郎\t30", $tsv);
    }

    // =========================================================================
    // open / add / close（分割書き込み）
    // =========================================================================

    #[Test]
    public function open_add_closeで分割書き込みができる(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');

        try {
            $writer = CsvWriter::file($tmpFile, CsvConfig::make()->writeEncoding('UTF-8'))
                ->map(['氏名' => 'name', '年齢' => 'age'])
                ->open();

            $writer->add([['name' => '山田太郎', 'age' => '30']]);
            $writer->add([['name' => '鈴木花子', 'age' => '25']]);
            $writer->add([['name' => '田中一郎', 'age' => '40']]);
            $writer->close();

            $rows = CsvReader::file($tmpFile)->rows();

            $this->assertCount(3, $rows);
            $this->assertSame('山田太郎', $rows[0]['氏名']);
            $this->assertSame('鈴木花子', $rows[1]['氏名']);
            $this->assertSame('田中一郎', $rows[2]['氏名']);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function addでチャンクごとに書き込める(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');

        try {
            $writer = CsvWriter::file($tmpFile, CsvConfig::make()->writeEncoding('UTF-8'))
                ->map(['コード' => 'code', '名前' => 'name'])
                ->open();

            // 複数チャンクに分けて書き込む
            $chunk1 = [['code' => '0120', 'name' => 'フリーダイヤル']];
            $chunk2 = [['code' => '001',  'name' => 'テスト'], ['code' => '1234', 'name' => '通常']];
            $writer->add($chunk1)->add($chunk2);
            $writer->close();

            $rows = CsvReader::file($tmpFile)->rows();

            $this->assertCount(3, $rows);
            $this->assertSame('0120', $rows[0]['コード']);
            $this->assertSame('001',  $rows[1]['コード']);
            $this->assertSame('1234', $rows[2]['コード']);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function open前にaddを呼ぶと例外が発生する(): void
    {
        $this->expectException(\RuntimeException::class);

        CsvWriter::file('/tmp/test.csv')
            ->map(['氏名' => 'name'])
            ->add([['name' => '山田']]);
    }

    // =========================================================================
    // 読み書き往復
    // =========================================================================

    #[Test]
    public function 書き出したCSVを読み込むと元のデータに戻る(): void
    {
        $original = [
            ['code' => '0120', 'name' => 'フリーダイヤル'],
            ['code' => '001',  'name' => 'テスト'],
            ['code' => '1234', 'name' => '通常コード'],
        ];

        $csv = CsvWriter::toString(CsvConfig::make()->writeEncoding('UTF-8'))
            ->map(['コード' => 'code', '名前' => 'name'])
            ->get($original);

        $rows = CsvReader::string($csv)->rows();

        $this->assertSame('0120', $rows[0]['コード']);
        $this->assertSame('001',  $rows[1]['コード']);
        $this->assertSame('1234', $rows[2]['コード']);
        $this->assertSame('フリーダイヤル', $rows[0]['名前']);
    }
}
