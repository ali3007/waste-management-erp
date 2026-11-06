<?php
/**
 * Minimal streaming .xlsx writer (one sheet), built on ZipArchive only —
 * no Composer packages needed on XAMPP or a production server.
 *
 * Rows are appended to a temporary sheet file as they arrive, so exporting
 * tens of thousands of complaints never holds the whole table in memory.
 * All text is written as inline strings, which Excel always treats as TEXT:
 * a value such as "=1+1" or "@cmd" can never run as a formula.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

final class CmpXlsx
{
    private string $sheetPath;
    /** @var resource */
    private $sheet;
    private int $row = 0;
    private array $widths = [];
    private int $cols = 0;

    public function __construct(private string $sheetTitle = 'Complaints')
    {
        $dir = defined('CMP_TMP_DIR') ? CMP_TMP_DIR : sys_get_temp_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
        $this->sheetPath = tempnam($path, 'xlsx') ?: ($path . '/xlsx' . bin2hex(random_bytes(4)));
        $this->sheet = fopen($this->sheetPath, 'w+b');
    }

    /** Column letters for a zero-based index (0 → A, 26 → AA). */
    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    /** XML-safe text: strips characters XML 1.0 forbids, escapes the rest. */
    private static function xml(string $v): string
    {
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v) ?? '';
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Append one row. $header = bold + shaded. Numbers (int/float) stay numeric. */
    public function addRow(array $cells, bool $header = false): void
    {
        $this->row++;
        $this->cols = max($this->cols, count($cells));
        $xml = '<row r="' . $this->row . '">';
        foreach (array_values($cells) as $i => $v) {
            $ref = self::col($i) . $this->row;
            $style = $header ? ' s="1"' : '';
            if ($v === null || $v === '') {
                $xml .= '<c r="' . $ref . '"' . $style . '/>';
            } elseif (is_int($v) || is_float($v)) {
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } else {
                $text = (string)$v;
                $len = min(60, mb_strlen($text));
                $this->widths[$i] = max($this->widths[$i] ?? 8, $len + 2);
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::xml($text) . '</t></is></c>';
            }
        }
        fwrite($this->sheet, $xml . '</row>');
    }

    /** Finish and return the path of the finished .xlsx (caller deletes it). */
    public function finish(): string
    {
        fflush($this->sheet);
        $out = tempnam(sys_get_temp_dir(), 'xlsxout') ?: ($this->sheetPath . '.xlsx');

        $zip = new ZipArchive();
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Excel file.');
        }
        $title = self::xml(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->sheetTitle) ?? 'Sheet1', 0, 31));

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $title . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF17A06A"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" wrapText="1"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

        $cols = '';
        foreach ($this->widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(8, $w)) . '" customWidth="1"/>';
        }
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>';
        $tail = '</sheetData>' . ($this->row > 0 && $this->cols > 0 ? '<autoFilter ref="A1:' . self::col($this->cols - 1) . $this->row . '"/>' : '') . '</worksheet>';

        $assembled = tempnam(sys_get_temp_dir(), 'xlsxsheet') ?: ($this->sheetPath . '.sheet');
        $dst = fopen($assembled, 'wb');
        fwrite($dst, $head);
        rewind($this->sheet);
        stream_copy_to_stream($this->sheet, $dst);
        fwrite($dst, $tail);
        fclose($dst);
        $zip->addFile($assembled, 'xl/worksheets/sheet1.xml');
        $zip->close();

        fclose($this->sheet);
        @unlink($this->sheetPath);
        @unlink($assembled);
        return $out;
    }
}
