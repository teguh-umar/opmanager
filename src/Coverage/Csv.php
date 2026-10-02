<?php

namespace GlpiPlugin\Opmanager\Coverage;

/** Pure: Excel-friendly CSV (UTF-8 BOM, ';'), formula cells neutralised. */
final class Csv
{
    /**
     * @param list<string>            $header
     * @param list<list<scalar|null>> $rows
     */
    public static function render(array $header, array $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }
        return $out;
    }

    /** @param list<scalar|null> $cells */
    private static function line(array $cells): string
    {
        $cells = array_map(static function ($v): string {
            $v = (string) $v;
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                $v = "'" . $v;
            }
            return preg_match('/[;"\r\n]/', $v) === 1 ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }, $cells);
        return implode(';', $cells) . "\r\n";
    }
}
