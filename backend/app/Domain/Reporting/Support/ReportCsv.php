<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Accounting\Support\CsvExporter;

/**
 * The CSV form of the OA5 reports. It only lays out a report array the service already produced (same figures as the screen, same user, same data
 * scope): no query and no arithmetic. Headers are Indonesian like the other exports.
 *
 * A statement is a flat table whose first column says what a row is, so a spreadsheet can filter it:
 *   Baris       a line of the statement (its amount in "Jumlah baris")
 *   Akun        an account under the line above it (its amount in "Jumlah akun", never added to the line, which already includes it)
 *   Kontrol     an integrity figure of the statement (balanced, difference, unmapped accounts, ...), with the report's `complete` flag
 *   Peringatan  a warning the screen shows
 * so an export of a partial or unreliable statement can never pass for a clean one.
 */
final class ReportCsv
{
    private const ROW = 'Baris';

    private const ACCOUNT = 'Akun';

    private const CONTROL = 'Kontrol';

    private const WARNING = 'Peringatan';

    private const TITLES = ['BALANCE_SHEET' => 'neraca', 'PROFIT_LOSS' => 'laba-rugi', 'CASH_FLOW' => 'arus-kas'];

    /**
     * @param  array<string,mixed>  $report  a balance sheet, profit and loss or cash flow report
     * @return array{filename:string,header:list<string>,rows:list<list<mixed>>}
     */
    public static function statement(array $report): array
    {
        $rows = [];
        foreach ($report['lines'] as $line) {
            $rows[] = [self::ROW, $line['code'], $line['label'], $line['line_type'], $line['depth'], '', '', self::amount($line['amount']), ''];
            foreach ($line['accounts'] as $account) {
                $rows[] = [self::ACCOUNT, $line['code'], '', '', $line['depth'] + 1, $account['code'], $account['name'], '', self::amount($account['amount'])];
            }
        }
        $rows[] = [self::CONTROL, 'complete', '', '', '', '', '', self::text($report['complete']), ''];
        foreach (self::integrity($report['integrity']) as [$key, $value, $account, $note]) {
            $rows[] = $account === null
                ? [self::CONTROL, $key, $note, '', '', '', '', self::text($value), '']
                : [self::CONTROL, $key, $note, '', '', $account['code'], $account['name'], '', self::text($value)];
        }
        foreach ($report['warnings'] as $warning) {
            $rows[] = [self::WARNING, $warning['code'], $warning['message'], '', '', '', '', '', ''];
        }

        $dated = isset($report['as_of']) ? [$report['as_of']] : [$report['range']['from'], $report['range']['to']];

        return [
            'filename' => self::TITLES[$report['statement']].'_'.implode('_', $dated).'.csv',
            'header' => ['Jenis', 'Kode baris', 'Uraian', 'Tipe baris', 'Kedalaman', 'Kode akun', 'Nama akun', 'Jumlah baris', 'Jumlah akun'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $report  a comparative report
     * @return array{filename:string,header:list<string>,rows:list<list<mixed>>}
     */
    public static function comparative(array $report): array
    {
        $rows = [];
        foreach ($report['lines'] as $line) {
            $rows[] = [self::ROW, $line['code'], $line['label'], $line['line_type'], $line['depth'], '', '', self::amount($line['current']), self::amount($line['comparison']), self::amount($line['variance']), self::amount($line['variance_pct'])];
            foreach ($line['accounts'] as $account) {
                $rows[] = [self::ACCOUNT, $line['code'], '', '', $line['depth'] + 1, $account['code'], $account['name'], self::amount($account['current']), self::amount($account['comparison']), self::amount($account['variance']), self::amount($account['variance_pct'])];
            }
        }
        $rows[] = [self::CONTROL, 'complete', '', '', '', '', '', self::text($report['complete']), '', '', ''];
        foreach (self::integrity($report['integrity']) as [$key, $value, $account, $note]) {
            $current = str_starts_with($key, 'current.');
            $cells = [self::text($value), ''];
            $rows[] = [self::CONTROL, $key, $note, '', '', $account['code'] ?? '', $account['name'] ?? '', ...($current ? $cells : array_reverse($cells)), '', ''];
        }
        foreach ($report['warnings'] as $warning) {
            $rows[] = [self::WARNING, $warning['code'], $warning['message'], '', '', '', '', '', '', '', ''];
        }

        $dated = isset($report['as_of']) ? [$report['as_of']] : [$report['range']['from'], $report['range']['to']];
        $slug = self::TITLES[$report['statement']].'-'.strtolower(str_replace('_', '-', $report['mode']));

        return [
            'filename' => "perbandingan-{$slug}_".implode('_', $dated).'.csv',
            'header' => ['Jenis', 'Kode baris', 'Uraian', 'Tipe baris', 'Kedalaman', 'Kode akun', 'Nama akun', 'Berjalan', 'Pembanding', 'Selisih', 'Selisih %'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $report  an account balance report
     * @return array{filename:string,header:list<string>,rows:list<list<mixed>>}
     */
    public static function accountBalance(array $report): array
    {
        $asOf = $report['mode'] === 'AS_OF';
        $header = $asOf
            ? ['Kode akun', 'Nama akun', 'Tipe', 'Saldo normal', 'Saldo']
            : ['Kode akun', 'Nama akun', 'Tipe', 'Saldo normal', 'Saldo awal', 'Debit', 'Kredit', 'Saldo akhir'];

        $rows = [];
        foreach ($report['data'] as $r) {
            $rows[] = $asOf
                ? [$r['code'], $r['name'], $r['account_type'], $r['normal_balance'], self::amount($r['balance'])]
                : [$r['code'], $r['name'], $r['account_type'], $r['normal_balance'], self::amount($r['opening']), self::amount($r['debit']), self::amount($r['credit']), self::amount($r['closing'])];
        }
        $t = $report['totals'];
        $rows[] = $asOf
            ? ['', 'Total', '', $t['normal_balance'], self::amount($t['balance'])]
            : ['', 'Total', '', $t['normal_balance'], self::amount($t['opening']), self::amount($t['debit']), self::amount($t['credit']), self::amount($t['closing'])];
        $rows[] = array_pad(['', 'Cakupan lengkap', '', '', self::text($report['complete'])], count($header), '');

        return [
            'filename' => 'saldo-akun_'.($asOf ? $report['as_of'] : $report['range']['from'].'_'.$report['range']['to']).'.csv',
            'header' => $header,
            'rows' => $rows,
        ];
    }

    /**
     * The integrity block of a report as flat entries [key, value, account|null, note]: scalars as they are, maps as `key.subkey`, lists of accounts one
     * entry per account.
     *
     * @param  array<string,mixed>  $block
     * @return list<array{0:string,1:mixed,2:?array{code:string,name:string},3:string}>
     */
    private static function integrity(array $block, string $prefix = ''): array
    {
        $out = [];
        foreach ($block as $key => $value) {
            $name = $prefix.$key;
            if (! is_array($value)) {
                $out[] = [$name, $value, null, ''];

                continue;
            }
            if ($value === []) {
                continue;
            }
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    $account = ['code' => $item['code'], 'name' => $item['name']];
                    if (isset($item['closing'])) { // a cash account: opening, movement and closing
                        foreach (['opening', 'movement', 'closing'] as $field) {
                            $out[] = ["{$name}.{$field}", $item[$field], $account, ''];
                        }
                    } else {
                        $out[] = [$name, $item['amount'] ?? $item['budget'] ?? '', $account, (string) ($item['reason'] ?? '')];
                    }
                }

                continue;
            }
            array_push($out, ...self::integrity($value, "{$name}."));
        }

        return $out;
    }

    /** A decimal string as a numeric cell, null as an empty one. */
    private static function amount(?string $value): mixed
    {
        return $value === null ? '' : CsvExporter::number($value);
    }

    /** A control value: decimal strings stay numeric, booleans read Ya / Tidak, null is empty. */
    private static function text(mixed $value): mixed
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 => CsvExporter::number($value),
            default => (string) $value,
        };
    }
}
