<?php

namespace App\Domain\Integrations\EduPage;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Reads an EduPage export into rows of cells. Two formats are understood:
 *
 * - CSV („ნახვა - CSV“ in Agenda): UTF-8 with or without BOM; the delimiter
 *   (`,` `;` or tab) is detected from the header line.
 * - The timetable's „XLS“ export, which is really a MIME quoted-printable
 *   HTML worksheet, or a plain HTML table. `colspan` is kept per cell so a
 *   double lesson stays one cell spanning two periods.
 *
 * Binary XLS/XLSX workbooks are refused rather than half-read: re-export
 * them as CSV.
 */
class SourceTable
{
    /**
     * @param  list<list<array{text: string, span: int}>>  $rows
     */
    private function __construct(public readonly array $rows) {}

    public static function fromFile(string $path): self
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException('ფაილი ვერ წაიკითხა.');
        }

        if (str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException('ბინარული XLS/XLSX არ მიიღება — გადმოწერეთ CSV ფორმატში.');
        }

        $head = ltrim(substr($bytes, 0, 2048));

        if (str_starts_with($head, 'MIME-Version:') || stripos($head, '<html') !== false || stripos($head, '<table') !== false) {
            return self::fromHtml($bytes);
        }

        return self::fromCsv($bytes);
    }

    private static function fromCsv(string $bytes): self
    {
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;
        $firstLine = strtok($bytes, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])
            ->sortByDesc(fn (string $candidate) => substr_count($firstLine, $candidate))
            ->first();

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('ფაილი ვერ წაიკითხა.');
        }

        fwrite($handle, $bytes);
        rewind($handle);

        $rows = [];

        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }

            $rows[] = array_map(fn ($cell) => ['text' => self::clean((string) $cell), 'span' => 1], $cells);
        }

        fclose($handle);

        return new self($rows);
    }

    private static function fromHtml(string $bytes): self
    {
        if (str_starts_with(ltrim($bytes), 'MIME-Version:')) {
            $parts = preg_split("/\r?\n\r?\n/", $bytes, 2);
            $headers = $parts[0] ?? '';
            $bytes = $parts[1] ?? '';

            if (stripos($headers, 'quoted-printable') !== false) {
                $bytes = quoted_printable_decode($bytes);
            }
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $rows = [];

        foreach ((new DOMXPath($document))->query('//tr') ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            $row = [];

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $row[] = [
                        'text' => self::clean($cell->textContent),
                        'span' => max(1, (int) ($cell->getAttribute('colspan') ?: 1)),
                    ];
                }
            }

            $rows[] = $row;
        }

        return new self($rows);
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * The first row as headers, every later non-empty row keyed by them.
     *
     * @return array{headers: list<string>, records: list<array{row: int, cells: array<string, string>}>}
     */
    public function records(): array
    {
        $headers = array_map(fn (array $cell) => $cell['text'], $this->rows[0] ?? []);
        $records = [];

        foreach (array_slice($this->rows, 1) as $index => $row) {
            $texts = array_map(fn (array $cell) => $cell['text'], $row);

            if (implode('', $texts) === '') {
                continue;
            }

            $cells = [];

            foreach ($headers as $position => $header) {
                $cells[$header] = $texts[$position] ?? '';
            }

            // Row 1 is the header, so data rows start at 2 — as a person sees it in a spreadsheet.
            $records[] = ['row' => $index + 2, 'cells' => $cells];
        }

        return ['headers' => $headers, 'records' => $records];
    }
}
