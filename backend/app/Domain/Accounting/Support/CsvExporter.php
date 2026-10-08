<?php

namespace App\Domain\Accounting\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV download for reports. Text that a spreadsheet could execute as a formula (starts with = + - @ tab or CR) is
 * neutralised with a leading apostrophe; amounts are wrapped with number() and pass through untouched.
 */
final class CsvExporter
{
    /** A numeric cell (already a plain decimal string produced by the backend). */
    public static function number(string $value): object
    {
        return new class($value)
        {
            public function __construct(public readonly string $value) {}
        };
    }

    public static function cell(mixed $value): string
    {
        if (is_object($value) && property_exists($value, 'value')) {
            return $value->value;
        }
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true) ? "'".$text : $text;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<mixed>>  $rows
     */
    public static function stream(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheets read Indonesian text correctly
            fputcsv($out, array_map([self::class, 'cell'], $header), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $filename).'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
