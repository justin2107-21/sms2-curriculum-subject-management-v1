<?php

require_once __DIR__ . '/../../../shared/lib/fpdf/fpdf.php';

/**
 * PDF of the curriculum export, drawn from the SAME dataset array as the CSV and the preview
 * (CurriculumExportService::dataset). No encryption here: protected exports put the PDF inside the AES-256 ZIP.
 * FPDF's core fonts are Windows-1252; text is mapped from UTF-8 and anything unmappable becomes "?" (never an exception).
 */
final class CurriculumPdfRenderer extends FPDF
{
    private array $d = [];
    private const CODE_BLUE = [33, 99, 197];   // --csms-code-blue (#2163c5)
    private const INK = [31, 41, 55];
    private const MUTED = [100, 116, 139];

    /** UTF-8 to Windows-1252 for core fonts. Common typography is mapped first, the rest through mbstring; unknown characters become "?". */
    public static function latin1(mixed $value): string
    {
        $s = (string)$value;
        if ($s === '') return '';
        // Invalid UTF-8 bytes become "?" instead of making the next steps fail.
        $previousSub = mb_substitute_character();
        mb_substitute_character(0x3F);
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        mb_substitute_character($previousSub);
        $s = strtr($s, [
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2212}" => '-', "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ',
            "\u{200B}" => '', "\u{FEFF}" => '', "\u{2192}" => '->', "\u{2190}" => '<-', "\u{2264}" => '<=', "\u{2265}" => '>=', "\u{00B7}" => "\u{00B7}",
        ]);
        // Replace control characters (keep nothing that could break the content stream).
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $s) ?? '';
        $previous = mb_substitute_character();
        mb_substitute_character(0x3F);   // "?"
        try {
            $converted = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
        return is_string($converted) ? $converted : '?';
    }

    public static function render(array $dataset): string
    {
        $pdf = new self('P', 'mm', 'A4');
        $pdf->d = $dataset;
        $pdf->SetCompression(true);
        $pdf->SetTitle(self::latin1('Curriculum Structure - ' . ($dataset['context']['curriculum_code'] ?? '') . ' ' . ($dataset['context']['version_label'] ?? '')));
        $pdf->SetAuthor('Curriculum & Subject Management System');
        $pdf->SetCreator('CSMS');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->body();
        return $pdf->Output('S');
    }

    public function Header(): void
    {
        if ($this->PageNo() === 1) return;
        $c = $this->d['context'] ?? [];
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...self::MUTED);
        $this->Cell(0, 5, self::latin1(($c['program_code'] ?? '') . ' | ' . ($c['curriculum_code'] ?? '') . ' | ' . ($c['version_label'] ?? '') . ' | ' . ($this->d['scope']['label'] ?? '')), 0, 1, 'L');
        $this->SetDrawColor(226, 232, 240);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(3);
        $this->SetTextColor(...self::INK);
    }

    public function Footer(): void
    {
        $this->SetY(-14);
        $this->SetDrawColor(226, 232, 240);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...self::MUTED);
        $this->Cell(150, 6, self::latin1('Generated from stored curriculum data (draft/approved status as shown)'), 0, 0, 'L');
        $this->Cell(30, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }

    private function body(): void
    {
        $c = $this->d['context'];
        $this->SetTextColor(...self::MUTED);
        $this->SetFont('Helvetica', 'B', 8);
        $this->Cell(0, 5, self::latin1(strtoupper((string)$c['program_name'])), 0, 1);
        $this->SetTextColor(...self::INK);
        $this->SetFont('Helvetica', 'B', 17);
        $this->Cell(0, 9, 'Curriculum Structure', 0, 1);
        $this->SetFont('Helvetica', 'B', 11);
        $this->MultiCell(0, 5.5, self::latin1($c['curriculum_code'] . ' - ' . $c['curriculum_name']), 0, 'L');
        $this->Ln(1.5);

        $status = ucfirst((string)$c['version_status']) . ' / review: ' . str_replace('_', ' ', (string)$c['version_review_status']);
        $rows = [
            ['Program', $c['program_code'] . ' - ' . $c['program_name']],
            ['Curriculum version', $c['version_label'] . ($c['effective_academic_year'] ? ' (effective ' . $c['effective_academic_year'] . ')' : '')],
            ['Version status', $status],
            ['Export scope', $this->d['scope']['label']],
            ['Generated', (string)$this->d['generated_at']],
        ];
        foreach ($rows as [$label, $value]) {
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->SetTextColor(...self::MUTED);
            $this->Cell(38, 5, self::latin1($label), 0, 0);
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(...self::INK);
            $this->MultiCell(0, 5, self::latin1($value), 0, 'L');
        }
        $this->Ln(3);

        if ((int)$this->d['summary']['total_subjects'] === 0) {
            $this->SetFont('Helvetica', 'I', 10);
            $this->Cell(0, 8, 'No mapped subjects were found for the selected export scope.', 0, 1);
        }

        foreach ($this->d['years'] as $year) {
            $this->ensureSpace(24);
            $this->SetFont('Helvetica', 'B', 11);
            $this->SetFillColor(238, 242, 250);
            $this->SetTextColor(...self::INK);
            $this->Cell(0, 7, self::latin1($year['year_level']), 0, 1, 'L', true);
            foreach ($year['semesters'] as $semester) {
                $this->ensureSpace(24);
                $this->SetFont('Helvetica', 'B', 9.5);
                $this->Cell(0, 7, self::latin1($semester['semester']), 0, 1);
                $this->tableHead();
                foreach ($semester['subjects'] as $subject) $this->subjectRow($subject);
                $this->SetFont('Helvetica', 'B', 8.5);
                $this->Cell(128, 6, 'Semester units', 0, 0, 'R');
                $this->Cell(18, 6, self::latin1($this->units($semester['total_units'])), 0, 1, 'R');
                $this->Ln(2);
            }
            $this->SetFont('Helvetica', 'B', 9);
            $this->Cell(128, 6, self::latin1($year['year_level'] . ' total units'), 0, 0, 'R');
            $this->Cell(18, 6, self::latin1($this->units($year['total_units'])), 0, 1, 'R');
            $this->Ln(4);
        }

        $this->ensureSpace(22);
        $this->SetDrawColor(...self::INK);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', 'B', 10);
        $this->Cell(90, 7, 'Total subjects: ' . (int)$this->d['summary']['total_subjects'], 0, 0);
        $this->Cell(0, 7, 'Total units: ' . self::latin1($this->units($this->d['summary']['total_units'])), 0, 1);
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...self::MUTED);
        $this->MultiCell(0, 4, self::latin1($this->d['disclaimer']), 0, 'L');
    }

    private function units(mixed $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->GetY() + $needed > $this->PageBreakTrigger) $this->AddPage();
    }

    private function tableHead(): void
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(248, 250, 252);
        $this->SetTextColor(...self::MUTED);
        $this->SetDrawColor(226, 232, 240);
        $this->Cell(28, 6, 'CODE', 'B', 0, 'L', true);
        $this->Cell(86, 6, 'TITLE', 'B', 0, 'L', true);
        $this->Cell(14, 6, 'UNITS', 'B', 0, 'R', true);
        $this->Cell(52, 6, 'TYPE', 'B', 1, 'L', true);
    }

    /** Number of lines a text needs in a cell of the given width (FPDF tutorial algorithm, simplified for word wrap). */
    private function lineCount(float $width, string $text): int
    {
        $max = $width - 2 * $this->cMargin;
        $lines = 1; $current = 0.0;
        foreach (preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
            $w = $this->GetStringWidth($token);
            if ($current + $w > $max && $current > 0 && trim($token) !== '') { $lines++; $current = 0.0; }
            while ($w > $max) {   // a single very long word: break it
                $lines++; $w -= $max;
            }
            $current += $w;
        }
        return $lines;
    }

    private function subjectRow(array $subject): void
    {
        $code = self::latin1($subject['subject_code']);
        $title = self::latin1($subject['subject_title']);
        $type = self::latin1($subject['placement_type']);
        $this->SetFont('Helvetica', '', 8.5);
        $lines = max($this->lineCount(86, $title), $this->lineCount(52, $type), $this->lineCount(28, $code));
        $height = 5 * $lines;
        $this->ensureSpace($height + 1);
        $x = $this->GetX(); $y = $this->GetY();
        $this->SetTextColor(...self::CODE_BLUE);
        $this->MultiCell(28, 5, $code, 0, 'L');
        $this->SetXY($x + 28, $y);
        $this->SetTextColor(...self::INK);
        $this->MultiCell(86, 5, $title, 0, 'L');
        $this->SetXY($x + 114, $y);
        $this->Cell(14, 5, self::latin1($this->units($subject['units'])), 0, 0, 'R');
        $this->SetXY($x + 128, $y);
        $this->MultiCell(52, 5, $type, 0, 'L');
        $this->SetDrawColor(241, 245, 249);
        $this->SetXY($x, $y + $height);
        $this->Line($x, $y + $height, $x + 180, $y + $height);
    }
}
