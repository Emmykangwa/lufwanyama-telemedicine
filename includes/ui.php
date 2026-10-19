<?php
/**
 * includes/ui.php
 *
 * Small presentation helpers that render inline SVG charts and dashboard
 * components. No chart library and no images: everything here is a handful
 * of SVG paths, so a dashboard still loads on a 2G link and prints cleanly.
 *
 * Every function returns a string of HTML and touches no database.
 */

declare(strict_types=1);

/** Scale a list of numbers into y-coordinates inside a box of height $h. */
function _scale(array $v, float $h, float $pad = 2.0): array
{
    $max = max($v) ?: 1;
    $out = [];
    foreach ($v as $n) {
        $out[] = $h - $pad - ((float)$n / $max) * ($h - $pad * 2);
    }
    return $out;
}

/**
 * A thin trend line, as used on a metric card. Decorative: the figure it
 * illustrates is always printed next to it, so it is hidden from readers.
 */
function spark_line(array $values, string $class = 'sp-line'): string
{
    $values = array_values(array_map('floatval', $values));
    if (count($values) < 2) {
        $values = [0, 0];
    }
    $w = 108.0; $h = 34.0;
    $ys = _scale($values, $h);
    $step = $w / (count($ys) - 1);

    $d = '';
    foreach ($ys as $i => $y) {
        $d .= ($i === 0 ? 'M' : 'L') . round($i * $step, 1) . ' ' . round($y, 1) . ' ';
    }
    return '<svg class="' . e($class) . '" viewBox="0 0 ' . $w . ' ' . $h . '" fill="none" '
         . 'preserveAspectRatio="none" aria-hidden="true" focusable="false">'
         . '<path d="' . trim($d) . '" stroke="currentColor" stroke-width="1.6" '
         . 'stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/** A compact "barcode" bar chart for a metric card. Decorative, as above. */
function spark_bars(array $values, string $class = 'sp-bars'): string
{
    $values = array_values(array_map('floatval', $values));
    if (!$values) {
        $values = [0];
    }
    $w = 108.0; $h = 34.0;
    $max = max($values) ?: 1;
    $n   = count($values);
    $gap = 1.6;
    $bw  = max(1.2, ($w - $gap * ($n - 1)) / $n);

    $bars = '';
    foreach ($values as $i => $v) {
        $bh = max(1.5, ($v / $max) * ($h - 3));
        $x  = round($i * ($bw + $gap), 2);
        $y  = round($h - $bh, 2);
        $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . round($bw, 2) . '" '
               . 'height="' . round($bh, 2) . '" rx="' . min(1.2, $bw / 2) . '" fill="currentColor"/>';
    }
    return '<svg class="' . e($class) . '" viewBox="0 0 ' . $w . ' ' . $h . '" '
         . 'preserveAspectRatio="none" aria-hidden="true" focusable="false">' . $bars . '</svg>';
}

/**
 * One metric card: small label, large figure with a unit, a trend graphic and
 * a short sentence giving the figure meaning.
 */
function metric(string $label, string $value, string $unit, string $chart, string $caption): string
{
    return '<article class="metric">'
         . '<div class="metric__top"><span class="metric__label">' . e($label) . '</span></div>'
         . '<p class="metric__v">' . e($value) . ($unit !== '' ? '<i>' . e($unit) . '</i>' : '') . '</p>'
         . '<div class="metric__btm">'
         . '<div class="metric__chart">' . $chart . '</div>'
         . '<p class="metric__cap">' . $caption . '</p>'
         . '</div></article>';
}

/**
 * The large column chart. $rows is an ordered map of label => number.
 * Given a role and a label so the trend is announced rather than silent.
 */
function bar_chart(array $rows, string $aria, int $ticks = 3): string
{
    if (!$rows) {
        return empty_state('No appointments in this window.');
    }
    $vals = array_map('floatval', array_values($rows));
    $keys = array_keys($rows);
    $max  = max($vals);
    // round the top of the axis up to something readable
    $step = max(1, (int)ceil($max / max(1, $ticks)));
    $top  = max(1, $step * $ticks);

    $w = 720.0; $h = 210.0; $padL = 30.0; $padB = 22.0; $padT = 10.0;
    $plotW = $w - $padL; $plotH = $h - $padB - $padT;
    $n = count($vals);
    $slot = $plotW / $n;
    $bw = min(16.0, max(3.0, $slot * 0.55));

    $grid = ''; $axis = '';
    for ($i = 0; $i <= $ticks; $i++) {
        $y = round($padT + $plotH - ($i / $ticks) * $plotH, 1);
        $grid .= '<line x1="' . $padL . '" y1="' . $y . '" x2="' . $w . '" y2="' . $y . '" '
               . 'stroke="currentColor" stroke-width="1" class="ch-grid"/>';
        $axis .= '<text x="' . ($padL - 7) . '" y="' . ($y + 3.5) . '" text-anchor="end" '
               . 'class="ch-ax">' . (int)round(($i / $ticks) * $top) . '</text>';
    }

    $bars = ''; $labels = '';
    foreach ($vals as $i => $v) {
        $bh = $top > 0 ? ($v / $top) * $plotH : 0.0;
        $bh = max($v > 0 ? 2.0 : 0.0, $bh);
        $x  = round($padL + $i * $slot + ($slot - $bw) / 2, 2);
        $y  = round($padT + $plotH - $bh, 2);
        if ($bh > 0) {
            $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . round($bw, 2) . '" '
                   . 'height="' . round($bh, 2) . '" rx="2" class="ch-bar"><title>'
                   . e((string)$keys[$i]) . ': ' . (int)$v . '</title></rect>';
        }
        // only label a few columns, otherwise they collide
        $every = (int)ceil($n / 7);
        if ($i % $every === 0) {
            $labels .= '<text x="' . round($padL + $i * $slot + $slot / 2, 2) . '" y="' . ($h - 6)
                     . '" text-anchor="middle" class="ch-lb">' . e((string)$keys[$i]) . '</text>';
        }
    }

    return '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="'
         . e($aria) . '">' . $grid . $axis . $bars . $labels . '</svg>';
}

/**
 * A ranked horizontal breakdown: label, proportional track, count.
 * The track is SVG rather than a styled element because the
 * Content-Security-Policy refuses inline style attributes.
 */
function rank_bars(array $rows, string $keyField = 'k', string $valField = 'c'): string
{
    if (!$rows) {
        return empty_state('No data in this window.');
    }
    $max = 0;
    foreach ($rows as $r) {
        $max = max($max, (int)$r[$valField]);
    }
    $max = $max ?: 1;

    $out = '<ul class="ranks">';
    foreach ($rows as $r) {
        $v   = (int)$r[$valField];
        $pct = round(($v / $max) * 100, 1);
        $out .= '<li><span class="ranks__k" title="' . e((string)$r[$keyField]) . '">' . e((string)$r[$keyField]) . '</span>'
              . '<svg class="ranks__t" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">'
              . '<rect width="100" height="6" rx="3" class="ranks__bg"/>'
              . ($v > 0 ? '<rect width="' . max(1.5, $pct) . '" height="6" rx="3" class="ranks__fg"/>' : '')
              . '</svg>'
              . '<b class="ranks__v">' . $v . '</b></li>';
    }
    return $out . '</ul>';
}

/**
 * Status split shown as one stacked track plus a legend, so the mix is read
 * at a glance instead of counted down a column.
 */
function status_split(array $rows): string
{
    $total = 0;
    foreach ($rows as $r) {
        $total += (int)$r['c'];
    }
    if ($total === 0) {
        return empty_state('Nothing to break down yet.');
    }

    $x     = 0.0;
    $rects = '';
    $key   = '<ul class="split__key">';
    foreach ($rows as $r) {
        $st  = in_array((string)$r['k'], statuses(), true) ? (string)$r['k'] : 'pending';
        $v   = (int)$r['c'];
        if ($v === 0) {
            continue;
        }
        $pct = ($v / $total) * 100;
        $rects .= '<rect x="' . round($x, 2) . '" width="' . round($pct, 2) . '" height="8" class="f-' . $st . '">'
                . '<title>' . e(t($st)) . ': ' . $v . '</title></rect>';
        $x += $pct;
        $key .= '<li><span class="dot s-' . $st . '"></span>' . e(t($st))
              . ' <b>' . $v . '</b> <small>' . round($pct) . '%</small></li>';
    }
    return '<svg class="split" viewBox="0 0 100 8" preserveAspectRatio="none" role="img" '
         . 'aria-label="Appointment status split">' . $rects . '</svg>' . $key . '</ul>';
}

/**
 * A month grid marking the days that carry an appointment, so a clinician
 * sees the shape of the month rather than reading dates out of a table.
 * $marked maps 'Y-m-d' => status.
 */
function mini_cal(string $monthStart, array $marked, string $heading = ''): string
{
    $first = strtotime($monthStart . ' 00:00:00') ?: time();
    $first = strtotime(date('Y-m-01', $first));
    $days  = (int)date('t', $first);
    $lead  = (int)date('N', $first) % 7;   // 0 = Sunday column
    $today = date('Y-m-d');

    $out = '<div class="cal">';
    $out .= '<div class="cal__head"><b>' . e($heading !== '' ? $heading : date('F Y', $first)) . '</b></div>';
    $out .= '<div class="cal__grid">';
    foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d) {
        $out .= '<span class="cal__dow">' . $d . '</span>';
    }
    for ($i = 0; $i < $lead; $i++) {
        $out .= '<span class="cal__pad"></span>';
    }
    for ($d = 1; $d <= $days; $d++) {
        $iso = date('Y-m-d', strtotime("+" . ($d - 1) . " day", $first));
        $cls = 'cal__d';
        if (isset($marked[$iso])) {
            $cls .= ' on s-' . $marked[$iso];
        }
        if ($iso === $today) {
            $cls .= ' today';
        }
        $title = isset($marked[$iso]) ? ' title="' . e(t((string)$marked[$iso])) . '"' : '';
        $out .= '<span class="' . $cls . '"' . $title . '>' . $d . '</span>';
    }
    $out .= '</div><div class="cal__legend">';
    foreach (['pending', 'confirmed', 'rescheduled', 'completed', 'declined'] as $s) {
        $out .= '<span><i class="dot s-' . $s . '"></i>' . e(t($s)) . '</span>';
    }
    return $out . '</div></div>';
}

/** A dated activity list, newest first. $items: [when, title, meta, status|null]. */
function timeline(array $items): string
{
    if (!$items) {
        return empty_state('No activity yet.');
    }
    $out = '<ol class="tl">';
    foreach ($items as [$when, $title, $meta, $status]) {
        $out .= '<li><span class="tl__dot' . ($status ? ' s-' . e((string)$status) : '') . '"></span>'
              . '<div class="tl__b"><p class="tl__t">' . e((string)$title)
              . ($status ? ' ' . badge((string)$status) : '') . '</p>'
              . '<p class="tl__m">' . e((string)$meta) . '</p></div>'
              . '<time class="tl__w">' . e((string)$when) . '</time></li>';
    }
    return $out . '</ol>';
}

/** A panel wrapper: title, optional right-hand slot, body. */
function panel_open(string $title, string $right = ''): string
{
    return '<section class="pan"><header class="pan__h"><h2>' . e($title) . '</h2>'
         . ($right !== '' ? '<div class="pan__r">' . $right . '</div>' : '') . '</header>'
         . '<div class="pan__b">';
}

function panel_close(): string
{
    return '</div></section>';
}

/**
 * Page links for a long list. $keep carries the current filters so paging
 * does not silently reset them. Returns '' when everything fits on one page.
 *
 * Long lists are paged because a dashboard must stay under the page-weight
 * budget the project sets for 2G and 3G links; an unbounded list would grow
 * past it as a centre accumulates records.
 */
function pager(int $page, int $pages, array $keep = [], string $param = 'p'): string
{
    if ($pages < 2) {
        return '';
    }
    $link = static function (int $n) use ($keep, $param): string {
        $q = $keep;
        $q[$param] = $n;
        return e('?' . http_build_query($q));
    };

    $out = '<nav class="pager noprint" aria-label="Pages">';
    $out .= $page > 1
        ? '<a class="pager__b" href="' . $link($page - 1) . '" rel="prev">Previous</a>'
        : '<span class="pager__b is-off">Previous</span>';

    $from = max(1, $page - 2);
    $to   = min($pages, $from + 4);
    $from = max(1, $to - 4);
    for ($i = $from; $i <= $to; $i++) {
        $out .= $i === $page
            ? '<span class="pager__n on" aria-current="page">' . $i . '</span>'
            : '<a class="pager__n" href="' . $link($i) . '">' . $i . '</a>';
    }

    $out .= $page < $pages
        ? '<a class="pager__b" href="' . $link($page + 1) . '" rel="next">Next</a>'
        : '<span class="pager__b is-off">Next</span>';
    return $out . '</nav>';
}

/**
 * A row of tabs for switching a list between views. $items maps a value to
 * [label, count|null]; '' is the "all" view. $keep carries other filters.
 */
function tabs(array $items, string $current, string $param = 'view', array $keep = []): string
{
    $out = '<nav class="tabs" aria-label="Views">';
    foreach ($items as $value => [$label, $count]) {
        $q = $keep;
        if ($value !== '') {
            $q[$param] = $value;
        } else {
            unset($q[$param]);
        }
        $href = '?' . http_build_query($q);
        $on   = (string)$value === $current;
        $out .= '<a href="' . e($href === '?' ? '?' : $href) . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>'
              . e($label) . ($count !== null ? '<span class="tabs__n">' . (int)$count . '</span>' : '') . '</a>';
    }
    return $out . '</nav>';
}

/** A quiet message for an empty list, optionally with a next step. */
function empty_state(string $text, string $action = '', string $ico = 'inbox'): string
{
    return '<div class="empty">' . icon($ico) . '<p>' . e($text) . '</p>'
         . ($action !== '' ? '<div>' . $action . '</div>' : '') . '</div>';
}

/** A definition list of label => value pairs; values are already-escaped HTML. */
function kv(array $pairs): string
{
    $out = '<dl class="kv">';
    foreach ($pairs as $k => $v) {
        if ($v === null || $v === '') {
            $v = '<span class="muted">&mdash;</span>';
        }
        $out .= '<div><dt>' . e((string)$k) . '</dt><dd>' . $v . '</dd></div>';
    }
    return $out . '</dl>';
}

/** Small avatar with initials. */
function avatar(string $name, string $class = 'av'): string
{
    return '<span class="' . e($class) . '" aria-hidden="true">' . e(initials($name)) . '</span>';
}

/** Active / Inactive pill. */
function state_pill(bool $active): string
{
    return $active
        ? '<span class="pill pill--ok">Active</span>'
        : '<span class="pill pill--off">Inactive</span>';
}

/** Role tag. */
function role_tag(string $role): string
{
    return '<span class="tag tag--' . e($role) . '">' . e(role_label($role)) . '</span>';
}
