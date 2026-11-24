<?php
/**
 * charts.php — dependency-free SVG charts.
 * -------------------------------------------------------
 * The dashboards need real charts without pulling in a
 * JavaScript charting library: the office works on weak
 * connections and every asset has to load offline. These
 * helpers return accessible inline SVG that inherits the
 * application's typography and colour tokens.
 * -------------------------------------------------------
 */

/** Chart palette lifted from the office Excel workbook. */
function chart_palette(): array
{
    return [
        'blue'   => '#4F81BD',
        'red'    => '#C0504D',
        'green'  => '#9BBB59',
        'purple' => '#8064A2',
        'teal'   => '#4BACC6',
        'gold'   => '#C98012',
        'grey'   => '#B9C6C0',
    ];
}

/** Compact money for axis labels: 13.7M, 850K, 420. */
function chart_money(float $value): string
{
    $value = (float) $value;
    $abs   = abs($value);

    if ($abs >= 1000000000) {
        return rtrim(rtrim(number_format($value / 1000000000, 1, '.', ''), '0'), '.') . 'B';
    }

    if ($abs >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }

    if ($abs >= 1000) {
        return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'K';
    }

    return number_format($value, 0);
}

/**
 * Vertical column chart.
 *
 * Each series item: ['label' => string, 'value' => float, 'color' => '#RRGGBB'].
 *
 * @param array $series
 * @param array $options height, value_prefix, show_values, gradient
 */
function svg_column_chart(array $series, array $options = []): string
{
    $width        = (int) ($options['width'] ?? 640);
    $height       = (int) ($options['height'] ?? 260);
    $padLeft      = (int) ($options['pad_left'] ?? 54);
    $padRight     = (int) ($options['pad_right'] ?? 14);
    $padTop       = (int) ($options['pad_top'] ?? 18);
    $padBottom    = (int) ($options['pad_bottom'] ?? 42);
    $showValues   = (bool) ($options['show_values'] ?? false);
    $valuePrefix  = (string) ($options['value_prefix'] ?? '');
    $emptyText    = (string) ($options['empty_text'] ?? t('No data yet'));
    $title        = (string) ($options['title'] ?? '');

    $plotWidth  = max(1, $width - $padLeft - $padRight);
    $plotHeight = max(1, $height - $padTop - $padBottom);

    $max = 0.0;
    foreach ($series as $item) {
        $max = max($max, (float) ($item['value'] ?? 0));
    }

    if ($max <= 0) {
        return '<div class="chart-empty"><i class="bi bi-bar-chart"></i><span>'
            . e($emptyText) . '</span></div>';
    }

    // Round the axis up to a friendly number.
    $magnitude = 10 ** max(0, strlen((string) (int) $max) - 2);
    $step      = max(1.0, ceil($max / 4 / $magnitude) * $magnitude);
    $axisMax   = $step * 4;

    $count   = max(1, count($series));
    $slot    = $plotWidth / $count;
    $barGap  = min(14.0, $slot * 0.28);
    $barW    = max(4.0, $slot - $barGap);
    $palette = chart_palette();
    $default = $options['color'] ?? $palette['blue'];

    $svg  = '<svg class="chart-svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img"'
          . ' aria-label="' . e($title !== '' ? $title : t('Chart')) . '" preserveAspectRatio="xMidYMid meet">';

    if (!empty($options['gradient'])) {
        $svg .= '<defs><linearGradient id="' . e((string) $options['gradient']) . '" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0%" stop-color="' . e($default) . '" stop-opacity="1"/>'
              . '<stop offset="100%" stop-color="' . e($default) . '" stop-opacity=".55"/>'
              . '</linearGradient></defs>';
    }

    // Grid + Y labels.
    for ($i = 0; $i <= 4; $i++) {
        $value = $axisMax - ($step * $i);
        $y     = $padTop + ($plotHeight * $i / 4);

        $svg .= '<line x1="' . $padLeft . '" y1="' . round($y, 1) . '" x2="' . ($width - $padRight)
              . '" y2="' . round($y, 1) . '" stroke="#E6EEEA" stroke-width="1"/>'
              . '<text x="' . ($padLeft - 9) . '" y="' . round($y + 3.5, 1)
              . '" text-anchor="end" font-size="10" fill="#7A8C85">'
              . e(chart_money($value)) . '</text>';
    }

    $grid = (int) ($options['label_every'] ?? 1);

    foreach (array_values($series) as $index => $item) {
        $value = max(0.0, (float) ($item['value'] ?? 0));
        $barH  = $axisMax > 0 ? ($value / $axisMax) * $plotHeight : 0;
        $x     = $padLeft + ($slot * $index) + ($barGap / 2);
        $y     = $padTop + $plotHeight - $barH;
        $color = (string) ($item['color'] ?? $default);

        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1)
              . '" width="' . round($barW, 1) . '" height="' . round($barH, 1)
              . '" rx="3" fill="' . ($options['gradient'] ?? null ? 'url(#' . e((string) $options['gradient']) . ')' : e($color)) . '">'
              . '<title>' . e((string) ($item['label'] ?? '')) . ': ' . e($valuePrefix . num($value)) . '</title>'
              . '</rect>';

        if ($showValues) {
            $svg .= '<text x="' . round($x + ($barW / 2), 1) . '" y="' . round(max(12, $y - 7), 1)
                  . '" text-anchor="middle" font-size="10.5" font-weight="700" fill="#2D3E37">'
                  . e(chart_money($value)) . '</text>';
        }

        if ($index % $grid === 0) {
            $label = (string) ($item['label'] ?? '');

            $svg .= '<text x="' . round($x + ($barW / 2), 1) . '" y="' . ($height - $padBottom + 18)
                  . '" text-anchor="middle" font-size="9.5" fill="#5C7068">' . e($label) . '</text>';
        }
    }

    $svg .= '</svg>';

    return $svg;
}

/**
 * Donut chart with a legend.
 *
 * Each segment: ['label' => string, 'value' => int|float, 'color' => '#RRGGBB'].
 */
function svg_donut_chart(array $segments, array $options = []): string
{
    $size   = (int) ($options['size'] ?? 190);
    $stroke = (int) ($options['stroke'] ?? 26);
    $center = $size / 2;
    $radius = ($size - $stroke) / 2;
    $circ   = 2 * M_PI * $radius;

    $total = 0.0;
    foreach ($segments as $segment) {
        $total += max(0.0, (float) ($segment['value'] ?? 0));
    }

    $title = (string) ($options['title'] ?? t('Distribution'));

    $svg = '<svg class="chart-svg chart-donut" viewBox="0 0 ' . $size . ' ' . $size
         . '" role="img" aria-label="' . e($title) . '">'
         . '<circle cx="' . $center . '" cy="' . $center . '" r="' . $radius
         . '" fill="none" stroke="#EDF2EF" stroke-width="' . $stroke . '"/>';

    if ($total > 0) {
        $offset = $circ * 0.25; // start at 12 o'clock

        foreach ($segments as $segment) {
            $value = max(0.0, (float) ($segment['value'] ?? 0));

            if ($value <= 0) {
                continue;
            }

            $length = ($value / $total) * $circ;
            $color  = (string) ($segment['color'] ?? '#B9C6C0');

            $svg .= '<circle cx="' . $center . '" cy="' . $center . '" r="' . $radius
                  . '" fill="none" stroke="' . e($color) . '" stroke-width="' . $stroke
                  . '" stroke-dasharray="' . round($length, 2) . ' ' . round($circ - $length, 2)
                  . '" stroke-dashoffset="' . round($offset, 2) . '" stroke-linecap="butt">'
                  . '<title>' . e((string) ($segment['label'] ?? '')) . ': ' . e(num($value))
                  . ' (' . e(percent($value, $total)) . '%)</title></circle>';

            $offset -= $length;
        }
    }

    $centerValue = (string) ($options['center_value'] ?? num($total));
    $centerLabel = (string) ($options['center_label'] ?? 'total');

    $svg .= '<text x="' . $center . '" y="' . ($center - 3) . '" text-anchor="middle"'
          . ' font-size="24" font-weight="800" fill="#111A16">' . e($centerValue) . '</text>'
          . '<text x="' . $center . '" y="' . ($center + 17) . '" text-anchor="middle"'
          . ' font-size="9.5" fill="#5C7068" letter-spacing=".08em">' . e(strtoupper($centerLabel)) . '</text>'
          . '</svg>';

    $legend = '<div class="chart-legend">';

    foreach ($segments as $segment) {
        $value = max(0.0, (float) ($segment['value'] ?? 0));

        $legend .= '<span class="chart-legend-item">'
                 . '<span class="chart-dot" style="background:' . e((string) ($segment['color'] ?? '#B9C6C0')) . '"></span>'
                 . '<span>' . e((string) ($segment['label'] ?? '')) . '</span>'
                 . '<strong>' . e(num($value)) . '</strong>'
                 . '</span>';
    }

    $legend .= '</div>';

    return '<div class="chart-donut-wrap">' . $svg . $legend . '</div>';
}

/** Round a maximum value up to a friendly axis maximum and step. */
function chart_nice_axis(float $max, int $steps = 4): array
{
    $steps = max(2, $steps);
    if ($max <= 0) {
        return [1.0, 1.0 / $steps];
    }
    $rough = $max / $steps;
    $magnitude = 10 ** floor(log10($rough));
    $residual = $rough / $magnitude;
    $nice = $residual <= 1 ? 1 : ($residual <= 2 ? 2 : ($residual <= 5 ? 5 : 10));
    $step = $nice * $magnitude;
    return [$step * $steps, $step];
}

/** Shared legend markup for multi-series charts. */
function chart_legend_html(array $series): string
{
    $html = '<div class="chart-legend chart-legend-row">';
    foreach ($series as $item) {
        $html .= '<span class="chart-legend-item"><span class="chart-dot" style="background:'
            . e((string) ($item['color'] ?? '#4F81BD')) . '"></span><span>'
            . e((string) ($item['label'] ?? '')) . '</span></span>';
    }
    return $html . '</div>';
}

/**
 * Multi-series line / area chart.
 *
 * Series item: ['label' => string, 'color' => '#RRGGBB', 'points' =>
 * [['label' => string, 'value' => float], ...], 'fill' => bool, 'dashed' => bool].
 */
function svg_line_chart(array $series, array $options = []): string
{
    static $chartSequence = 0;
    $chartSequence++;

    $width = (int) ($options['width'] ?? 720);
    $height = (int) ($options['height'] ?? 280);
    $padLeft = (int) ($options['pad_left'] ?? 46);
    $padRight = (int) ($options['pad_right'] ?? 16);
    $padTop = (int) ($options['pad_top'] ?? 18);
    $padBottom = (int) ($options['pad_bottom'] ?? 46);
    $emptyText = (string) ($options['empty_text'] ?? t('No data yet'));
    $title = (string) ($options['title'] ?? t('Trend'));
    $valuePrefix = (string) ($options['value_prefix'] ?? '');
    $valueSuffix = (string) ($options['value_suffix'] ?? '');
    $showPoints = (bool) ($options['show_points'] ?? true);

    $labels = [];
    $pointCount = 0;
    $max = 0.0;
    $min = 0.0;
    foreach ($series as $line) {
        $points = $line['points'] ?? [];
        $pointCount = max($pointCount, count($points));
        if ($labels === []) {
            foreach ($points as $point) {
                $labels[] = (string) ($point['label'] ?? '');
            }
        }
        foreach ($points as $point) {
            $value = (float) ($point['value'] ?? 0);
            $max = max($max, $value);
            $min = min($min, $value);
        }
    }

    if ($pointCount === 0 || $max <= 0) {
        return '<div class="chart-empty"><i class="bi bi-graph-up"></i><span>' . e($emptyText) . '</span></div>';
    }

    $yMin = isset($options['y_min']) ? (float) $options['y_min'] : 0.0;
    $yMaxOption = isset($options['y_max']) ? (float) $options['y_max'] : null;
    [$axisMax, $step] = chart_nice_axis($yMaxOption ?? $max, 4);
    if ($yMaxOption !== null) {
        $axisMax = $yMaxOption;
        $step = $axisMax / 4;
    }
    $axisMin = max(0.0, min($yMin, $min));
    $range = max(0.0001, $axisMax - $axisMin);

    $plotWidth = max(1, $width - $padLeft - $padRight);
    $plotHeight = max(1, $height - $padTop - $padBottom);
    $slot = $pointCount > 1 ? $plotWidth / ($pointCount - 1) : $plotWidth;

    $xFor = static fn(int $index): float => $pointCount > 1
        ? $padLeft + ($slot * $index)
        : $padLeft + ($plotWidth / 2);
    $yFor = static fn(float $value): float => $padTop + $plotHeight - ((max($axisMin, min($axisMax, $value)) - $axisMin) / $range) * $plotHeight;

    $svg = '<svg class="chart-svg chart-line" viewBox="0 0 ' . $width . ' ' . $height
        . '" role="img" aria-label="' . e($title) . '" preserveAspectRatio="xMidYMid meet">';

    // Horizontal grid and Y labels.
    for ($i = 0; $i <= 4; $i++) {
        $value = $axisMax - ($step * $i);
        $y = $padTop + ($plotHeight * $i / 4);
        $svg .= '<line x1="' . $padLeft . '" y1="' . round($y, 1) . '" x2="' . ($width - $padRight)
            . '" y2="' . round($y, 1) . '" stroke="#E6EEEA" stroke-width="1"/>'
            . '<text x="' . ($padLeft - 8) . '" y="' . round($y + 3.5, 1)
            . '" text-anchor="end" font-size="10" fill="#7A8C85">' . e(chart_money($value)) . '</text>';
    }

    $labelEvery = max(1, (int) ($options['label_every'] ?? max(1, (int) ceil($pointCount / 8))));
    foreach ($labels as $index => $label) {
        if ($index % $labelEvery !== 0 && $index !== $pointCount - 1) {
            continue;
        }
        $svg .= '<text x="' . round($xFor($index), 1) . '" y="' . ($height - $padBottom + 18)
            . '" text-anchor="middle" font-size="9.5" fill="#5C7068">' . e($label) . '</text>';
    }

    foreach ($series as $lineIndex => $line) {
        $color = (string) ($line['color'] ?? '#4F81BD');
        $points = array_values($line['points'] ?? []);
        if ($points === []) {
            continue;
        }
        $path = '';
        foreach ($points as $index => $point) {
            $path .= ($index === 0 ? 'M' : 'L') . round($xFor($index), 1) . ' ' . round($yFor((float) ($point['value'] ?? 0)), 1) . ' ';
        }
        $path = trim($path);

        if (!empty($line['fill']) || !empty($options['area'])) {
            $gradientId = 'chart-area-' . $chartSequence . '-' . $lineIndex;
            $svg .= '<defs><linearGradient id="' . $gradientId . '" x1="0" y1="0" x2="0" y2="1">'
                . '<stop offset="0%" stop-color="' . e($color) . '" stop-opacity=".30"/>'
                . '<stop offset="100%" stop-color="' . e($color) . '" stop-opacity=".02"/>'
                . '</linearGradient></defs>';
            $areaPath = $path . ' L' . round($xFor(count($points) - 1), 1) . ' ' . ($padTop + $plotHeight)
                . ' L' . round($xFor(0), 1) . ' ' . ($padTop + $plotHeight) . ' Z';
            $svg .= '<path d="' . $areaPath . '" fill="url(#' . $gradientId . ')" stroke="none"/>';
        }

        $dash = !empty($line['dashed']) ? ' stroke-dasharray="6 5"' : '';
        $svg .= '<path d="' . $path . '" fill="none" stroke="' . e($color)
            . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"' . $dash . '/>';

        if ($showPoints) {
            foreach ($points as $index => $point) {
                $value = (float) ($point['value'] ?? 0);
                $svg .= '<circle cx="' . round($xFor($index), 1) . '" cy="' . round($yFor($value), 1)
                    . '" r="3.2" fill="#fff" stroke="' . e($color) . '" stroke-width="2">'
                    . '<title>' . e((string) ($line['label'] ?? '')) . ' · ' . e((string) ($point['label'] ?? ''))
                    . ': ' . e($valuePrefix . num($value) . $valueSuffix) . '</title></circle>';
            }
        }
    }

    $svg .= '</svg>';
    $legend = count($series) > 1 ? chart_legend_html($series) : '';
    return '<div class="chart-line-wrap">' . $svg . $legend . '</div>';
}

/**
 * Grouped or stacked column chart.
 *
 * @param array $categories ['Jan', 'Feb', ...]
 * @param array $series [['label'=>'Boys','color'=>'#','values'=>[12,18]], ...]
 */
function svg_multi_bar_chart(array $categories, array $series, array $options = []): string
{
    $stacked = (bool) ($options['stacked'] ?? false);
    $width = (int) ($options['width'] ?? 720);
    $height = (int) ($options['height'] ?? 290);
    $padLeft = (int) ($options['pad_left'] ?? 52);
    $padRight = (int) ($options['pad_right'] ?? 16);
    $padTop = (int) ($options['pad_top'] ?? 18);
    $padBottom = (int) ($options['pad_bottom'] ?? 52);
    $emptyText = (string) ($options['empty_text'] ?? t('No data yet'));
    $title = (string) ($options['title'] ?? t('Comparison'));
    $showValues = (bool) ($options['show_values'] ?? false);
    $valuePrefix = (string) ($options['value_prefix'] ?? '');

    $categoryCount = count($categories);
    $seriesCount = max(1, count($series));
    $max = 0.0;
    for ($c = 0; $c < $categoryCount; $c++) {
        if ($stacked) {
            $sum = 0.0;
            foreach ($series as $line) $sum += max(0.0, (float) ($line['values'][$c] ?? 0));
            $max = max($max, $sum);
        } else {
            foreach ($series as $line) $max = max($max, (float) ($line['values'][$c] ?? 0));
        }
    }

    if ($categoryCount === 0 || $max <= 0) {
        return '<div class="chart-empty"><i class="bi bi-bar-chart"></i><span>' . e($emptyText) . '</span></div>';
    }

    [$axisMax, $step] = chart_nice_axis($max, 4);
    $plotWidth = max(1, $width - $padLeft - $padRight);
    $plotHeight = max(1, $height - $padTop - $padBottom);
    $slot = $plotWidth / $categoryCount;
    $groupPad = min(16.0, $slot * 0.22);
    $groupWidth = max(6.0, $slot - $groupPad);
    $barWidth = $stacked ? $groupWidth : max(3.0, ($groupWidth / $seriesCount) - 2);

    $svg = '<svg class="chart-svg chart-bars" viewBox="0 0 ' . $width . ' ' . $height
        . '" role="img" aria-label="' . e($title) . '" preserveAspectRatio="xMidYMid meet">';

    for ($i = 0; $i <= 4; $i++) {
        $value = $axisMax - ($step * $i);
        $y = $padTop + ($plotHeight * $i / 4);
        $svg .= '<line x1="' . $padLeft . '" y1="' . round($y, 1) . '" x2="' . ($width - $padRight)
            . '" y2="' . round($y, 1) . '" stroke="#E6EEEA" stroke-width="1"/>'
            . '<text x="' . ($padLeft - 8) . '" y="' . round($y + 3.5, 1)
            . '" text-anchor="end" font-size="10" fill="#7A8C85">' . e(chart_money($value)) . '</text>';
    }

    foreach ($categories as $c => $category) {
        $groupX = $padLeft + ($slot * $c) + ($groupPad / 2);
        if ($stacked) {
            $stackTop = $padTop + $plotHeight;
            foreach ($series as $line) {
                $value = max(0.0, (float) ($line['values'][$c] ?? 0));
                if ($value <= 0) continue;
                $barH = ($value / $axisMax) * $plotHeight;
                $stackTop -= $barH;
                $svg .= '<rect x="' . round($groupX, 1) . '" y="' . round($stackTop, 1)
                    . '" width="' . round($barWidth, 1) . '" height="' . round($barH, 1)
                    . '" fill="' . e((string) ($line['color'] ?? '#4F81BD')) . '">'
                    . '<title>' . e((string) ($line['label'] ?? '')) . ' · ' . e((string) $category)
                    . ': ' . e($valuePrefix . num($value)) . '</title></rect>';
            }
            if ($showValues) {
                $svg .= '<text x="' . round($groupX + ($barWidth / 2), 1) . '" y="' . round(max(12, $stackTop - 6), 1)
                    . '" text-anchor="middle" font-size="10" font-weight="700" fill="#2D3E37">'
                    . e(chart_money($max > 0 ? (float) array_sum(array_map(static fn(array $line): float => (float) ($line['values'][$c] ?? 0), $series)) : 0)) . '</text>';
            }
        } else {
            foreach ($series as $s => $line) {
                $value = max(0.0, (float) ($line['values'][$c] ?? 0));
                $barH = ($value / $axisMax) * $plotHeight;
                $x = $groupX + ($s * ($barWidth + 2));
                $y = $padTop + $plotHeight - $barH;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1)
                    . '" width="' . round($barWidth, 1) . '" height="' . round($barH, 1)
                    . '" rx="2" fill="' . e((string) ($line['color'] ?? '#4F81BD')) . '">'
                    . '<title>' . e((string) ($line['label'] ?? '')) . ' · ' . e((string) $category)
                    . ': ' . e($valuePrefix . num($value)) . '</title></rect>';
                if ($showValues && $value > 0) {
                    $svg .= '<text x="' . round($x + ($barWidth / 2), 1) . '" y="' . round(max(12, $y - 5), 1)
                        . '" text-anchor="middle" font-size="9" font-weight="700" fill="#2D3E37">'
                        . e(chart_money($value)) . '</text>';
                }
            }
        }

        $svg .= '<text x="' . round($groupX + ($groupWidth / 2), 1) . '" y="' . ($height - $padBottom + 18)
            . '" text-anchor="middle" font-size="9.5" fill="#5C7068">' . e((string) $category) . '</text>';
    }

    $svg .= '</svg>';
    $legend = count($series) > 1 ? chart_legend_html($series) : '';
    return '<div class="chart-bars-wrap">' . $svg . $legend . '</div>';
}

/** Convenience wrappers with explicit intent. */
function svg_grouped_bar_chart(array $categories, array $series, array $options = []): string
{
    return svg_multi_bar_chart($categories, $series, $options + ['stacked' => false]);
}

function svg_stacked_bar_chart(array $categories, array $series, array $options = []): string
{
    return svg_multi_bar_chart($categories, $series, $options + ['stacked' => true]);
}

/**
 * Heatmap grid. Matrix is row-index => column-index => numeric value.
 *
 * @param array $rowLabels ['Mon', 'Tue']
 * @param array $columnLabels ['08:00', '09:00']
 * @param array $matrix [[1,2,3],[4,5,6]]
 */
function svg_heatmap(array $rowLabels, array $columnLabels, array $matrix, array $options = []): string
{
    $cell = (int) ($options['cell'] ?? 34);
    $gap = (int) ($options['gap'] ?? 4);
    $left = (int) ($options['label_width'] ?? 74);
    $top = (int) ($options['top'] ?? 22);
    $title = (string) ($options['title'] ?? t('Activity heatmap'));
    $emptyText = (string) ($options['empty_text'] ?? t('No activity yet'));
    $suffix = (string) ($options['value_suffix'] ?? '');

    $max = 0.0;
    foreach ($matrix as $row) foreach ((array) $row as $value) $max = max($max, (float) $value);
    if ($max <= 0) {
        return '<div class="chart-empty"><i class="bi bi-grid-3x3-gap"></i><span>' . e($emptyText) . '</span></div>';
    }

    $cols = count($columnLabels);
    $rows = count($rowLabels);
    $width = $left + ($cols * ($cell + $gap)) + 8;
    $height = $top + ($rows * ($cell + $gap)) + 8;

    $svg = '<svg class="chart-svg chart-heatmap" viewBox="0 0 ' . $width . ' ' . $height
        . '" role="img" aria-label="' . e($title) . '" preserveAspectRatio="xMidYMid meet">';

    foreach ($columnLabels as $c => $label) {
        if ($cols > 12 && $c % 2 !== 0) continue;
        $svg .= '<text x="' . ($left + ($c * ($cell + $gap)) + ($cell / 2)) . '" y="14" text-anchor="middle" font-size="9" fill="#7A8C85">'
            . e((string) $label) . '</text>';
    }

    foreach ($rowLabels as $r => $rowLabel) {
        $y = $top + ($r * ($cell + $gap));
        $svg .= '<text x="' . ($left - 8) . '" y="' . ($y + ($cell / 2) + 3.5) . '" text-anchor="end" font-size="9.5" fill="#5C7068">'
            . e((string) $rowLabel) . '</text>';
        foreach ($columnLabels as $c => $_) {
            $value = (float) ($matrix[$r][$c] ?? 0);
            $intensity = $max > 0 ? $value / $max : 0;
            $opacity = $value > 0 ? 0.12 + ($intensity * 0.82) : 0;
            $fill = $value > 0 ? 'rgba(22,158,107,' . round($opacity, 2) . ')' : '#F3F7F4';
            $x = $left + ($c * ($cell + $gap));
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cell . '" height="' . $cell . '" rx="5" fill="' . $fill . '">'
                . '<title>' . e((string) $rowLabel) . ' · ' . e((string) ($columnLabels[$c] ?? '')) . ': '
                . e(num($value) . $suffix) . '</title></rect>';
            if ($value > 0 && $cell >= 28) {
                $textColor = $intensity > 0.55 ? '#FFFFFF' : '#1F3B31';
                $svg .= '<text x="' . ($x + ($cell / 2)) . '" y="' . ($y + ($cell / 2) + 3.5)
                    . '" text-anchor="middle" font-size="9.5" font-weight="700" fill="' . $textColor . '">'
                    . e(num($value)) . '</text>';
            }
        }
    }

    $svg .= '</svg>';
    return $svg;
}

/** Compact inline sparkline for KPI cards. */
function svg_sparkline(array $values, array $options = []): string
{
    $width = (int) ($options['width'] ?? 120);
    $height = (int) ($options['height'] ?? 34);
    $color = (string) ($options['color'] ?? '#169E6B');
    $values = array_values(array_map(static fn($value): float => (float) $value, $values));
    if (count($values) < 2) {
        return '<span class="sparkline-empty">—</span>';
    }
    $max = max($values);
    $min = min($values);
    $range = max(0.0001, $max - $min);
    $slot = $width / (count($values) - 1);
    $path = '';
    $area = '';
    foreach ($values as $index => $value) {
        $x = $index * $slot;
        $y = $height - 3 - (($value - $min) / $range) * ($height - 8);
        $path .= ($index === 0 ? 'M' : 'L') . round($x, 1) . ' ' . round($y, 1) . ' ';
        $area .= ($index === 0 ? 'M' : 'L') . round($x, 1) . ' ' . round($y, 1) . ' ';
    }
    $area .= 'L' . $width . ' ' . $height . ' L0 ' . $height . ' Z';
    return '<svg class="sparkline" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Trend">'
        . '<path d="' . trim($area) . '" fill="' . e($color) . '" opacity=".12"/>'
        . '<path d="' . trim($path) . '" fill="none" stroke="' . e($color) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'
        . '</svg>';
}

/** Horizontal ranked bars, useful for top-N lists without axis clutter. */
function svg_ranked_bars(array $items, array $options = []): string
{
    $max = 0.0;
    foreach ($items as $item) $max = max($max, (float) ($item['value'] ?? 0));
    if ($max <= 0) {
        return '<div class="chart-empty"><i class="bi bi-list-ol"></i><span>'
            . e((string) ($options['empty_text'] ?? t('No data yet'))) . '</span></div>';
    }
    $color = (string) ($options['color'] ?? '#4F81BD');
    $prefix = (string) ($options['value_prefix'] ?? '');
    $html = '<div class="ranked-bars">';
    foreach ($items as $item) {
        $value = (float) ($item['value'] ?? 0);
        $width = max(1.5, ($value / $max) * 100);
        $html .= '<div class="ranked-row"><div class="ranked-label" title="' . e((string) ($item['label'] ?? '')) . '">'
            . e((string) ($item['label'] ?? '')) . '</div><div class="ranked-track"><span style="width:'
            . round($width, 2) . '%;background:' . e((string) ($item['color'] ?? $color)) . '"></span></div>'
            . '<div class="ranked-value">' . e($prefix . num($value)) . '</div></div>';
    }
    return $html . '</div>';
}
