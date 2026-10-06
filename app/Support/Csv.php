<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV download that opens correctly in Excel: UTF-8 with a BOM (otherwise Thai text
 * turns into mojibake), and text cells that start with = + - @ are prefixed with an
 * apostrophe so a project/comment name can't run as a spreadsheet formula.
 * Numbers are passed through untouched.
 */
class Csv
{
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
