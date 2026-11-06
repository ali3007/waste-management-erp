<?php
/**
 * Complaints — PowerPoint generation from a user-uploaded design.
 *
 * WHY NOT A LIBRARY. PHP presentation libraries rebuild a deck from scratch
 * and drop most of a designed template (theme fonts, master graphics,
 * layouts). This engine instead edits the PPTX package itself (a ZIP of XML
 * parts) with ZipArchive + DOM, so the uploaded design survives intact.
 *
 * HOW A DECK IS BUILT (nothing hard-coded, no placeholders required):
 *   - The template file is COPIED to a private temp file; the original is
 *     never opened for writing.
 *   - The template's slide master(s), layouts, theme, fonts and background
 *     are kept untouched.
 *   - Slide 1 (Complaint Details) starts from the template's own first slide
 *     (its design shapes are kept) — or, if the template has no slide that
 *     suits, from the template's best "title only" style layout.
 *   - Slides 2..N+1 (one Before + one After each) start from the template's
 *     second slide, or from the same layout as slide 1.
 *   - The title placeholder is filled; other empty content placeholders are
 *     removed; complaint details, pictures and captions are added as normal,
 *     editable PowerPoint objects (a native table, native pictures) placed
 *     in the free area below the title, using the theme's own colours and
 *     fonts.
 *   - The template's sample slides are dropped from the output, and any part
 *     only they used (media, notes, comments, charts) is removed.
 * Limits (documented in the README): content that exists only on the
 * template's slides 3+ is not reused; animations on the cloned sample slides
 * are removed (they would point at shapes that no longer exist).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const CMP_PPT_MAX_BYTES = 60 * 1024 * 1024;
const CMP_PPT_MAX_ENTRIES = 3000;
const CMP_PPT_MAX_UNPACKED = 500 * 1024 * 1024;
const CMP_EMU_IN = 914400;

const CMP_NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
const CMP_NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
const CMP_NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const CMP_NS_CT = 'http://schemas.openxmlformats.org/package/2006/content-types';
const CMP_NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
const CMP_REL_SLIDE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide';
const CMP_REL_LAYOUT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout';
const CMP_REL_MASTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster';
const CMP_REL_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
const CMP_CT_SLIDE = 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml';

/* ------------------------------------------------------------------ *
 | Template validation (upload time)                                   |
 * ------------------------------------------------------------------ */

/** Temp folder for generated files (private, cleaned by the caller). */
function cmp_tmp_dir(): string
{
    $dir = CMP_TMP_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = rtrim(sys_get_temp_dir(), '/\\');
    }
    return $dir;
}

function cmp_tmp_file(string $suffix): string
{
    $path = cmp_tmp_dir() . '/cmp-' . bin2hex(random_bytes(8)) . $suffix;
    return $path;
}

/** Remove stale generated files (older than 2 hours) left by a crashed request. */
function cmp_tmp_cleanup(): void
{
    foreach (glob(cmp_tmp_dir() . '/cmp-*') ?: [] as $f) {
        if (is_file($f) && @filemtime($f) < time() - 7200) {
            @unlink($f);
        }
    }
}

/**
 * Inspect an uploaded .pptx. Returns an error string, or the facts about it.
 *
 * @return string|array{slide_count:int,layout_count:int,width:int,height:int}
 */
function cmp_ppt_inspect(string $path): string|array
{
    if (!class_exists('ZipArchive')) {
        return 'The server cannot read PowerPoint files (PHP zip extension is missing).';
    }
    $head = (string)@file_get_contents($path, false, null, 0, 4);
    if (!str_starts_with($head, "PK\x03\x04")) {
        return 'That file is not a valid PowerPoint (.pptx) presentation.';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return 'That file is not a valid PowerPoint (.pptx) presentation.';
    }

    $entries = $zip->numFiles;
    if ($entries < 1 || $entries > CMP_PPT_MAX_ENTRIES) {
        $zip->close();
        return 'That presentation has an unusual structure and was refused.';
    }
    $unpacked = 0;
    for ($i = 0; $i < $entries; $i++) {
        $st = $zip->statIndex($i);
        $name = (string)($st['name'] ?? '');
        $unpacked += (int)($st['size'] ?? 0);
        if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
            $zip->close();
            return 'That presentation contains unsafe file paths and was refused.';
        }
        if (preg_match('~(^|/)vbaProject\.bin$~i', $name) || preg_match('~\.(exe|dll|bat|cmd|js|vbs|ps1)$~i', $name)) {
            $zip->close();
            return 'Macro-enabled or executable content is not allowed. Save the template as a plain .pptx.';
        }
    }
    if ($unpacked > CMP_PPT_MAX_UNPACKED) {
        $zip->close();
        return 'That presentation is too large when unpacked.';
    }

    $ct = $zip->getFromName('[Content_Types].xml');
    $pres = $zip->getFromName('ppt/presentation.xml');
    if ($ct === false || $pres === false) {
        $zip->close();
        return 'That file is not a valid PowerPoint presentation (core parts are missing).';
    }
    if (stripos($ct, 'macroEnabled') !== false) {
        $zip->close();
        return 'Macro-enabled presentations are not allowed. Save the template as a plain .pptx.';
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($pres, LIBXML_NONET)) {
        $zip->close();
        return 'The presentation is damaged (presentation.xml cannot be read).';
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('p', CMP_NS_P);
    $size = $xp->query('//p:sldSz')->item(0);
    $width = $size instanceof DOMElement ? (int)$size->getAttribute('cx') : 0;
    $height = $size instanceof DOMElement ? (int)$size->getAttribute('cy') : 0;
    $slides = $xp->query('//p:sldIdLst/p:sldId')->length;

    $layouts = 0;
    for ($i = 0; $i < $entries; $i++) {
        if (preg_match('~^ppt/slideLayouts/slideLayout\d+\.xml$~', (string)$zip->getNameIndex($i))) {
            $layouts++;
        }
    }
    $zip->close();

    if ($layouts < 1 || $width < 1 || $height < 1) {
        return 'The presentation has no slide layouts, so it cannot be used as a design.';
    }
    return ['slide_count' => $slides, 'layout_count' => $layouts, 'width' => $width, 'height' => $height];
}

/* ------------------------------------------------------------------ *
 | Package builder                                                     |
 * ------------------------------------------------------------------ */

final class CmpPptBuilder
{
    public ZipArchive $zip;
    private DOMDocument $ct;
    private DOMDocument $pres;
    private DOMDocument $presRels;
    public int $width;
    public int $height;
    private int $slideCount = 0;
    private int $mediaCount = 0;
    private array $mediaKeys = [];
    private array $newSlides = [];
    /** @var array<string,bool> parts that existed in the template */
    private array $original = [];
    /** @var array<string,bool> parts referenced by slides we create */
    private array $referencedExtra = [];

    public function __construct(private string $file)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($file) !== true) {
            throw new RuntimeException('The template could not be opened.');
        }
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $this->original[(string)$this->zip->getNameIndex($i)] = true;
        }
        $this->ct = $this->loadPart('[Content_Types].xml');
        $this->pres = $this->loadPart('ppt/presentation.xml');
        $this->presRels = $this->loadPart('ppt/_rels/presentation.xml.rels');
        $xp = $this->xp($this->pres);
        $size = $xp->query('//p:sldSz')->item(0);
        $this->width = (int)$size->getAttribute('cx');
        $this->height = (int)$size->getAttribute('cy');
    }

    /* ---- low-level helpers ---- */
    public function loadPart(string $name): DOMDocument
    {
        $xml = $this->zip->getFromName($name);
        if ($xml === false) {
            throw new RuntimeException('The template is missing part ' . $name . '.');
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('The template part ' . $name . ' is damaged.');
        }
        return $dom;
    }

    public function xp(DOMDocument $d): DOMXPath
    {
        $xp = new DOMXPath($d);
        $xp->registerNamespace('p', CMP_NS_P);
        $xp->registerNamespace('a', CMP_NS_A);
        $xp->registerNamespace('r', CMP_NS_R);
        $xp->registerNamespace('ct', CMP_NS_CT);
        $xp->registerNamespace('rel', CMP_NS_REL);
        return $xp;
    }

    public static function relsPath(string $part): string
    {
        return dirname($part) . '/_rels/' . basename($part) . '.rels';
    }

    /** Resolve a relationship target relative to its source part. */
    public static function resolve(string $sourcePart, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $parts = explode('/', dirname($sourcePart) . '/' . $target);
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                array_pop($out);
            } else {
                $out[] = $p;
            }
        }
        return implode('/', $out);
    }

    /** @return array<string,array{type:string,target:string,mode:string,path:string}> keyed by rId */
    public function rels(string $part): array
    {
        $xml = $this->zip->getFromName(self::relsPath($part));
        if ($xml === false) {
            return [];
        }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return [];
        }
        $out = [];
        foreach ($dom->getElementsByTagNameNS(CMP_NS_REL, 'Relationship') as $r) {
            $mode = $r->getAttribute('TargetMode') ?: 'Internal';
            $out[$r->getAttribute('Id')] = [
                'type' => $r->getAttribute('Type'), 'target' => $r->getAttribute('Target'), 'mode' => $mode,
                'path' => $mode === 'External' ? '' : self::resolve($part, $r->getAttribute('Target')),
            ];
        }
        return $out;
    }

    /* ---- reading the design ---- */

    /** Slide part names in presentation order. */
    public function slideParts(): array
    {
        $rels = [];
        foreach ($this->xp($this->presRels)->query('//rel:Relationship') as $r) {
            $rels[$r->getAttribute('Id')] = self::resolve('ppt/presentation.xml', $r->getAttribute('Target'));
        }
        $out = [];
        foreach ($this->xp($this->pres)->query('//p:sldIdLst/p:sldId') as $s) {
            $rid = $s->getAttributeNS(CMP_NS_R, 'id');
            if (isset($rels[$rid])) {
                $out[] = $rels[$rid];
            }
        }
        return $out;
    }

    /** All layout part names. */
    public function layoutParts(): array
    {
        $out = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $n = (string)$this->zip->getNameIndex($i);
            if (preg_match('~^ppt/slideLayouts/slideLayout\d+\.xml$~', $n)) {
                $out[] = $n;
            }
        }
        natsort($out);
        return array_values($out);
    }

    /** Layout facts: type, whether it has a title and where it sits. */
    public function analyseLayout(string $layoutPart): array
    {
        $dom = $this->loadPart($layoutPart);
        $xp = $this->xp($dom);
        $root = $dom->documentElement;
        $type = $root->getAttribute('type');
        $titleBox = $this->placeholderBox($layoutPart, $dom, ['title', 'ctrTitle']);
        $hasTitle = $xp->query('//p:sp/p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"]')->length > 0;
        $others = 0;
        foreach ($xp->query('//p:sp/p:nvSpPr/p:nvPr/p:ph') as $ph) {
            $t = $ph->getAttribute('type') ?: 'body';
            if (!in_array($t, ['title', 'ctrTitle', 'dt', 'ftr', 'sldNum'], true)) {
                $others++;
            }
        }
        return ['part' => $layoutPart, 'type' => $type, 'hasTitle' => $hasTitle, 'titleBox' => $titleBox, 'others' => $others,
                'isCentered' => $xp->query('//p:sp/p:nvSpPr/p:nvPr/p:ph[@type="ctrTitle"]')->length > 0];
    }

    /** Geometry [x,y,cx,cy] of a placeholder on a layout, falling back to its master. */
    private function placeholderBox(string $layoutPart, DOMDocument $layoutDom, array $types): ?array
    {
        $find = function (DOMDocument $d) use ($types): ?array {
            $xp = $this->xp($d);
            foreach ($xp->query('//p:sp[p:nvSpPr/p:nvPr/p:ph]') as $sp) {
                $ph = $xp->query('p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
                if (in_array($ph->getAttribute('type'), $types, true)) {
                    $off = $xp->query('p:spPr/a:xfrm/a:off', $sp)->item(0);
                    $ext = $xp->query('p:spPr/a:xfrm/a:ext', $sp)->item(0);
                    if ($off instanceof DOMElement && $ext instanceof DOMElement) {
                        return [(int)$off->getAttribute('x'), (int)$off->getAttribute('y'), (int)$ext->getAttribute('cx'), (int)$ext->getAttribute('cy')];
                    }
                }
            }
            return null;
        };
        $box = $find($layoutDom);
        if ($box !== null) {
            return $box;
        }
        foreach ($this->rels($layoutPart) as $rel) {
            if ($rel['type'] === CMP_REL_MASTER && $rel['path'] !== '') {
                return $find($this->loadPart($rel['path']));
            }
        }
        return null;
    }

    /** Geometry of the title on a (cloned or fresh) slide: its own, else its layout's. */
    public function slideTitleBox(DOMDocument $slide, string $layoutPart): ?array
    {
        $xp = $this->xp($slide);
        foreach ($xp->query('//p:sp[p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"]]') as $sp) {
            $off = $xp->query('p:spPr/a:xfrm/a:off', $sp)->item(0);
            $ext = $xp->query('p:spPr/a:xfrm/a:ext', $sp)->item(0);
            if ($off instanceof DOMElement && $ext instanceof DOMElement) {
                return [(int)$off->getAttribute('x'), (int)$off->getAttribute('y'), (int)$ext->getAttribute('cx'), (int)$ext->getAttribute('cy')];
            }
        }
        return $this->analyseLayout($layoutPart)['titleBox'];
    }

    /** The layout best suited to carry generated content (a title near the top, little else). */
    public function bestLayout(): array
    {
        $best = null;
        $bestScore = -1;
        foreach ($this->layoutParts() as $part) {
            $a = $this->analyseLayout($part);
            $score = 0;
            if ($a['type'] === 'titleOnly') {
                $score = 100;
            } elseif ($a['hasTitle'] && !$a['isCentered'] && $a['titleBox'] !== null && $a['titleBox'][1] < $this->height * 0.3) {
                $score = 60 - min(30, $a['others'] * 10);
            } elseif ($a['type'] === 'blank') {
                $score = 20;
            } elseif ($a['hasTitle']) {
                $score = 5;
            }
            if ($score > $bestScore) {
                $best = $a;
                $bestScore = $score;
            }
        }
        if ($best === null) {
            throw new RuntimeException('The template has no usable slide layout.');
        }
        return $best;
    }

    /**
     * An existing template slide as a clone-able base, or null when it is
     * missing or unsuitable (title not near the top → e.g. a title slide).
     *
     * @return array{xml:string,layout:string,rels:array}|null
     */
    public function baseFromSlide(int $index): ?array
    {
        $parts = $this->slideParts();
        if (!isset($parts[$index])) {
            return null;
        }
        $part = $parts[$index];
        $rels = $this->rels($part);
        $layout = null;
        foreach ($rels as $r) {
            if ($r['type'] === CMP_REL_LAYOUT) {
                $layout = $r['path'];
            }
        }
        if ($layout === null || $this->zip->locateName($layout) === false) {
            return null;
        }
        $dom = $this->loadPart($part);
        $a = $this->analyseLayout($layout);
        $box = $this->slideTitleBox($dom, $layout);
        $titleFine = $box !== null && !$a['isCentered'] && $box[1] < $this->height * 0.32;
        if (!$titleFine) {
            return null;
        }
        $keep = [];
        foreach ($rels as $id => $r) {
            if (in_array($r['type'], [CMP_REL_LAYOUT, CMP_REL_IMAGE, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink'], true)) {
                $keep[$id] = $r;
            }
        }
        return ['xml' => (string)$this->zip->getFromName($part), 'layout' => $layout, 'rels' => $keep];
    }

    /* ---- removing the template's sample slides ---- */

    public function removeAllSlides(): void
    {
        $parts = $this->slideParts();
        $removeRelIds = [];
        foreach ($this->xp($this->presRels)->query('//rel:Relationship') as $r) {
            if ($r->getAttribute('Type') === CMP_REL_SLIDE) {
                $removeRelIds[] = $r;
            }
        }
        foreach ($removeRelIds as $node) {
            $node->parentNode->removeChild($node);
        }
        $xp = $this->xp($this->pres);
        foreach ($xp->query('//p:sldIdLst') as $n) {
            $n->parentNode->removeChild($n);
        }
        /* Things that point at slide ids must go too: sections, custom shows. */
        foreach ($xp->query('//p:custShowLst') as $n) {
            $n->parentNode->removeChild($n);
        }
        foreach ($xp->query('//p:extLst/p:ext[@uri="{521415D9-36F7-43E2-AB2F-B90AF26B5E84}"]') as $n) {
            $n->parentNode->removeChild($n);   // p14:sectionLst
        }
        foreach ($xp->query('//p:extLst[not(*)]') as $n) {
            $n->parentNode->removeChild($n);
        }

        foreach ($parts as $part) {
            $this->zip->deleteName($part);
            $this->zip->deleteName(self::relsPath($part));
            $this->removeOverride('/' . $part);
        }
    }

    private function removeOverride(string $partName): void
    {
        foreach ($this->xp($this->ct)->query('//ct:Override[@PartName="' . $partName . '"]') as $n) {
            $n->parentNode->removeChild($n);
        }
    }

    /* ---- adding slides and media ---- */

    private function ensureDefault(string $ext, string $mime): void
    {
        $xp = $this->xp($this->ct);
        if ($xp->query('//ct:Default[@Extension="' . $ext . '"]')->length === 0) {
            $d = $this->ct->createElementNS(CMP_NS_CT, 'Default');
            $d->setAttribute('Extension', $ext);
            $d->setAttribute('ContentType', $mime);
            $this->ct->documentElement->insertBefore($d, $this->ct->documentElement->firstChild);
        }
    }

    /** Add a picture file as a media part (de-duplicated by source path). Returns its part name. */
    public function addMedia(string $srcPath, string $ext): string
    {
        $key = $srcPath;
        if (isset($this->mediaKeys[$key])) {
            return $this->mediaKeys[$key];
        }
        $this->mediaCount++;
        $name = 'ppt/media/cmpimage' . $this->mediaCount . '.' . $ext;
        $this->ensureDefault($ext, ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream');
        $this->zip->addFile($srcPath, $name);
        $this->referencedExtra[$name] = true;
        return $this->mediaKeys[$key] = $name;
    }

    /**
     * Register a finished slide. $rels = [rId => [type, targetPartName]] (all
     * internal; targets are written relative to ppt/slides/).
     */
    public function addSlide(string $xml, array $rels): int
    {
        $this->slideCount++;
        $n = $this->slideCount;
        $part = 'ppt/slides/slide' . $n . '.xml';
        $this->zip->addFromString($part, $xml);

        $relXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . CMP_NS_REL . '">';
        foreach ($rels as $id => $r) {
            if (($r['mode'] ?? 'Internal') === 'External') {
                $relXml .= '<Relationship Id="' . htmlspecialchars($id, ENT_XML1) . '" Type="' . htmlspecialchars($r['type'], ENT_XML1) . '" Target="' . htmlspecialchars($r['target'], ENT_XML1) . '" TargetMode="External"/>';
                continue;
            }
            $this->referencedExtra[$r['path']] = true;
            $target = '../' . preg_replace('~^ppt/~', '', $r['path']);
            $relXml .= '<Relationship Id="' . htmlspecialchars($id, ENT_XML1) . '" Type="' . htmlspecialchars($r['type'], ENT_XML1) . '" Target="' . htmlspecialchars($target, ENT_XML1) . '"/>';
        }
        $this->zip->addFromString(self::relsPath($part), $relXml . '</Relationships>');

        $o = $this->ct->createElementNS(CMP_NS_CT, 'Override');
        $o->setAttribute('PartName', '/' . $part);
        $o->setAttribute('ContentType', CMP_CT_SLIDE);
        $this->ct->documentElement->appendChild($o);

        /* presentation.xml.rels */
        $existing = [];
        foreach ($this->xp($this->presRels)->query('//rel:Relationship') as $r) {
            $existing[] = $r->getAttribute('Id');
        }
        $rid = 'rIdCmp' . $n;
        $rel = $this->presRels->createElementNS(CMP_NS_REL, 'Relationship');
        $rel->setAttribute('Id', $rid);
        $rel->setAttribute('Type', CMP_REL_SLIDE);
        $rel->setAttribute('Target', 'slides/slide' . $n . '.xml');
        $this->presRels->documentElement->appendChild($rel);
        $this->newSlides[] = $rid;
        return $n;
    }

    /** Write presentation.xml with the new slide list in the right schema position. */
    private function writeSlideList(): void
    {
        $xp = $this->xp($this->pres);
        $root = $this->pres->documentElement;
        $list = $this->pres->createElementNS(CMP_NS_P, 'p:sldIdLst');
        foreach ($this->newSlides as $i => $rid) {
            $id = $this->pres->createElementNS(CMP_NS_P, 'p:sldId');
            $id->setAttribute('id', (string)(256 + $i));
            $id->setAttributeNS(CMP_NS_R, 'r:id', $rid);
            $list->appendChild($id);
        }
        /* Schema order: sldMasterIdLst, notesMasterIdLst, handoutMasterIdLst, sldIdLst, sldSz … */
        $anchor = $xp->query('/p:presentation/p:sldSz')->item(0);
        if ($anchor !== null) {
            $root->insertBefore($list, $anchor);
        } else {
            $root->appendChild($list);
        }
    }

    /** Relationship targets listed in one .rels file of the ORIGINAL package. */
    private function relsFileTargets(string $relsName): array
    {
        $xml = $this->zip->getFromName($relsName);
        if ($xml === false) {
            return [];
        }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return [];
        }
        $sourceDir = str_starts_with($relsName, '_rels/') ? '' : dirname(dirname($relsName));
        $out = [];
        foreach ($dom->getElementsByTagNameNS(CMP_NS_REL, 'Relationship') as $r) {
            if ($r->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $out[] = self::resolve(($sourceDir === '' || $sourceDir === '.' ? '' : $sourceDir . '/') . 'x', $r->getAttribute('Target'));
        }
        return $out;
    }

    /**
     * Delete template parts that only the removed sample slides used
     * (media, notes, comments, charts …). Only parts that existed in the
     * template are ever deleted; anything a new slide references is kept.
     */
    private function collectGarbage(): void
    {
        $folders = ['ppt/media/', 'ppt/notesSlides/', 'ppt/comments/', 'ppt/charts/', 'ppt/diagrams/', 'ppt/embeddings/', 'ppt/tags/', 'ppt/ink/'];
        for ($pass = 0; $pass < 4; $pass++) {
            $referenced = $this->referencedExtra;
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $name = (string)$this->zip->getNameIndex($i);
                if (!isset($this->original[$name]) || !str_ends_with($name, '.rels')) {
                    continue;
                }
                foreach ($this->relsFileTargets($name) as $target) {
                    $referenced[$target] = true;
                }
            }
            $deleted = 0;
            for ($i = $this->zip->numFiles - 1; $i >= 0; $i--) {
                $name = (string)$this->zip->getNameIndex($i);
                if (!isset($this->original[$name]) || str_ends_with($name, '.rels') || isset($referenced[$name])) {
                    continue;
                }
                foreach ($folders as $f) {
                    if (str_starts_with($name, $f)) {
                        $this->zip->deleteName($name);
                        $this->zip->deleteName(self::relsPath($name));
                        $this->removeOverride('/' . $name);
                        $deleted++;
                        break;
                    }
                }
            }
            if ($deleted === 0) {
                break;
            }
        }
    }

    /** docProps/app.xml lists slide titles/counts of the TEMPLATE; replace with a minimal truthful one. */
    private function refreshAppProps(string $title): void
    {
        if ($this->zip->locateName('docProps/app.xml') !== false) {
            $this->zip->addFromString(
                'docProps/app.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
                . '<Application>Microsoft Office PowerPoint</Application><Slides>' . $this->slideCount . '</Slides><Notes>0</Notes><HiddenSlides>0</HiddenSlides></Properties>'
            );
        }
        if ($this->zip->locateName('docProps/core.xml') !== false) {
            $core = $this->zip->getFromName('docProps/core.xml');
            if ($core !== false) {
                $safe = htmlspecialchars($title, ENT_XML1);
                $core = preg_match('~<dc:title>.*?</dc:title>|<dc:title/>~s', $core)
                    ? preg_replace('~<dc:title>.*?</dc:title>|<dc:title/>~s', '<dc:title>' . $safe . '</dc:title>', $core, 1)
                    : preg_replace('~</cp:coreProperties>~', '<dc:title>' . $safe . '</dc:title></cp:coreProperties>', $core, 1);
                if ($core !== null) {
                    $this->zip->addFromString('docProps/core.xml', $core);
                }
            }
        }
    }

    public function finish(string $title): void
    {
        $this->writeSlideList();
        $this->collectGarbage();
        $this->zip->addFromString('[Content_Types].xml', $this->ct->saveXML());
        $this->zip->addFromString('ppt/presentation.xml', $this->pres->saveXML());
        $this->zip->addFromString('ppt/_rels/presentation.xml.rels', $this->presRels->saveXML());
        $this->refreshAppProps($title);
        if (!$this->zip->close()) {
            throw new RuntimeException('The presentation could not be written.');
        }
    }
}

/* ------------------------------------------------------------------ *
 | Slide XML helpers                                                   |
 * ------------------------------------------------------------------ */

function cmp_ppt_x(string $text): string
{
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** The skeleton of a slide with no shapes yet. */
function cmp_ppt_blank_slide_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sld xmlns:a="' . CMP_NS_A . '" xmlns:r="' . CMP_NS_R . '" xmlns:p="' . CMP_NS_P . '"><p:cSld><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
}

/**
 * Prepare a slide DOM: a clone of a template slide (cleaned) or a fresh one.
 * Fills the title, removes empty content placeholders, returns [dom, spTree, nextShapeId].
 */
function cmp_ppt_prepare_slide(CmpPptBuilder $b, ?array $base, array $layout, string $title, array &$rels): array
{
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->loadXML($base['xml'] ?? cmp_ppt_blank_slide_xml(), LIBXML_NONET);
    $xp = $b->xp($dom);
    $tree = $xp->query('//p:cSld/p:spTree')->item(0);

    if ($base !== null) {
        $rels = $base['rels'];
        /* Animations/transitions' shape references would dangle; drop animations. */
        foreach ($xp->query('/p:sld/p:timing') as $n) {
            $n->parentNode->removeChild($n);
        }
        /* Anything pointing at a relationship we did not keep is removed with its shape. */
        foreach ($xp->query('//@r:id | //@r:embed | //@r:link | //@r:pict | //@r:dm | //@r:lo | //@r:qs | //@r:cs') as $attr) {
            if (!isset($rels[$attr->value])) {
                $node = $attr->ownerElement;
                while ($node !== null && $node->parentNode !== $tree) {
                    $node = $node->parentNode;
                }
                if ($node !== null && $node->parentNode === $tree) {
                    $tree->removeChild($node);
                }
            }
        }
        /* Placeholders other than the title and footer-type ones are content slots we replace. */
        foreach ($xp->query('//p:sp[p:nvSpPr/p:nvPr/p:ph]') as $sp) {
            $ph = $xp->query('p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
            $type = $ph->getAttribute('type') ?: 'body';
            if (!in_array($type, ['title', 'ctrTitle', 'dt', 'ftr', 'sldNum'], true)) {
                $sp->parentNode->removeChild($sp);
            }
        }
        foreach ($xp->query('//p:pic[p:nvPicPr/p:nvPr/p:ph] | //p:graphicFrame[p:nvGraphicFramePr/p:nvPr/p:ph]') as $n) {
            $n->parentNode->removeChild($n);
        }
    } else {
        $rels = ['rId1' => ['type' => CMP_REL_LAYOUT, 'target' => '', 'mode' => 'Internal', 'path' => $layout['part']]];
        if ($layout['hasTitle']) {
            $frag = $dom->createDocumentFragment();
            $frag->appendXML('<p:sp xmlns:p="' . CMP_NS_P . '" xmlns:a="' . CMP_NS_A . '"><p:nvSpPr><p:cNvPr id="2" name="Title 1"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody></p:sp>');
            $tree->appendChild($frag);
        }
    }

    /* Fill the title: keep the first run's formatting, replace its text. */
    $titleSp = $xp->query('//p:sp[p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"]]')->item(0);
    if ($titleSp instanceof DOMElement) {
        $tx = $xp->query('p:txBody', $titleSp)->item(0);
        if ($tx instanceof DOMElement) {
            $paras = $xp->query('a:p', $tx);
            $first = $paras->item(0);
            for ($i = $paras->length - 1; $i >= 1; $i--) {
                $tx->removeChild($paras->item($i));
            }
            if ($first instanceof DOMElement) {
                $runs = $xp->query('a:r', $first);
                $keep = $runs->item(0);
                foreach ($xp->query('a:r | a:br | a:fld | a:endParaRPr', $first) as $n) {
                    if ($n !== $keep) {
                        $first->removeChild($n);
                    }
                }
                if ($keep instanceof DOMElement) {
                    $t = $xp->query('a:t', $keep)->item(0);
                    if ($t instanceof DOMElement) {
                        $t->nodeValue = '';
                        $t->appendChild($dom->createTextNode($title));
                    }
                } else {
                    $run = $dom->createDocumentFragment();
                    $run->appendXML('<a:r xmlns:a="' . CMP_NS_A . '"><a:rPr lang="en-US" dirty="0"/><a:t>' . cmp_ppt_x($title) . '</a:t></a:r>');
                    $first->appendChild($run);
                }
            }
            /* Let long titles shrink instead of overflowing the design. */
            $bodyPr = $xp->query('a:bodyPr', $tx)->item(0);
            if ($bodyPr instanceof DOMElement && $xp->query('a:normAutofit | a:spAutoFit | a:noAutofit', $bodyPr)->length === 0) {
                $bodyPr->appendChild($dom->createElementNS(CMP_NS_A, 'a:normAutofit'));
            }
        }
    }

    $maxId = 1;
    foreach ($xp->query('//p:cNvPr/@id') as $a) {
        $maxId = max($maxId, (int)$a->value);
    }
    return [$dom, $tree, $maxId + 1];
}

/** The free rectangle below the title: [x, y, cx, cy] in EMU. */
function cmp_ppt_region(CmpPptBuilder $b, DOMDocument $slide, string $layoutPart): array
{
    $W = $b->width;
    $H = $b->height;
    $box = $b->slideTitleBox($slide, $layoutPart);
    $margin = (int)(0.35 * CMP_EMU_IN);
    if ($box !== null && $box[1] + $box[3] < $H * 0.4 && $box[2] > $W * 0.4) {
        $x = $box[0];
        $y = $box[1] + $box[3] + (int)(0.1 * CMP_EMU_IN);
        return [$x, $y, $box[2], max((int)($H * 0.3), $H - $y - $margin)];
    }
    return [(int)($W * 0.05), (int)($H * 0.2), (int)($W * 0.9), (int)($H * 0.72)];
}

function cmp_ppt_textbox(int $id, string $name, int $x, int $y, int $cx, int $cy, string $text, int $size, bool $bold, string $align = 'ctr', bool $dashedBorder = false, bool $italic = false): string
{
    $ln = $dashedBorder
        ? '<a:ln w="12700"><a:solidFill><a:schemeClr val="tx1"><a:alpha val="35000"/></a:schemeClr></a:solidFill><a:prstDash val="dash"/></a:ln>'
        : '<a:noFill/>';
    return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . cmp_ppt_x($name) . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/>' . $ln . '</p:spPr>'
        . '<p:txBody><a:bodyPr wrap="square" lIns="45720" tIns="22860" rIns="45720" bIns="22860" anchor="ctr"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
        . '<a:p><a:pPr algn="' . $align . '"/><a:r><a:rPr lang="en-US" sz="' . $size . '" b="' . ($bold ? 1 : 0) . '" i="' . ($italic ? 1 : 0) . '" dirty="0"/><a:t>' . cmp_ppt_x($text) . '</a:t></a:r></a:p></p:txBody></p:sp>';
}

function cmp_ppt_picture(int $id, string $name, string $descr, string $rid, int $x, int $y, int $cx, int $cy): string
{
    return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="' . cmp_ppt_x($name) . '" descr="' . cmp_ppt_x($descr) . '"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
        . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
        . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
        . '<a:ln w="9525"><a:solidFill><a:schemeClr val="tx1"><a:alpha val="30000"/></a:schemeClr></a:solidFill></a:ln></p:spPr></p:pic>';
}

/** Append raw shape XML to the slide's shape tree. */
function cmp_ppt_append(DOMDocument $dom, DOMElement $tree, string $shapeXml): void
{
    $frag = $dom->createDocumentFragment();
    $wrapped = '<root xmlns:p="' . CMP_NS_P . '" xmlns:a="' . CMP_NS_A . '" xmlns:r="' . CMP_NS_R . '">' . $shapeXml . '</root>';
    $tmp = new DOMDocument();
    if (!@$tmp->loadXML($wrapped, LIBXML_NONET)) {
        throw new RuntimeException('Internal error building a slide shape.');
    }
    foreach ($tmp->documentElement->childNodes as $child) {
        $tree->appendChild($dom->importNode($child, true));
    }
}

/** Fit an image (w×h px) inside a box, centred. @return array{0:int,1:int,2:int,3:int} x,y,cx,cy */
function cmp_ppt_fit(int $bx, int $by, int $bw, int $bh, int $iw, int $ih): array
{
    $iw = max(1, $iw);
    $ih = max(1, $ih);
    $scale = min($bw / $iw, $bh / $ih);
    $cx = (int)round($iw * $scale);
    $cy = (int)round($ih * $scale);
    return [$bx + intdiv($bw - $cx, 2), $by + intdiv($bh - $cy, 2), $cx, $cy];
}

/* ------------------------------------------------------------------ *
 | Details table                                                       |
 * ------------------------------------------------------------------ */

/**
 * The complaint-details table as native PowerPoint XML, sized to fit the
 * region: the font shrinks and long text is shortened until it does.
 *
 * @param array<int,array{0:string,1:string}> $pairs   short fields, shown two per row
 * @param array<int,array{0:string,1:string}> $wide    long fields (full-width rows)
 */
function cmp_ppt_details_table(int $id, int $x, int $y, int $cx, int $cy, array $pairs, array $wide): string
{
    $labelW = (int)($cx * 0.16);
    $valueW = (int)($cx * 0.34);

    $build = function (int $size) use ($pairs, $wide, $labelW, $valueW, $cx): array {
        $pt = $size / 100;
        $lineH = (int)($pt * 1.25 * 12700);
        $pad = (int)(0.05 * CMP_EMU_IN);
        $charW = $pt * 0.52 * 12700;
        $rows = '';
        $total = 0;

        $cell = static function (string $text, bool $label, int $span = 1, bool $merged = false) use ($size): string {
            if ($merged) {
                return '<a:tc hMerge="1"><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></a:txBody><a:tcPr/></a:tc>';
            }
            $fill = $label
                ? '<a:solidFill><a:schemeClr val="accent1"><a:lumMod val="20000"/><a:lumOff val="80000"/></a:schemeClr></a:solidFill>'
                : '<a:noFill/>';
            $border = '<a:solidFill><a:schemeClr val="tx1"><a:alpha val="22000"/></a:schemeClr></a:solidFill>';
            $lines = '';
            foreach (['lnL', 'lnR', 'lnT', 'lnB'] as $l) {
                $lines .= '<a:' . $l . ' w="6350">' . $border . '</a:' . $l . '>';
            }
            $paras = '';
            foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
                $paras .= '<a:p><a:r><a:rPr lang="en-US" sz="' . $size . '" b="' . ($label ? 1 : 0) . '" dirty="0"/><a:t>' . cmp_ppt_x($line) . '</a:t></a:r></a:p>';
            }
            return '<a:tc' . ($span > 1 ? ' gridSpan="' . $span . '"' : '') . '><a:txBody><a:bodyPr/><a:lstStyle/>' . $paras . '</a:txBody>'
                . '<a:tcPr marL="72000" marR="72000" marT="36000" marB="36000" anchor="ctr">' . $lines . $fill . '</a:tcPr></a:tc>';
        };
        $estLines = static function (string $text, float $widthEmu, float $charW): int {
            $per = max(8, (int)floor(($widthEmu - 144000) / $charW));
            $n = 0;
            foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
                $n += max(1, (int)ceil(mb_strlen($line) / $per));
            }
            return $n;
        };

        for ($i = 0; $i < count($pairs); $i += 2) {
            $a = $pairs[$i];
            $c = $pairs[$i + 1] ?? ['', ''];
            $lines = max($estLines($a[1], $valueW, $charW), $estLines($c[1], $valueW, $charW));
            $h = max((int)(0.34 * CMP_EMU_IN), $lines * $lineH + 2 * $pad);
            $total += $h;
            $rows .= '<a:tr h="' . $h . '">' . $cell($a[0], true) . $cell($a[1], false) . $cell($c[0], $c[0] !== '') . $cell($c[1], false) . '</a:tr>';
        }
        foreach ($wide as [$label, $value]) {
            $lines = $estLines($value, $valueW * 2 + $labelW, $charW);
            $h = max((int)(0.34 * CMP_EMU_IN), $lines * $lineH + 2 * $pad);
            $total += $h;
            $rows .= '<a:tr h="' . $h . '">' . $cell($label, true) . $cell($value, false, 3) . $cell('', false, 1, true) . $cell('', false, 1, true) . '</a:tr>';
        }
        return [$rows, $total];
    };

    $rows = '';
    $total = 0;
    foreach ([1800, 1600, 1400, 1300, 1200, 1100, 1000, 900, 800] as $size) {
        [$rows, $total] = $build($size);
        if ($total <= $cy) {
            break;
        }
    }
    return '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $id . '" name="Complaint details"/><p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
        . '<p:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . min($total, $cy) . '"/></p:xfrm>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table"><a:tbl><a:tblPr/>'
        . '<a:tblGrid><a:gridCol w="' . $labelW . '"/><a:gridCol w="' . $valueW . '"/><a:gridCol w="' . $labelW . '"/><a:gridCol w="' . ($cx - 2 * $labelW - $valueW) . '"/></a:tblGrid>'
        . $rows . '</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';
}

/* ------------------------------------------------------------------ *
 | Public API                                                          |
 * ------------------------------------------------------------------ */

/** A PNG copy of the company logo (alpha kept), ≤ 600 px, or null. Caller unlinks. */
function cmp_ppt_logo_png(?string $path): ?array
{
    if ($path === null || !is_file($path) || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $bytes = @file_get_contents($path);
    $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 600 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $tmp = cmp_tmp_file('.png');
    $ok = imagepng($dst, $tmp);
    imagedestroy($dst);
    return $ok ? [$tmp, $nw, $nh] : null;
}

/**
 * Build a presentation for one complaint.
 *
 * @param array $template  ppt_templates row
 * @param array $c         complaint (cmp_load row)
 * @param array $before    complaint_evidence rows, saved order
 * @param array $after     complaint_evidence rows, saved order
 * @return array{path:string, slides:int, pairs:int}  caller streams then unlinks path
 */
function cmp_ppt_generate(array $template, array $c, array $before, array $after): array
{
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    $source = CMP_TEMPLATE_DIR . '/' . $template['file_name'];
    if (!preg_match('/^[A-Za-z0-9._-]+$/', (string)$template['file_name']) || !is_file($source)) {
        throw new RuntimeException('The template file is missing.');
    }
    cmp_tmp_cleanup();

    $out = cmp_tmp_file('.pptx');
    if (!@copy($source, $out)) {         // the ORIGINAL is only ever read
        throw new RuntimeException('Could not prepare a working copy of the template.');
    }
    $cleanup = [$out];
    $id = (int)$c['id'];

    try {
        $b = new CmpPptBuilder($out);

        /* ---- choose the design bases before removing the sample slides ---- */
        $fallbackLayout = $b->bestLayout();
        $detailsBase = $b->baseFromSlide(0);
        $pairBase = $b->baseFromSlide(1);
        $detailsLayout = $detailsBase ? $b->analyseLayout($detailsBase['layout']) : $fallbackLayout;
        $pairLayout = $pairBase ? $b->analyseLayout($pairBase['layout']) : $detailsLayout;
        $b->removeAllSlides();

        /* ---- slide 1: complaint details ---- */
        $rels = [];
        [$dom, $tree, $nextId] = cmp_ppt_prepare_slide($b, $detailsBase, $detailsLayout, 'Complaint Details — ' . $c['complaint_number'], $rels);
        $region = cmp_ppt_region($b, $dom, $detailsLayout['part']);

        $latest = Database::fetch('SELECT r.remarks, r.resolved_at FROM complaint_resolution r WHERE r.complaint_id = :c ORDER BY r.id DESC LIMIT 1', ['c' => $id]);
        $desc = (string)$c['description'];
        $pairs = [
            ['Complaint No.', (string)$c['complaint_number']], ['Complaint Date', cmp_fmt_date((string)$c['complaint_date']) . ($c['complaint_time'] ? ', ' . substr((string)$c['complaint_time'], 0, 5) : '')],
            ['Complainant', (string)$c['complainant_name']], ['Source', (string)$c['source_name']],
            ['Type', (string)$c['type_name']], ['Priority', (string)$c['priority_name']],
            ['Region', (string)$c['region_name']], ['District', (string)$c['district_name']],
            ['Tehsil', (string)$c['tehsil_name']], ['UC', (string)$c['uc_name']],
            ['Beat', (string)($c['beat_name'] ?: '—')], ['Status', cmp_status_label((string)$c['status'])],
        ];
        $wide = [['Location / Address', (string)($c['location_address'] ?: '—')], ['Description', mb_strlen($desc) > 700 ? mb_substr($desc, 0, 697) . '…' : $desc]];
        if ($latest !== null && $c['status'] === CMP_ST_RESOLVED) {
            $rem = (string)$latest['remarks'];
            $wide[] = ['Resolution', cmp_fmt_date((string)$latest['resolved_at']) . ' — ' . (mb_strlen($rem) > 400 ? mb_substr($rem, 0, 397) . '…' : $rem)];
        }
        $logo = cmp_ppt_logo_png(cmp_logo_path());
        $logoRid = null;
        if ($logo !== null) {
            $cleanup[] = $logo[0];
            $media = $b->addMedia($logo[0], 'png');
            $logoRid = 'rIdCmpLogo';
            $rels[$logoRid] = ['type' => CMP_REL_IMAGE, 'target' => '', 'mode' => 'Internal', 'path' => $media];
        }
        $tableY = $region[1];
        $tableH = $region[3];
        if ($logo !== null) {
            $lh = (int)(0.6 * CMP_EMU_IN);
            [$lx, $ly, $lcx, $lcy] = cmp_ppt_fit($b->width - (int)(1.6 * CMP_EMU_IN) - (int)(0.3 * CMP_EMU_IN), (int)(0.18 * CMP_EMU_IN), (int)(1.6 * CMP_EMU_IN), $lh, $logo[1], $logo[2]);
            cmp_ppt_append($dom, $tree, cmp_ppt_picture($nextId++, 'Company logo', (string)company()['name'], $logoRid, $lx, $ly, $lcx, $lcy));
        }
        cmp_ppt_append($dom, $tree, cmp_ppt_details_table($nextId++, $region[0], $tableY, $region[2], $tableH, $pairs, $wide));
        $b->addSlide($dom->saveXML(), $rels);

        /* ---- slides 2…: one Before + one After per slide ---- */
        $count = max(count($before), count($after));
        $imgInfo = static function (?array $row) use ($id): ?array {
            if ($row === null) {
                return null;
            }
            $path = cmp_file_path($id, (string)$row['preview_name']) ?? cmp_file_path($id, (string)$row['file_name']);
            if ($path === null) {
                return null;
            }
            $size = @getimagesize($path);
            if ($size === false) {
                return null;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return ['path' => $path, 'w' => (int)$size[0], 'h' => (int)$size[1], 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
        };

        for ($i = 0; $i < $count; $i++) {
            $rels = [];
            [$dom, $tree, $nextId] = cmp_ppt_prepare_slide($b, $pairBase, $pairLayout, 'Before & After — ' . ($i + 1) . ' of ' . $count . ' · ' . $c['complaint_number'], $rels);
            $region = cmp_ppt_region($b, $dom, $pairLayout['part']);
            [$rx, $ry, $rw, $rh] = $region;

            $gap = (int)(0.3 * CMP_EMU_IN);
            $colW = intdiv($rw - $gap, 2);
            $labelH = (int)(0.38 * CMP_EMU_IN);
            $capH = (int)(0.32 * CMP_EMU_IN);
            $picH = max((int)(1.2 * CMP_EMU_IN), $rh - $labelH - $capH);

            $sides = [['Before', $before[$i] ?? null, count($before), $rx], ['After', $after[$i] ?? null, count($after), $rx + $colW + $gap]];
            foreach ($sides as $s => [$label, $row, $total, $colX]) {
                cmp_ppt_append($dom, $tree, cmp_ppt_textbox($nextId++, $label . ' label', $colX, $ry, $colW, $labelH, strtoupper($label), 1400, true));
                $info = $imgInfo($row);
                if ($info !== null) {
                    $media = $b->addMedia($info['path'], $info['ext']);
                    $rid = 'rIdCmpImg' . ($s + 1);
                    $rels[$rid] = ['type' => CMP_REL_IMAGE, 'target' => '', 'mode' => 'Internal', 'path' => $media];
                    [$px, $py, $pcx, $pcy] = cmp_ppt_fit($colX, $ry + $labelH, $colW, $picH, $info['w'], $info['h']);
                    cmp_ppt_append($dom, $tree, cmp_ppt_picture($nextId++, $label . ' ' . ($i + 1), $label . ' picture ' . ($i + 1) . ' of ' . $total, $rid, $px, $py, $pcx, $pcy));
                    cmp_ppt_append($dom, $tree, cmp_ppt_textbox($nextId++, $label . ' caption', $colX, $ry + $labelH + $picH, $colW, $capH, $label . ' ' . ($i + 1) . ' of ' . $total, 1000, false));
                } else {
                    cmp_ppt_append($dom, $tree, cmp_ppt_textbox($nextId++, $label . ' missing', $colX, $ry + $labelH, $colW, $picH, 'No ' . $label . ' picture for this position', 1200, false, 'ctr', true, true));
                }
            }
            $b->addSlide($dom->saveXML(), $rels);
        }

        $b->finish('Complaint ' . $c['complaint_number']);
        return ['path' => $out, 'slides' => 1 + $count, 'pairs' => $count];
    } catch (Throwable $e) {
        foreach ($cleanup as $f) {
            @unlink($f);
        }
        throw $e;
    } finally {
        if (isset($logo) && $logo !== null) {
            @unlink($logo[0]);
        }
    }
}

/** Company logo file path (shared with the PDF code's logic, without loading TCPDF). */
function cmp_logo_path(): ?string
{
    $logo = company()['logo'];
    if ($logo === '' || str_contains($logo, '/') || str_contains($logo, '\\') || str_contains($logo, '..')) {
        return null;
    }
    $path = COMPANY_LOGO_PATH . '/' . $logo;
    return is_file($path) ? $path : null;
}
