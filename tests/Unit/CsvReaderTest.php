<?php

namespace Wttks\Csv\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wttks\Csv\CsvConfig;
use Wttks\Csv\CsvReader;

class CsvReaderTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/../Fixtures';
    }

    // =========================================================================
    // エンコーディング自動判定
    // =========================================================================

    #[Test]
    public function UTF8ファイルを読み込める(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_utf8.csv")->rows();

        $this->assertCount(2, $rows);
        $this->assertSame('山田太郎', $rows[0]['氏名']);
        $this->assertSame('090-1234-5678', $rows[0]['電話番号']);
        $this->assertSame('鈴木花子', $rows[1]['氏名']);
    }

    #[Test]
    public function UTF8BOM付きファイルを読み込める(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_utf8bom.csv")->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('山田太郎', $rows[0]['氏名']);
    }

    #[Test]
    public function SJISファイルを自動判定して読み込める(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_sjis.csv")->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('山田太郎', $rows[0]['氏名']);
    }

    #[Test]
    public function エンコーディングを明示指定して読み込める(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_sjis.csv")
            ->encoding('SJIS-win')
            ->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('山田太郎', $rows[0]['氏名']);
    }

    // =========================================================================
    // ヘッダー処理
    // =========================================================================

    #[Test]
    public function ヘッダー行をキーとして返す(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_utf8.csv")->rows();

        $this->assertArrayHasKey('氏名', $rows[0]);
        $this->assertArrayHasKey('電話番号', $rows[0]);
        $this->assertArrayHasKey('郵便番号', $rows[0]);
    }

    #[Test]
    public function hasHeader_falseでインデックス配列を返す(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_utf8.csv")
            ->hasHeader(false)
            ->rows();

        // ヘッダー行もデータとして含まれる
        $this->assertSame('氏名', $rows[0][0]);
        $this->assertSame('山田太郎', $rows[1][0]);
    }

    // =========================================================================
    // カラムマッピング
    // =========================================================================

    #[Test]
    public function カラムマッピングでキー名を変換できる(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_utf8.csv")
            ->map([
                '氏名'     => 'name',
                '電話番号' => 'phone',
                '郵便番号' => 'zip',
            ])
            ->rows();

        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('phone', $rows[0]);
        $this->assertArrayHasKey('zip', $rows[0]);
        $this->assertSame('山田太郎', $rows[0]['name']);
        $this->assertSame('123-4567', $rows[0]['zip']);
    }

    #[Test]
    public function カラムマッピングでクロージャによる値変換ができる(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/simple_utf8.csv")
            ->map([
                '氏名'     => 'name',
                '郵便番号' => fn($v) => str_replace('-', '', $v),
            ])
            ->rows();

        $this->assertSame('山田太郎', $rows[0]['name']);
        $this->assertSame('1234567', $rows[0]['郵便番号']); // クロージャはキー名がヘッダー名になる
    }

    // =========================================================================
    // Excel数式形式
    // =========================================================================

    #[Test]
    public function Excel数式形式の先頭ゼロを正しく読み込む(): void
    {
        $rows = CsvReader::file("{$this->fixtures}/leading_zeros.csv")->rows();

        $this->assertSame('0120', $rows[0]['コード']);
        $this->assertSame('001', $rows[1]['コード']);
    }

    #[Test]
    public function excelFormula_falseでそのまま返す(): void
    {
        $config = CsvConfig::make()->excelFormula(false);
        $rows = CsvReader::file("{$this->fixtures}/leading_zeros.csv", $config)->rows();

        $this->assertSame('="0120"', $rows[0]['コード']);
    }

    // =========================================================================
    // 文字列から読み込み
    // =========================================================================

    #[Test]
    public function 文字列から読み込める(): void
    {
        $csv = "名前,年齢\n山田太郎,30\n鈴木花子,25\n";
        $rows = CsvReader::string($csv)->rows();

        $this->assertCount(2, $rows);
        $this->assertSame('山田太郎', $rows[0]['名前']);
        $this->assertSame('25', $rows[1]['年齢']);
    }

    // =========================================================================
    // TSV
    // =========================================================================

    #[Test]
    public function TSV形式を読み込める(): void
    {
        $tsv = "名前\t年齢\n山田太郎\t30\n";
        $rows = CsvReader::string($tsv, CsvConfig::tsv())->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('山田太郎', $rows[0]['名前']);
        $this->assertSame('30', $rows[0]['年齢']);
    }

    // =========================================================================
    // cursor (LazyCollection)
    // =========================================================================

    #[Test]
    public function cursorでストリーミング読み込みができる(): void
    {
        $count = 0;
        $first = null;

        foreach (CsvReader::file("{$this->fixtures}/simple_utf8.csv")->cursor() as $row) {
            if ($first === null) {
                $first = $row;
            }
            $count++;
        }

        $this->assertSame(2, $count);
        $this->assertSame('山田太郎', $first['氏名']);
    }

    // =========================================================================
    // 空行のスキップ
    // =========================================================================

    #[Test]
    public function 空行をスキップする(): void
    {
        $csv = "名前,年齢\n山田太郎,30\n\n鈴木花子,25\n";
        $rows = CsvReader::string($csv)->rows();

        $this->assertCount(2, $rows);
    }
}
