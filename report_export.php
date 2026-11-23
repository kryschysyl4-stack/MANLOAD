<?php
/**
 * One-click report export: CSV (Excel) and PDF, straight from the page that shows the report.
 *
 *   report_export($fmt, 'sales_2026-11-01', 'Sales Summary', 'Nov 1 – Nov 11, 2026', [
 *       ['title' => 'By day', 'header' => [...], 'rows' => [[...], ...], 'total' => [...], 'align' => ['', 'R', ...]],
 *       ...
 *   ]);
 *   echo export_buttons();   // CSV / PDF buttons that keep the page's current filters
 *
 * The PDF is written here without any library (A4 landscape, Helvetica, tables that break across pages).
 * Every export is written to the audit log.
 */
require_once __DIR__ . '/archive.php';   // csv_safe(), csv_string()

/** 'csv' | 'pdf' | '' from ?export= */
function export_format(): string {
    $f = str_param($_GET['export'] ?? '');
    return in_array($f, ['csv', 'pdf'], true) ? $f : '';
}

/** CSV + PDF buttons for the current page (current filters kept). */
function export_buttons(string $extra_class = ''): string {
    $q = $_GET;
    unset($q['export']);
    $base = '?' . ($q ? http_build_query($q) . '&' : '');
    return '<span class="btn-group btn-group-sm no-print ' . e($extra_class) . '" role="group" aria-label="Export">'
         . '<a class="btn btn-outline-success" href="' . e($base . 'export=csv') . '" title="Download as CSV (Excel)"><i class="bi bi-filetype-csv me-1"></i>CSV</a>'
         . '<a class="btn btn-outline-danger" href="' . e($base . 'export=pdf') . '" title="Download as PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>'
         . '</span>';
}

/** Send the report and stop. */
function report_export(string $fmt, string $fname, string $title, string $subtitle, array $sections): never {
    $fname = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $fname);
    audit('Report export ' . strtoupper($fmt) . ': ' . $title, '', 0, [], ['file' => $fname, 'period' => $subtitle]);
    if ($fmt === 'csv') {
        $rows = [[$title], [$subtitle], ['Generated ' . date('Y-m-d H:i') . ' by ' . ($_SESSION['full_name'] ?? 'MANLOAD')]];
        foreach ($sections as $s) {
            $rows[] = [];
            if (!empty($s['title'])) $rows[] = [$s['title']];
            $rows[] = $s['header'];
            foreach ($s['rows'] as $r) $rows[] = array_values($r);
            if (!empty($s['total'])) $rows[] = $s['total'];
        }
        $first = array_shift($rows);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
        header('X-Content-Type-Options: nosniff');
        echo csv_string($first, $rows);
        exit;
    }
    $pdf = new SimplePdf($title, $subtitle);
    foreach ($sections as $s) $pdf->table($s['title'] ?? '', $s['header'], $s['rows'], $s['total'] ?? [], $s['align'] ?? []);
    $out = $pdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '.pdf"');
    header('Content-Length: ' . strlen($out));
    header('X-Content-Type-Options: nosniff');
    echo $out;
    exit;
}

/** Money for exports: plain number (CSV) — PDFs get "PHP 1,234.00" via pdf_money(). */
function x_money($v): string { return number_format((float)$v, 2, '.', ''); }

/**
 * Minimal PDF writer — A4 landscape, Helvetica / Helvetica-Bold (WinAnsi), text tables only.
 */
final class SimplePdf {
    private const W = 842.0, H = 595.0, M = 32.0, ROW = 15.0, FS = 8.0;
    private const WIDTHS = [   // Helvetica glyph widths (1/1000 em), chars 32..126
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private array $pages = [];
    private string $cur = '';
    private float $y = 0;
    private int $page = 0;

    public function __construct(private string $title, private string $subtitle) { $this->newPage(); }

    private static function enc(string $s): string {
        $s = str_replace(['₱', '–', '—', '→', '•', '…', '✓'], ['PHP ', '-', '-', '->', '*', '...', 'v'], $s);
        $s = (string)@iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    private static function textWidth(string $s, float $size, bool $bold = false): float {
        $w = 0;
        $s = (string)@iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', str_replace('₱', 'PHP ', $s));
        for ($i = 0, $n = strlen($s); $i < $n; $i++) { $c = ord($s[$i]); $w += self::WIDTHS[$c - 32] ?? 556; }
        return $w * $size / 1000 * ($bold ? 1.06 : 1.0);
    }

    private function fit(string $s, float $max, float $size, bool $bold): string {
        if (self::textWidth($s, $size, $bold) <= $max) return $s;
        while (mb_strlen($s) > 1 && self::textWidth($s . '...', $size, $bold) > $max) $s = mb_substr($s, 0, -1);
        return $s . '...';
    }

    private function text(float $x, float $y, string $s, float $size = self::FS, bool $bold = false, string $align = 'L', float $w = 0): void {
        if ($align === 'R') $x += $w - self::textWidth($s, $size, $bold);
        elseif ($align === 'C') $x += ($w - self::textWidth($s, $size, $bold)) / 2;
        $this->cur .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, self::enc($s));
    }

    private function rect(float $x, float $y, float $w, float $h, string $gray = '0.93'): void {
        $this->cur .= sprintf("%s g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $y, $w, $h);
    }

    private function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->cur .= sprintf("0.75 G 0.5 w %.2F %.2F m %.2F %.2F l S 0 G\n", $x1, $y1, $x2, $y2);
    }

    private function newPage(): void {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = '';
        $this->page++;
        $top = self::H - self::M;
        $this->text(self::M, $top - 12, 'MANLOAD — ' . $this->title, 14, true);
        $this->text(self::M, $top - 27, $this->subtitle, 9);
        $this->text(self::M, $top - 27, 'Generated ' . date('M j, Y g:i A'), 8, false, 'R', self::W - 2 * self::M);
        $this->line(self::M, $top - 33, self::W - self::M, $top - 33);
        $this->y = $top - 50;
    }

    /** Column widths from the content, scaled to the page width. */
    private function widths(array $header, array $rows, array $total): array {
        $n = count($header);
        $w = array_map(fn($h) => self::textWidth((string)$h, self::FS, true) + 8, $header);
        foreach (array_slice(array_merge($rows, [$total]), 0, 400) as $r) {
            $r = array_values($r);
            for ($i = 0; $i < $n; $i++) $w[$i] = max($w[$i], min(220, self::textWidth((string)($r[$i] ?? ''), self::FS) + 8));
        }
        $avail = self::W - 2 * self::M;
        $sum = array_sum($w) ?: 1;
        return array_map(fn($x) => $x * $avail / $sum, $w);
    }

    private function row(array $cells, array $w, array $align, bool $bold, bool $shade): void {
        if ($this->y < self::M + self::ROW) $this->newPage();
        $x = self::M;
        if ($shade) $this->rect($x, $this->y - 4, self::W - 2 * self::M, self::ROW, '0.92');
        foreach ($w as $i => $cw) {
            $s = $this->fit((string)($cells[$i] ?? ''), $cw - 6, self::FS, $bold);
            $this->text($x + 3, $this->y, $s, self::FS, $bold, $align[$i] ?? 'L', $cw - 6);
            $x += $cw;
        }
        $this->line(self::M, $this->y - 4, self::W - self::M, $this->y - 4);
        $this->y -= self::ROW;
    }

    public function table(string $title, array $header, array $rows, array $total = [], array $align = []): void {
        if ($this->y < self::M + 4 * self::ROW) $this->newPage();
        if ($title !== '') { $this->y -= 4; $this->text(self::M, $this->y, $title, 10.5, true); $this->y -= self::ROW + 2; }
        $w = $this->widths($header, $rows, $total);
        $this->row($header, $w, $align, true, true);
        if (!$rows) $this->row(['(no data)'], [self::W - 2 * self::M], [], false, false);
        foreach ($rows as $r) {
            if ($this->y < self::M + self::ROW) { $this->newPage(); $this->row($header, $w, $align, true, true); }
            $this->row(array_values($r), $w, $align, false, false);
        }
        if ($total) $this->row(array_values($total), $w, $align, true, true);
        $this->y -= 10;
    }

    public function output(): string {
        $this->pages[] = $this->cur;
        $n = count($this->pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (5 + 2 * $i) . ' 0 R';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $content) {
            $content .= sprintf("BT /F1 7.5 Tf %.2F %.2F Td (Page %d of %d) Tj ET\n", self::W - self::M - 50, self::M - 14, $i + 1, $n);
            $objs[5 + 2 * $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] '
                              . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . (6 + 2 * $i) . ' 0 R >>';
            $objs[6 + 2 * $i] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        ksort($objs);
        foreach ($objs as $k => $o) { $off[$k] = strlen($out); $out .= "$k 0 obj\n$o\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R /Info << /Producer (MANLOAD) /Title (" . self::enc($this->title) . ") >> >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}
