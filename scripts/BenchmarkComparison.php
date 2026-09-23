<?php

declare(strict_types=1);

final class UcBenchComparison
{
    public static function metricValues(array $rows, string $metric, bool $allowZero = false): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (!isset($row['backend']) || !is_numeric($row[$metric] ?? null)) {
                continue;
            }
            $value = (float) $row[$metric];
            if (is_finite($value) && ($value > 0.0 || ($allowZero && $value === 0.0))) {
                $values[(string) $row['backend']] = $value;
            }
        }
        asort($values, SORT_NUMERIC);
        return $values;
    }

    public static function fasterCell(array $rows, string $metric, callable $label): string
    {
        $values = self::metricValues($rows, $metric);
        if ($values === []) {
            return '<span class="muted">n/a</span>';
        }
        $best = reset($values);
        $html = '';
        foreach ($values as $backend => $value) {
            $html .= '<span class="ranking">' . self::h($label($backend)) . ' '
                . number_format($value / $best, 2, '.', ',') . 'x</span>';
        }
        return $html;
    }

    public static function memoryTable(array $rows, callable $label): string
    {
        $groups = [];
        $backends = [];
        foreach ($rows as $row) {
            if (!isset($row['value_shm_bytes']) && !isset($row['fetch_retained_bytes'])) {
                continue;
            }
            $groups[$row['case']][$row['backend']] = $row;
            $backends[$row['backend']] = true;
        }
        if ($groups === []) {
            return '';
        }

        $metrics = [
            'value_shm_bytes' => 'cache',
            'first_fetch_retained_bytes' => 'first fetch',
            'fetch_retained_bytes' => 'per fetch',
            'request_residual_bytes' => 'request hold',
        ];
        $html = '<h2>Memory Usage</h2><p class="note">CLI measurements outside the timed loops. '
            . '<strong>cache</strong>: shared-memory increase from storing one value, including its key and allocator overhead. '
            . '<strong>first fetch</strong>: PHP heap retained while holding the first fetched value after storing in the same request. '
            . '<strong>per fetch</strong>: additional PHP heap retained while holding one fetched value in a warm request. '
            . '<strong>request hold</strong>: heap remaining after releasing fetched values and collecting cycles, including store-seeded state. '
            . 'Fetch memory excludes mutation. The smallest nonnegative cache and per-fetch values are highlighted independently, including ties. '
            . 'Yac does not expose allocator usage: cache and baseline are n/a; reserved capacity is reported separately. '
            . 'Missing measurements are n/a.</p><table><thead><tr><th>Workload</th>';
        foreach ($backends as $backend => $_) {
            $html .= '<th class="num">' . self::h($label($backend)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($groups as $case => $caseRows) {
            $best = [];
            foreach (['value_shm_bytes', 'fetch_retained_bytes'] as $metric) {
                $values = self::metricValues($caseRows, $metric, true);
                $best[$metric] = $values === [] ? null : reset($values);
            }
            $html .= '<tr><td><code>' . self::h($case) . '</code></td>';
            foreach ($backends as $backend => $_) {
                $row = $caseRows[$backend] ?? [];
                $html .= '<td class="num">';
                foreach ($metrics as $metric => $name) {
                    $value = $row[$metric] ?? null;
                    if ($metric === 'request_residual_bytes' && $value !== null) {
                        $value += $row['store_retained_bytes'] ?? 0;
                    }
                    $winner = $value !== null && isset($best[$metric]) && (float) $value === $best[$metric];
                    $html .= '<span class="small' . ($winner ? ' memory-best' : '') . '">'
                        . $name . ' ' . self::bytes($value) . '</span>';
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table><h3>Shared Memory Capacity and Baseline</h3>'
            . '<p class="note">Reserved capacity and used memory before storing the workload, excluded from the per-value cache delta. '
            . 'The baseline represents startup overhead with the default isolated CLI workers. Values below are medians across workloads.</p>'
            . '<table><thead><tr><th>Backend</th><th class="num">Reserved</th><th class="num">Baseline used</th></tr></thead><tbody>';
        foreach ($backends as $backend => $_) {
            $html .= '<tr><td>' . self::h($label($backend)) . '</td>';
            foreach (['backend_shm_reserved_bytes', 'backend_shm_baseline_bytes'] as $metric) {
                $values = [];
                foreach ($rows as $row) {
                    if ($row['backend'] === $backend && is_numeric($row[$metric] ?? null)) {
                        $values[] = (float) $row[$metric];
                    }
                }
                sort($values, SORT_NUMERIC);
                $count = count($values);
                $median = $count === 0 ? null : ($values[intdiv($count - 1, 2)] + $values[intdiv($count, 2)]) / 2;
                $html .= '<td class="num">' . self::bytes($median) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function bytes(int|float|null $value): string
    {
        if ($value === null) {
            return 'n/a';
        }
        if (abs($value) >= 1048576) {
            return number_format($value / 1048576, 2, '.', ',') . ' MiB';
        }
        if (abs($value) >= 1024) {
            return number_format($value / 1024, 1, '.', ',') . ' KiB';
        }
        return number_format($value, 0, '.', ',') . ' B';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
