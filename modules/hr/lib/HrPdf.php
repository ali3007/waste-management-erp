<?php
/**
 * Letterhead + footer for the HR PDFs. Loaded only after TCPDF has been
 * booted (see hr_pdf_class() in pdf.php), because it extends TCPDF.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

class HrPdf extends TCPDF
{
    public array $brand = [];
    public ?string $logoJpeg = null;
    public string $docLabel = '';
    public string $generatedOn = '';
    public bool $letterhead = true;

    public function Header(): void
    {
        if (!$this->letterhead) {
            return;
        }
        $left = $this->lMargin;
        $textX = $left;
        if ($this->logoJpeg !== null) {
            $this->Image($this->logoJpeg, $left, 8, 0, 15, 'JPG', '', '', false, 300);
            $textX = $left + 20;
        }
        $this->SetXY($textX, 8);
        $this->SetFont('dejavusans', 'B', 13);
        $this->SetTextColor(23, 90, 62);
        $this->Cell(0, 7, (string)($this->brand['name'] ?? ''), 0, 1, 'L');
        $this->SetX($textX);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(95, 110, 105);
        $line = trim(implode('  |  ', array_filter([
            (string)($this->brand['address'] ?? ''),
            (string)($this->brand['phone'] ?? ''),
            (string)($this->brand['email'] ?? ''),
        ])));
        $this->MultiCell($this->getPageWidth() - $this->rMargin - $textX, 4, $line, 0, 'L', false, 1);

        $this->SetDrawColor(23, 160, 106);
        $this->SetLineWidth(0.6);
        $y = 25;
        $this->Line($left, $y, $this->getPageWidth() - $this->rMargin, $y);
        $this->SetLineWidth(0.2);
    }

    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetDrawColor(200, 208, 205);
        $this->Line($this->lMargin, $this->GetY(), $this->getPageWidth() - $this->rMargin, $this->GetY());
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(120, 130, 126);
        $this->Cell(0, 6, $this->docLabel . '  ·  Generated ' . $this->generatedOn, 0, 0, 'L');
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}
