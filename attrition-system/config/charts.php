<?php
/**
 * LINE CHARTS (SVG) — a trend over time reads best as a line with a ring on every point, like a textbook chart.
 *   svgLineChart([
 *     'labels'  => ['Prelim', 'Midterm', 'Pre-Finals'],                                  x axis
 *     'series'  => [['name' => 'BSIT-41', 'color' => '#e5534b', 'values' => [69, 72, null],   // null = no data (the line stops there)
 *                    'pops' => [popAttr, popAttr, ''],                                         // tap a point -> the window of data behind it
 *                    'colors' => [null, '#f00', null],                                         // optional ring colour per point
 *                    'dashed' => false, 'values_shown' => false]],
 *     'min' => 50, 'max' => 100, 'ticks' => 5, 'unit' => '%',
 *     'ref' => [['value' => 75, 'label' => 'passing', 'color' => '#e5534b']],
 *     'width' => 720, 'height' => 250 ])
 */
function svgLineChart(array $o): string {
    $labels = $o['labels'] ?? [];
    $n = count($labels);
    if ($n === 0) { return ''; }
    $W = (int) ($o['width'] ?? 720); $H = (int) ($o['height'] ?? 250);
    $min = (float) ($o['min'] ?? 0); $max = (float) ($o['max'] ?? 100); $ticks = (int) ($o['ticks'] ?? 5); $unit = $o['unit'] ?? '';
    if ($max <= $min) { $max = $min + 1; }
    $rot = $n > 7;
    $L = 46; $R = 22; $T = 20; $B = $rot ? 58 : 40;
    $iw = $W - $L - $R; $ih = $H - $T - $B;
    $x = function (int $i) use ($L, $iw, $n) { return $L + 22 + ($n > 1 ? $i * ($iw - 44) / ($n - 1) : ($iw - 44) / 2); };
    $y = function (float $v) use ($T, $ih, $min, $max) { return $T + $ih - (($v - $min) / ($max - $min)) * $ih; };
    $fmt = function (float $v) { return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.'); };
    $step = max(1, (int) ceil($n / 12));
    $svg = '<svg class="lc" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="xMidYMid meet" role="img" xmlns="http://www.w3.org/2000/svg">';
    // grid
    for ($k = 0; $k <= $ticks; $k++) {
        $v = $min + $k * ($max - $min) / $ticks; $yy = round($y($v), 1);
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#dde6ec" stroke-width="1"/>';
        $svg .= '<text x="' . ($L - 9) . '" y="' . ($yy + 4) . '" text-anchor="end" font-size="11.5" fill="#5a7381">' . $fmt($v) . '</text>';
    }
    for ($i = 0; $i < $n; $i++) {
        $xx = round($x($i), 1);
        $svg .= '<line x1="' . $xx . '" x2="' . $xx . '" y1="' . $T . '" y2="' . ($H - $B) . '" stroke="#edf2f5" stroke-width="1"/>';
        if ($i % $step === 0) {
            $ty = $H - $B + 18;
            $svg .= $rot ? '<text x="' . $xx . '" y="' . $ty . '" text-anchor="end" font-size="11" fill="#5a7381" transform="rotate(-38 ' . $xx . ' ' . $ty . ')">' . htmlspecialchars((string) $labels[$i]) . '</text>'
                         : '<text x="' . $xx . '" y="' . $ty . '" text-anchor="middle" font-size="12" fill="#4a6270">' . htmlspecialchars((string) $labels[$i]) . '</text>';
        }
    }
    // axes, with small arrow heads
    $svg .= '<line x1="' . $L . '" x2="' . $L . '" y1="' . ($T - 6) . '" y2="' . ($H - $B) . '" stroke="#4a5a63" stroke-width="2.2"/>';
    $svg .= '<line x1="' . $L . '" x2="' . ($W - $R + 8) . '" y1="' . ($H - $B) . '" y2="' . ($H - $B) . '" stroke="#4a5a63" stroke-width="2.2"/>';
    $svg .= '<polygon points="' . $L . ',' . ($T - 12) . ' ' . ($L - 5) . ',' . ($T - 2) . ' ' . ($L + 5) . ',' . ($T - 2) . '" fill="#4a5a63"/>';
    $svg .= '<polygon points="' . ($W - $R + 14) . ',' . ($H - $B) . ' ' . ($W - $R + 4) . ',' . ($H - $B - 5) . ' ' . ($W - $R + 4) . ',' . ($H - $B + 5) . '" fill="#4a5a63"/>';
    // reference lines (e.g. the passing grade)
    foreach (($o['ref'] ?? []) as $ref) {
        $v = (float) $ref['value']; if ($v < $min || $v > $max) { continue; }
        $yy = round($y($v), 1); $c = $ref['color'] ?? '#e5534b';
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="' . $c . '" stroke-width="1.6" stroke-dasharray="6 5"/>';
        if (!empty($ref['label'])) { $svg .= '<text x="' . ($W - $R - 4) . '" y="' . ($yy - 6) . '" text-anchor="end" font-size="11" font-weight="600" fill="' . $c . '">' . htmlspecialchars($ref['label']) . '</text>'; }
    }
    // the lines, then the rings on top
    foreach ($o['series'] as $s) {
        $color = $s['color'] ?? '#0b8fa3'; $vals = $s['values']; $path = ''; $open = false;
        for ($i = 0; $i < $n; $i++) {
            $v = $vals[$i] ?? null;
            if ($v === null) { $open = false; continue; }
            $path .= ($open ? ' L ' : ' M ') . round($x($i), 1) . ' ' . round($y(max($min, min($max, (float) $v))), 1); $open = true;
        }
        if ($path !== '') { $svg .= '<path d="' . trim($path) . '" fill="none" stroke="' . $color . '" stroke-width="3.2" stroke-linejoin="round" stroke-linecap="round"' . (!empty($s['dashed']) ? ' stroke-dasharray="7 6"' : '') . '/>'; }
    }
    foreach ($o['series'] as $s) {
        $color = $s['color'] ?? '#0b8fa3'; $vals = $s['values'];
        for ($i = 0; $i < $n; $i++) {
            $v = $vals[$i] ?? null; if ($v === null) { continue; }
            $cx = round($x($i), 1); $cy = round($y(max($min, min($max, (float) $v))), 1);
            $ring = $s['colors'][$i] ?? $color; $pop = $s['pops'][$i] ?? '';
            $tip = htmlspecialchars(($s['name'] ?? '') . ' · ' . $labels[$i] . ': ' . $fmt((float) $v) . $unit);
            $svg .= '<g class="lc-pt' . ($pop !== '' ? ' tap' : '') . '"' . ($pop !== '' ? ' data-pop="' . $pop . '"' : '') . '><title>' . $tip . '</title>'
                  . '<circle cx="' . $cx . '" cy="' . $cy . '" r="15" fill="transparent"/>'
                  . '<circle class="vis" cx="' . $cx . '" cy="' . $cy . '" r="5.2" fill="#fff" stroke="' . $ring . '" stroke-width="3.2"/></g>';
            if (!empty($s['values_shown'])) { $svg .= '<text x="' . $cx . '" y="' . ($cy - 11) . '" text-anchor="middle" font-size="11" font-weight="700" fill="' . $ring . '">' . $fmt((float) $v) . '</text>'; }
        }
    }
    $svg .= '</svg>';
    $legend = '';
    if (count($o['series']) > 1 || !empty($o['legend_always'])) {
        $legend = '<div class="lc-legend">';
        foreach ($o['series'] as $s) { $legend .= '<span><i style="background:' . ($s['color'] ?? '#0b8fa3') . '"></i>' . htmlspecialchars($s['name'] ?? '') . '</span>'; }
        foreach (($o['ref'] ?? []) as $ref) { if (!empty($ref['label'])) { $legend .= '<span><i class="dash" style="border-color:' . ($ref['color'] ?? '#e5534b') . '"></i>' . htmlspecialchars($ref['label']) . '</span>'; } }
        $legend .= '</div>';
    }
    return '<div class="lc-wrap">' . $svg . $legend . '</div>';
}
