<?php

namespace Tests\Unit\Support;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CsvTest extends TestCase
{
    /**
     * @return array<string,array{0:mixed,1:string}>
     */
    public static function cells(): array
    {
        return [
            'a formula' => ['=1+1', "'=1+1"],
            'a plus' => ['+44 20 7946 0958', "'+44 20 7946 0958"],
            'a minus' => ['-2+3', "'-2+3"],
            'an at sign' => ['@SUM(A1)', "'@SUM(A1)"],
            'a tab' => ["\tcmd", "'\tcmd"],
            'a carriage return' => ["\rcmd", "'\rcmd"],
            'a command' => ['=cmd|"/c calc"!A1', '\'=cmd|"/c calc"!A1'],
            'ordinary text' => ['Sara Al-Ahmad', 'Sara Al-Ahmad'],
            'Arabic text' => ['سارة', 'سارة'],
            'a number' => [12.5, '12.5'],
            'an integer' => [0, '0'],
            'a sign inside the text' => ['a=b', 'a=b'],
            'null' => [null, ''],
            'empty' => ['', ''],
            'false' => [false, ''],
        ];
    }

    #[DataProvider('cells')]
    public function test_a_cell_cannot_begin_a_formula(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Csv::cell($value));
    }

    public function test_a_download_starts_with_a_byte_order_mark_and_quotes_what_needs_it(): void
    {
        $response = Csv::download('x.csv', ['Name', 'Note'], [['Sara, "the" buyer', "line one\nline two"], ['=evil', 'ok']]);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBFName,Note\n", $content);
        $this->assertStringContainsString('"Sara, ""the"" buyer"', $content);
        $this->assertStringContainsString("\"line one\nline two\"", $content);
        $this->assertStringContainsString("'=evil,ok", $content);
        $this->assertStringContainsString('text/csv; charset=UTF-8', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('x.csv', (string) $response->headers->get('Content-Disposition'));
    }
}
