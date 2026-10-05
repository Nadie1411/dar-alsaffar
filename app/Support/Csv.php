<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writing CSV that is safe to open in a spreadsheet.
 */
class Csv
{
    /**
     * A spreadsheet treats a cell that begins with =, +, - or @ as a formula,
     * so a customer who types one into their name could make an export run it
     * on whoever opens the file. A leading apostrophe turns it back into text.
     */
    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }

    /**
     * Streams rows as a download. The byte-order mark is what makes Excel
     * read the Arabic as UTF-8 instead of garbling it.
     *
     * @param  array<int,string>  $header
     * @param  iterable<int,array<int,mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map([self::class, 'cell'], $header), ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
