<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UTF-8 CSV with a BOM, so Excel opens Georgian text correctly. Cells that
 * a spreadsheet would evaluate as a formula are prefixed with an
 * apostrophe (CSV injection).
 */
class CsvDownload
{
    /**
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, string|int|null>>  $rows
     */
    public static function make(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new \RuntimeException('Cannot open the output stream.');
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function cell(string|int|null $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)
            ? "'".$value
            : $value;
    }
}
