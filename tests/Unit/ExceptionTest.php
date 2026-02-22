<?php

namespace Wttks\Csv\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wttks\Csv\CsvConfig;
use Wttks\Csv\CsvReader;
use Wttks\Csv\CsvWriter;
use Wttks\Csv\Exceptions\CsvConfigException;
use Wttks\Csv\Exceptions\CsvEncodingException;
use Wttks\Csv\Exceptions\CsvException;
use Wttks\Csv\Exceptions\CsvFileNotFoundException;
use Wttks\Csv\Exceptions\CsvFileNotReadableException;
use Wttks\Csv\Exceptions\CsvFileNotWritableException;
use Wttks\Csv\Exceptions\CsvMappingException;
use Wttks\Csv\Exceptions\CsvStateException;

class ExceptionTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/../Fixtures';
    }

    // =========================================================================
    // 継承構造
    // =========================================================================

    #[Test]
    public function 全ての例外はCsvExceptionを継承している(): void
    {
        $this->assertInstanceOf(CsvException::class, new CsvConfigException());
        $this->assertInstanceOf(CsvException::class, new CsvFileNotFoundException());
        $this->assertInstanceOf(CsvException::class, new CsvFileNotReadableException());
        $this->assertInstanceOf(CsvException::class, new CsvFileNotWritableException());
        $this->assertInstanceOf(CsvException::class, new CsvEncodingException());
        $this->assertInstanceOf(CsvException::class, new CsvMappingException());
        $this->assertInstanceOf(CsvException::class, new CsvStateException());
    }

    #[Test]
    public function CsvExceptionで全ての例外を補足できる(): void
    {
        $caught = [];

        $exceptions = [
            new CsvConfigException(),
            new CsvFileNotFoundException(),
            new CsvFileNotReadableException(),
            new CsvFileNotWritableException(),
            new CsvEncodingException(),
            new CsvMappingException(),
            new CsvStateException(),
        ];

        foreach ($exceptions as $e) {
            try {
                throw $e;
            } catch (CsvException) {
                $caught[] = $e::class;
            }
        }

        $this->assertCount(7, $caught);
    }

    // =========================================================================
    // CsvConfigException
    // =========================================================================

    #[Test]
    public function delimiterに空文字列を指定するとCsvConfigException(): void
    {
        $this->expectException(CsvConfigException::class);
        CsvConfig::make()->delimiter('');
    }

    #[Test]
    public function delimiterに複数文字を指定するとCsvConfigException(): void
    {
        $this->expectException(CsvConfigException::class);
        CsvConfig::make()->delimiter(',,');
    }

    #[Test]
    public function enclosureに空文字列を指定するとCsvConfigException(): void
    {
        $this->expectException(CsvConfigException::class);
        CsvConfig::make()->enclosure('');
    }

    #[Test]
    public function escapeに複数文字を指定するとCsvConfigException(): void
    {
        $this->expectException(CsvConfigException::class);
        CsvConfig::make()->escape('\\\\');
    }

    #[Test]
    public function writeEncodingに未対応値を指定するとCsvConfigException(): void
    {
        $this->expectException(CsvConfigException::class);
        CsvConfig::make()->writeEncoding('UNKNOWN');
    }

    // =========================================================================
    // CsvFileNotFoundException
    // =========================================================================

    #[Test]
    public function 存在しないファイルを指定するとCsvFileNotFoundException(): void
    {
        $this->expectException(CsvFileNotFoundException::class);
        CsvReader::file('/tmp/not_exists_' . uniqid() . '.csv');
    }

    // =========================================================================
    // CsvFileNotWritableException
    // =========================================================================

    #[Test]
    public function 存在しないディレクトリへの書き込みはCsvFileNotWritableException(): void
    {
        $this->expectException(CsvFileNotWritableException::class);
        CsvWriter::file('/tmp/no_such_dir_' . uniqid() . '/output.csv');
    }

    // =========================================================================
    // CsvEncodingException
    // =========================================================================

    #[Test]
    public function 未対応エンコーディングを指定するとCsvEncodingException(): void
    {
        $this->expectException(CsvEncodingException::class);
        CsvReader::file("{$this->fixtures}/simple_utf8.csv")->encoding('UNKNOWN');
    }

    // =========================================================================
    // CsvMappingException
    // =========================================================================

    #[Test]
    public function mapの値に不正な型を指定するとCsvMappingException_Reader(): void
    {
        $this->expectException(CsvMappingException::class);
        CsvReader::file("{$this->fixtures}/simple_utf8.csv")->map(['氏名' => 123]);
    }

    #[Test]
    public function mapの値に不正な型を指定するとCsvMappingException_Writer(): void
    {
        $this->expectException(CsvMappingException::class);
        CsvWriter::toString()->map(['氏名' => 123]);
    }

    #[Test]
    public function mapのクロージャが例外をスローするとCsvMappingException_Reader(): void
    {
        $this->expectException(CsvMappingException::class);

        CsvReader::file("{$this->fixtures}/simple_utf8.csv")
            ->map(['氏名' => fn($v) => throw new \RuntimeException('変換エラー')])
            ->rows();
    }

    #[Test]
    public function mapのクロージャが例外をスローするとCsvMappingException_Writer(): void
    {
        $this->expectException(CsvMappingException::class);

        CsvWriter::toString()
            ->map(['氏名' => fn($item) => throw new \RuntimeException('変換エラー')])
            ->get([['name' => '山田']]);
    }

    // =========================================================================
    // CsvStateException
    // =========================================================================

    #[Test]
    public function fileなしでwriteを呼ぶとCsvStateException(): void
    {
        $this->expectException(CsvStateException::class);
        CsvWriter::toString()->map(['氏名' => 'name'])->write([]);
    }

    #[Test]
    public function fileなしでopenを呼ぶとCsvStateException(): void
    {
        $this->expectException(CsvStateException::class);
        CsvWriter::toString()->map(['氏名' => 'name'])->open();
    }

    #[Test]
    public function openなしでaddを呼ぶとCsvStateException(): void
    {
        $this->expectException(CsvStateException::class);

        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        try {
            CsvWriter::file($tmpFile)->map(['氏名' => 'name'])->add([]);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function open後に設定変更するとCsvStateException(): void
    {
        $this->expectException(CsvStateException::class);

        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        try {
            $writer = CsvWriter::file($tmpFile)->map(['氏名' => 'name'])->open();
            $writer->delimiter(';'); // open() 後の設定変更は禁止
        } finally {
            $writer->close();
            unlink($tmpFile);
        }
    }

    #[Test]
    public function 二重openはCsvStateException(): void
    {
        $this->expectException(CsvStateException::class);

        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        try {
            $writer = CsvWriter::file($tmpFile)->map(['氏名' => 'name'])->open();
            $writer->open(); // 二重 open は禁止
        } finally {
            $writer->close();
            unlink($tmpFile);
        }
    }
}
