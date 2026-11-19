<?php
declare(strict_types=1);
/*
|--------------------------------------------------------------------------
| utm_common.php  -  index.php आणि admin.php दोन्हीसाठी common functions
|--------------------------------------------------------------------------
| 1) ensureSchema()      : नवीन columns / settings table आपोआप तयार करतो
| 2) loadSettings()      : Admin ने ठरवलेले गुण / चुकांचे नियम
| 3) docxToContent()     : Word (.docx) मधून Text + Format (HTML) काढतो
| 4) evaluateTyping()    : टाइप केलेल्या शब्दांची Original शी योग्य तुलना
*/

const DOCX_W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

/* ---------------------------------------------------------------
   0) Database config (config.php) आणि connection
---------------------------------------------------------------- */
function utmConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        $loaded = is_file($file) ? require $file : [];
        $cfg = is_array($loaded) ? $loaded : [];
        $cfg += ['db_host' => 'localhost', 'db_user' => 'root', 'db_pass' => '', 'db_name' => 'student'];
    }
    return $cfg;
}

function utmConnect(): mysqli
{
    $c = utmConfig();
    return new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name']);
}

/* परीक्षेचा प्रकार -> Settings मधील गट (passage / letter / statement / email) */
function examKind(string $mode): string
{
    $map = ['business_letter' => 'letter', 'statement' => 'statement', 'email' => 'email'];
    return $map[$mode] ?? 'passage';
}

/* Admin ने add केलेले Statements / E-Mails */
function dbDocuments(mysqli $db, string $type, string $lang): array
{
    $rows = [];
    $s = $db->prepare('SELECT id, title, pdf_path FROM exam_documents WHERE is_active = 1 AND doc_type = ? AND language = ? ORDER BY id ASC');
    if ($s) {
        $s->bind_param('ss', $type, $lang);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc()) $rows[] = $x;
        $s->close();
    }
    return $rows;
}

/* ---------------------------------------------------------------
   1) Database upgrade (जुन्या data ला धक्का न लावता)
---------------------------------------------------------------- */
function ensureSchema(mysqli $db): void
{
    $columns = [
        ['passages', 'content_html', 'ALTER TABLE passages ADD COLUMN content_html MEDIUMTEXT NULL'],
        ['business_letters', 'content_html', 'ALTER TABLE business_letters ADD COLUMN content_html MEDIUMTEXT NULL'],
        ['passages', 'use_for', "ALTER TABLE passages ADD COLUMN use_for VARCHAR(10) NOT NULL DEFAULT 'both'"],
    ];
    foreach ($columns as $c) {
        $r = $db->query("SHOW COLUMNS FROM `" . $c[0] . "` LIKE '" . $c[1] . "'");
        if ($r) {
            if ($r->num_rows === 0) $db->query($c[2]);
            $r->free();
        }
    }
    $db->query("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB");
    // Statements आणि E-Mails (doc_type = 'statement' / 'email')
    $db->query("CREATE TABLE IF NOT EXISTS exam_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_type VARCHAR(20) NOT NULL,
        title VARCHAR(150) NOT NULL,
        language VARCHAR(20) NOT NULL,
        content MEDIUMTEXT NULL,
        content_html MEDIUMTEXT NULL,
        pdf_path VARCHAR(255) NULL,
        meta_json MEDIUMTEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_type (doc_type)
    ) ENGINE=InnoDB");
    $r = $db->query("SHOW COLUMNS FROM exam_documents LIKE 'meta_json'");
    if ($r) {
        if ($r->num_rows === 0) $db->query('ALTER TABLE exam_documents ADD COLUMN meta_json MEDIUMTEXT NULL');
        $r->free();
    }
    // Admin login चुकीचे प्रयत्न (brute-force संरक्षण)
    $db->query("CREATE TABLE IF NOT EXISTS admin_attempts (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        tries INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL
    ) ENGINE=InnoDB");
}

/* ---------------------------------------------------------------
   2) Exam settings (Admin -> Exam Settings)
---------------------------------------------------------------- */
function defaultSettings(): array
{
    $base = [
        'total_marks' => '40',
        'pass_marks' => '16',
        'max_mistakes' => '24',     // 0 = मर्यादा नाही
        'full_penalty' => '1',      // Full mistake साठी वजा होणारे गुण
        'half_penalty' => '0.5',    // Half mistake साठी वजा होणारे गुण
        'untyped_penalty' => '0.5', // टाइप न केलेल्या प्रत्येक शब्दाचे गुण
    ];
    $out = [];
    foreach (['passage', 'letter', 'statement', 'email'] as $kind) {
        foreach ($base as $k => $v) $out[$kind . '_' . $k] = $v;
        if ($kind !== 'passage') $out[$kind . '_minutes'] = '30'; // परीक्षेचा वेळ (मिनिटे)
    }
    return $out;
}

function loadSettings(mysqli $db): array
{
    $cfg = defaultSettings();
    $r = $db->query('SELECT setting_key, setting_value FROM settings');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if (isset($cfg[$row['setting_key']]) && is_numeric($row['setting_value'])) {
                $cfg[$row['setting_key']] = (string)$row['setting_value'];
            }
        }
        $r->free();
    }
    return $cfg;
}

/* ---------------------------------------------------------------
   4) Typing evaluation (word alignment)
   - एक शब्द सुटला तर फक्त 1 चूक (पुढचे सर्व शब्द चुकीचे ठरत नाहीत)
   - Full mistake : चुकीचा शब्द / जास्तीचा शब्द / मधला सुटलेला शब्द
   - Half mistake : फक्त Capital-small किंवा punctuation चा फरक
   - Untyped      : शेवटचे टाइप न केलेले शब्द
---------------------------------------------------------------- */
/** Text मधील प्रत्येक शब्दाचा paragraph क्रमांक (प्रत्येक रिकामी नसलेली ओळ = एक paragraph) */
function wordParagraphs(string $text): array
{
    $para = [];
    $p = 0;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
        $words = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || !$words) continue;
        foreach ($words as $w) $para[] = $p;
        $p++;
    }
    return $para;
}

function normalizeWord(string $w): string
{
    $w = mb_strtolower($w, 'UTF-8');
    $n = preg_replace('/[\p{P}\p{S}]+/u', '', $w);
    return $n === null ? $w : $n;
}

function evaluateTyping(array $orig, array $typed, ?array $origPara = null, ?array $typedPara = null): array
{
    $n = count($orig);
    $m = count($typed);
    $ops = [];

    if ($n * $m > 4000000) {
        // खूप मोठा मजकूर: साधी position-wise तुलना
        $max = max($n, $m);
        for ($i = 0; $i < $max; $i++) {
            $o = $orig[$i] ?? null;
            $t = $typed[$i] ?? null;
            if ($o === null) $ops[] = ['extra', '', (string)$t];
            elseif ($t === null) $ops[] = ['omit', (string)$o, ''];
            elseif ($o === $t) $ops[] = ['ok', $o, $t];
            elseif (normalizeWord($o) !== '' && normalizeWord($o) === normalizeWord($t)) $ops[] = ['half', $o, $t];
            else $ops[] = ['wrong', $o, $t];
        }
    } else {
        $normO = array_map('normalizeWord', $orig);
        $normT = array_map('normalizeWord', $typed);
        // खर्च दुप्पट ठेवला आहे (half mistake = 1, full = 2) म्हणजे integer गणित
        $cost = [];
        for ($i = 0; $i <= $n; $i++) $cost[$i] = array_fill(0, $m + 1, 0);
        for ($i = 1; $i <= $n; $i++) $cost[$i][0] = $i * 2;
        for ($j = 1; $j <= $m; $j++) $cost[0][$j] = $j * 2;

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $del = $cost[$i - 1][$j] + 2;
                $ins = $cost[$i][$j - 1] + 2;
                $best = $del < $ins ? $del : $ins;
                // Paragraph नियम: शब्द फक्त त्याच paragraph मधील शब्दाशी जुळू शकतो
                if ($origPara === null || $typedPara === null || $origPara[$i - 1] === $typedPara[$j - 1]) {
                    if ($orig[$i - 1] === $typed[$j - 1]) $sub = 0;
                    elseif ($normO[$i - 1] !== '' && $normO[$i - 1] === $normT[$j - 1]) $sub = 1;
                    else $sub = 2;
                    $diag = $cost[$i - 1][$j - 1] + $sub;
                    if ($diag < $best) $best = $diag;
                }
                $cost[$i][$j] = $best;
            }
        }

        $i = $n;
        $j = $m;
        $rev = [];
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && ($origPara === null || $typedPara === null || $origPara[$i - 1] === $typedPara[$j - 1])) {
                if ($orig[$i - 1] === $typed[$j - 1]) $sub = 0;
                elseif ($normO[$i - 1] !== '' && $normO[$i - 1] === $normT[$j - 1]) $sub = 1;
                else $sub = 2;
                if ($cost[$i][$j] === $cost[$i - 1][$j - 1] + $sub) {
                    $type = $sub === 0 ? 'ok' : ($sub === 1 ? 'half' : 'wrong');
                    $rev[] = [$type, $orig[$i - 1], $typed[$j - 1]];
                    $i--;
                    $j--;
                    continue;
                }
            }
            if ($i > 0 && $cost[$i][$j] === $cost[$i - 1][$j] + 2) {
                $rev[] = ['omit', $orig[$i - 1], ''];
                $i--;
                continue;
            }
            $rev[] = ['extra', '', $typed[$j - 1]];
            $j--;
        }
        $ops = array_reverse($rev);
    }

    // शेवटी सुटलेले शब्द = "untyped"
    $last = count($ops) - 1;
    while ($last >= 0 && $ops[$last][0] === 'omit') {
        $ops[$last][0] = 'untyped';
        $last--;
    }

    $correct = 0;
    $half = 0;
    $full = 0;
    $untyped = 0;
    foreach ($ops as $op) {
        if ($op[0] === 'ok') $correct++;
        elseif ($op[0] === 'half') $half++;
        elseif ($op[0] === 'untyped') $untyped++;
        else $full++;
    }
    return ['ops' => $ops, 'correct' => $correct, 'half' => $half, 'full' => $full, 'untyped' => $untyped];
}

/* ---------------------------------------------------------------
   3) Word (.docx) -> Text + HTML (alignment, bold, underline, tab, table)
---------------------------------------------------------------- */
function docxAttr(DOMElement $el, string $name): string
{
    return $el->getAttributeNS(DOCX_W_NS, $name);
}

function docxFlag(DOMXPath $xp, DOMElement $r, string $tag): bool
{
    $list = $xp->query('w:rPr/w:' . $tag, $r);
    $n = $list ? $list->item(0) : null;
    if (!($n instanceof DOMElement)) return false;
    $v = strtolower(docxAttr($n, 'val'));
    return !in_array($v, ['0', 'false', 'off', 'none'], true);
}

function docxStyleAlign(?string $stylesXml): array
{
    $map = ['_default' => 'left'];
    if (!$stylesXml) return $map;
    $d = new DOMDocument();
    if (!@$d->loadXML($stylesXml, LIBXML_NONET)) return $map;
    $xp = new DOMXPath($d);
    $xp->registerNamespace('w', DOCX_W_NS);
    $styles = $xp->query('//w:style[@w:type="paragraph"]');
    if (!$styles) return $map;
    foreach ($styles as $st) {
        if (!($st instanceof DOMElement)) continue;
        $jcList = $xp->query('w:pPr/w:jc', $st);
        $jc = $jcList ? $jcList->item(0) : null;
        if ($jc instanceof DOMElement) {
            $val = docxAttr($jc, 'val');
            $map[docxAttr($st, 'styleId')] = $val;
            if (docxAttr($st, 'default') === '1') $map['_default'] = $val;
        }
    }
    return $map;
}

/** एक paragraph -> [html, text] */
function docxParagraph(DOMXPath $xp, DOMElement $p, array $styleJc): array
{
    $jc = $styleJc['_default'] ?? 'left';
    $psList = $xp->query('w:pPr/w:pStyle', $p);
    $ps = $psList ? $psList->item(0) : null;
    if ($ps instanceof DOMElement) {
        $sid = docxAttr($ps, 'val');
        if (isset($styleJc[$sid])) $jc = $styleJc[$sid];
    }
    $jcList = $xp->query('w:pPr/w:jc', $p);
    $jcEl = $jcList ? $jcList->item(0) : null;
    if ($jcEl instanceof DOMElement) $jc = docxAttr($jcEl, 'val');

    $alignMap = ['center' => 'center', 'right' => 'right', 'end' => 'right', 'both' => 'justify', 'distribute' => 'justify', 'left' => 'left', 'start' => 'left'];
    $css = ['text-align:' . ($alignMap[$jc] ?? 'left'), 'white-space:pre-wrap'];

    $indList = $xp->query('w:pPr/w:ind', $p);
    $ind = $indList ? $indList->item(0) : null;
    if ($ind instanceof DOMElement) {
        $props = ['left' => 'margin-left', 'start' => 'margin-left', 'right' => 'margin-right', 'end' => 'margin-right', 'firstLine' => 'text-indent'];
        foreach ($props as $attr => $prop) {
            $v = docxAttr($ind, $attr);
            if ($v !== '' && is_numeric($v)) $css[] = $prop . ':' . round(((float)$v) / 20, 1) . 'pt';
        }
        $hang = docxAttr($ind, 'hanging');
        if ($hang !== '' && is_numeric($hang)) $css[] = 'text-indent:-' . round(((float)$hang) / 20, 1) . 'pt';
    }
    $spList = $xp->query('w:pPr/w:spacing', $p);
    $sp = $spList ? $spList->item(0) : null;
    if ($sp instanceof DOMElement) {
        $b = docxAttr($sp, 'before');
        $a = docxAttr($sp, 'after');
        if ($b !== '' && is_numeric($b)) $css[] = 'margin-top:' . round(((float)$b) / 20, 1) . 'pt';
        if ($a !== '' && is_numeric($a)) $css[] = 'margin-bottom:' . round(((float)$a) / 20, 1) . 'pt';
    }
    $bdr = $xp->query('w:pPr/w:pBdr/w:bottom', $p);
    if ($bdr && $bdr->length > 0) $css[] = 'border-bottom:1px solid #000';

    $html = '';
    $text = '';
    $runs = $xp->query('.//w:r', $p);
    if ($runs) {
        foreach ($runs as $r) {
            if (!($r instanceof DOMElement)) continue;
            $rt = '';
            foreach ($r->childNodes as $c) {
                if (!($c instanceof DOMElement)) continue;
                switch ($c->localName) {
                    case 't':
                        $rt .= $c->textContent;
                        break;
                    case 'tab':
                        $rt .= "\t";
                        break;
                    case 'br':
                    case 'cr':
                        if (docxAttr($c, 'type') !== 'page') $rt .= "\n";
                        break;
                    case 'noBreakHyphen':
                        $rt .= '-';
                        break;
                }
            }
            if ($rt === '') continue;
            if (docxFlag($xp, $r, 'caps')) $rt = mb_strtoupper($rt, 'UTF-8');
            $text .= $rt;

            $rs = [];
            if (docxFlag($xp, $r, 'b')) $rs[] = 'font-weight:bold';
            if (docxFlag($xp, $r, 'i')) $rs[] = 'font-style:italic';
            if (docxFlag($xp, $r, 'u')) $rs[] = 'text-decoration:underline';
            $szList = $xp->query('w:rPr/w:sz', $r);
            $sz = $szList ? $szList->item(0) : null;
            if ($sz instanceof DOMElement) {
                $half = docxAttr($sz, 'val');
                if ($half !== '' && is_numeric($half) && (float)$half > 0) $rs[] = 'font-size:' . round(((float)$half) / 2, 1) . 'pt';
            }
            $h = htmlspecialchars($rt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $h = str_replace(["\t", "\n"], ['<span class="dtab"></span>', '<br>'], $h);
            $html .= $rs ? '<span style="' . implode(';', $rs) . '">' . $h . '</span>' : $h;
        }
    }
    $text = rtrim($text);
    if ($html === '') $html = '&nbsp;';
    return ['<p style="' . implode(';', $css) . '">' . $html . '</p>', $text];
}

/** एक table -> [html, text] */
function docxTable(DOMXPath $xp, DOMElement $tbl, array $styleJc): array
{
    $bl = $xp->query('w:tblPr/w:tblBorders', $tbl);
    $bordered = $bl && $bl->length > 0;
    $tsList = $xp->query('w:tblPr/w:tblStyle', $tbl);
    $ts = $tsList ? $tsList->item(0) : null;
    if ($ts instanceof DOMElement && stripos(docxAttr($ts, 'val'), 'grid') !== false) $bordered = true;

    $html = '<table class="dtable' . ($bordered ? ' bordered' : '') . '">';
    $textRows = [];
    $rows = $xp->query('w:tr', $tbl);
    if ($rows) {
        foreach ($rows as $tr) {
            if (!($tr instanceof DOMElement)) continue;
            $html .= '<tr>';
            $cells = [];
            $tcs = $xp->query('w:tc', $tr);
            if ($tcs) {
                foreach ($tcs as $tc) {
                    if (!($tc instanceof DOMElement)) continue;
                    $cellHtml = '';
                    $cellText = [];
                    $ps = $xp->query('w:p', $tc);
                    if ($ps) {
                        foreach ($ps as $p) {
                            if (!($p instanceof DOMElement)) continue;
                            [$h, $t] = docxParagraph($xp, $p, $styleJc);
                            $cellHtml .= $h;
                            if ($t !== '') $cellText[] = $t;
                        }
                    }
                    $html .= '<td>' . $cellHtml . '</td>';
                    $cells[] = implode(' ', $cellText);
                }
            }
            $html .= '</tr>';
            $textRows[] = implode("\t", $cells);
        }
    }
    return [$html . '</table>', implode("\n", $textRows)];
}

/** .docx फाईलमधून ['text' => ..., 'html' => ...] */
function docxToContent(string $path): array
{
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
        throw new RuntimeException('PHP ZipArchive / DOM extension सुरू नाही (XAMPP च्या php.ini मध्ये extension=zip सुरू करा).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('.docx फाईल उघडता आली नाही.');
    $xml = $zip->getFromName('word/document.xml');
    $stylesXml = $zip->getFromName('word/styles.xml');
    $zip->close();
    if (!$xml) throw new RuntimeException('Word फाईलमध्ये मजकूर सापडला नाही.');

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('Word फाईल वाचता आली नाही.');
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', DOCX_W_NS);
    $styleJc = docxStyleAlign($stylesXml ?: null);

    $bodyList = $xp->query('//w:body');
    $body = $bodyList ? $bodyList->item(0) : null;
    if (!($body instanceof DOMElement)) throw new RuntimeException('Word फाईलमध्ये मजकूर सापडला नाही.');

    $htmlParts = [];
    $lines = [];
    foreach ($body->childNodes as $node) {
        if (!($node instanceof DOMElement)) continue;
        if ($node->localName === 'p') {
            [$h, $t] = docxParagraph($xp, $node, $styleJc);
            $htmlParts[] = $h;
            $lines[] = $t;
        } elseif ($node->localName === 'tbl') {
            [$h, $t] = docxTable($xp, $node, $styleJc);
            $htmlParts[] = $h;
            $lines[] = $t;
        }
    }
    return ['text' => trim(implode("\n", $lines)), 'html' => implode("\n", $htmlParts)];
}

/* ===============================================================
   5) STATEMENT (Excel सारखा grid)  -  .xlsx / Excel मधून paste
   Grid format: ['rows'=>N, 'cols'=>M, 'cells'=>[r][c] => ['v','b','i','u','a','bd','cs','rs','h']]
=============================================================== */
const XL_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlColName(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

function xlColIndex(string $letters): int
{
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return $n - 1;
}

function xlFormatNumber(string $v, ?string $code, int $id): string
{
    if (!is_numeric($v)) return $v;
    $num = (float)$v;
    $builtin = [1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 9 => '0%', 10 => '0.00%'];
    if ($code === null && isset($builtin[$id])) $code = $builtin[$id];
    $plain = rtrim(rtrim(number_format($num, 10, '.', ''), '0'), '.');
    if ($plain === '' || $plain === '-') $plain = '0';
    if ($code === null || $id === 0 || strcasecmp($code, 'General') === 0) return $plain;
    $parts = explode(';', $code);
    $pos = (string)preg_replace('/\[[^\]]*\]|"[^"]*"|_.|\\\\./', '', $parts[0]);
    if (!preg_match('/[0#]/', $pos)) return $plain;
    $percent = strpos($pos, '%') !== false;
    $comma = strpos($pos, ',') !== false;
    $dec = 0;
    if (preg_match('/\.([0#?]+)/', $pos, $m)) $dec = strlen($m[1]);
    if ($percent) $num *= 100;
    $out = number_format($num, $dec, '.', $comma ? ',' : '');
    return $percent ? $out . '%' : $out;
}

/** .xlsx फाईल -> grid */
function xlsxToGrid(string $path): array
{
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
        throw new RuntimeException('PHP ZipArchive / DOM extension सुरू नाही (XAMPP च्या php.ini मध्ये extension=zip सुरू करा).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('.xlsx फाईल उघडता आली नाही.');

    // Shared strings
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $d = new DOMDocument();
        if (@$d->loadXML($ssXml, LIBXML_NONET)) {
            $xp = new DOMXPath($d);
            $xp->registerNamespace('m', XL_NS);
            $sis = $xp->query('//m:si');
            if ($sis) {
                foreach ($sis as $si) {
                    $t = '';
                    $tn = $xp->query('m:t | m:r/m:t', $si);
                    if ($tn) foreach ($tn as $n) $t .= $n->textContent;
                    $shared[] = $t;
                }
            }
        }
    }

    // Styles
    $numFmts = [];
    $fonts = [];
    $borders = [];
    $xfs = [];
    $stXml = $zip->getFromName('xl/styles.xml');
    if ($stXml) {
        $d = new DOMDocument();
        if (@$d->loadXML($stXml, LIBXML_NONET)) {
            $xp = new DOMXPath($d);
            $xp->registerNamespace('m', XL_NS);
            $q = $xp->query('//m:numFmts/m:numFmt');
            if ($q) foreach ($q as $n) if ($n instanceof DOMElement) $numFmts[(int)$n->getAttribute('numFmtId')] = $n->getAttribute('formatCode');
            $q = $xp->query('//m:fonts/m:font');
            if ($q) {
                foreach ($q as $f) {
                    $flag = function (string $tag) use ($xp, $f): int {
                        $l = $xp->query('m:' . $tag, $f);
                        $n = $l ? $l->item(0) : null;
                        if (!($n instanceof DOMElement)) return 0;
                        $v = strtolower($n->getAttribute('val'));
                        return in_array($v, ['0', 'false', 'none'], true) ? 0 : 1;
                    };
                    $fonts[] = ['b' => $flag('b'), 'i' => $flag('i'), 'u' => $flag('u')];
                }
            }
            $q = $xp->query('//m:borders/m:border');
            if ($q) {
                foreach ($q as $b) {
                    $has = 0;
                    foreach (['left', 'right', 'top', 'bottom'] as $side) {
                        $l = $xp->query('m:' . $side, $b);
                        $n = $l ? $l->item(0) : null;
                        if ($n instanceof DOMElement && $n->getAttribute('style') !== '') $has = 1;
                    }
                    $borders[] = $has;
                }
            }
            $q = $xp->query('//m:cellXfs/m:xf');
            if ($q) {
                foreach ($q as $x) {
                    if (!($x instanceof DOMElement)) continue;
                    $al = $xp->query('m:alignment', $x);
                    $an = $al ? $al->item(0) : null;
                    $xfs[] = [
                        'fmt' => (int)$x->getAttribute('numFmtId'),
                        'font' => (int)$x->getAttribute('fontId'),
                        'border' => (int)$x->getAttribute('borderId'),
                        'a' => ($an instanceof DOMElement) ? $an->getAttribute('horizontal') : '',
                    ];
                }
            }
        }
    }

    // पहिली sheet शोधा
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();
    if (!$sheetXml) throw new RuntimeException('Excel फाईलमध्ये Sheet सापडली नाही.');

    $d = new DOMDocument();
    if (!@$d->loadXML($sheetXml, LIBXML_NONET)) throw new RuntimeException('Excel फाईल वाचता आली नाही.');
    $xp = new DOMXPath($d);
    $xp->registerNamespace('m', XL_NS);

    $raw = [];
    $minR = PHP_INT_MAX; $maxR = -1; $minC = PHP_INT_MAX; $maxC = -1;
    $cs = $xp->query('//m:sheetData/m:row/m:c');
    if ($cs) {
        foreach ($cs as $c) {
            if (!($c instanceof DOMElement)) continue;
            if (!preg_match('/^([A-Z]+)(\d+)$/', $c->getAttribute('r'), $mm)) continue;
            $col = xlColIndex($mm[1]);
            $row = (int)$mm[2] - 1;
            $t = $c->getAttribute('t');
            $s = (int)$c->getAttribute('s');
            $xf = $xfs[$s] ?? ['fmt' => 0, 'font' => 0, 'border' => 0, 'a' => ''];
            $vl = $xp->query('m:v', $c);
            $vn = $vl ? $vl->item(0) : null;
            $v = $vn ? $vn->textContent : '';
            if ($t === 's') {
                $v = $shared[(int)$v] ?? '';
            } elseif ($t === 'inlineStr') {
                $v = '';
                $il = $xp->query('m:is//m:t', $c);
                if ($il) foreach ($il as $n) $v .= $n->textContent;
            } elseif ($t === 'b') {
                $v = $v === '1' ? 'TRUE' : 'FALSE';
            } elseif ($t === '' || $t === 'n') {
                $v = xlFormatNumber($v, $numFmts[$xf['fmt']] ?? null, $xf['fmt']);
            }
            $font = $fonts[$xf['font']] ?? ['b' => 0, 'i' => 0, 'u' => 0];
            $bd = $borders[$xf['border']] ?? 0;
            $v = trim($v);
            if ($v === '' && !$bd) continue;
            $raw[$row][$col] = [
                'v' => $v, 'b' => $font['b'], 'i' => $font['i'], 'u' => $font['u'],
                'a' => $xf['a'], 'bd' => $bd,
            ];
            if ($row < $minR) $minR = $row;
            if ($row > $maxR) $maxR = $row;
            if ($col < $minC) $minC = $col;
            if ($col > $maxC) $maxC = $col;
        }
    }
    if ($maxR < 0) throw new RuntimeException('Excel फाईलमध्ये मजकूर सापडला नाही.');

    $rows = $maxR - $minR + 1;
    $cols = $maxC - $minC + 1;
    $cells = [];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $cells[$r][$c] = $raw[$r + $minR][$c + $minC] ?? ['v' => '', 'b' => 0, 'i' => 0, 'u' => 0, 'a' => '', 'bd' => 0];
        }
    }

    // Merged cells
    $ml = $xp->query('//m:mergeCells/m:mergeCell');
    if ($ml) {
        foreach ($ml as $mc) {
            if (!($mc instanceof DOMElement)) continue;
            if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $mc->getAttribute('ref'), $mm)) continue;
            $r1 = (int)$mm[2] - 1 - $minR; $c1 = xlColIndex($mm[1]) - $minC;
            $r2 = (int)$mm[4] - 1 - $minR; $c2 = xlColIndex($mm[3]) - $minC;
            if ($r1 < 0 || $c1 < 0 || $r1 >= $rows || $c1 >= $cols) continue;
            $r2 = min($r2, $rows - 1);
            $c2 = min($c2, $cols - 1);
            $cells[$r1][$c1]['cs'] = $c2 - $c1 + 1;
            $cells[$r1][$c1]['rs'] = $r2 - $r1 + 1;
            for ($r = $r1; $r <= $r2; $r++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    if ($r === $r1 && $c === $c1) continue;
                    $cells[$r][$c]['h'] = 1;
                }
            }
        }
    }
    return ['rows' => $rows, 'cols' => $cols, 'cells' => $cells];
}

/** Excel मधून copy करून paste केलेला Text (Tab + नवीन ओळ) -> grid */
function gridFromTsv(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", rtrim($text, "\n"));
    $cols = 1;
    $data = [];
    foreach ($lines as $line) {
        $cellsRow = explode("\t", $line);
        $data[] = $cellsRow;
        if (count($cellsRow) > $cols) $cols = count($cellsRow);
    }
    $rows = count($data);
    $cells = [];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $cells[$r][$c] = ['v' => trim((string)($data[$r][$c] ?? '')), 'b' => 0, 'i' => 0, 'u' => 0, 'a' => '', 'bd' => 0];
        }
    }
    // पहिली ओळ फक्त एका cell ची असेल तर ती Title (Bold, Underline, Center)
    if ($rows > 0 && $cols > 1) {
        $nonEmpty = 0;
        foreach ($cells[0] as $cell) if ($cell['v'] !== '') $nonEmpty++;
        if ($nonEmpty === 1) {
            $first = null;
            foreach ($cells[0] as $c => $cell) if ($cell['v'] !== '') { $first = $c; break; }
            $title = $cells[0][$first]['v'];
            for ($c = 0; $c < $cols; $c++) $cells[0][$c] = ['v' => '', 'b' => 0, 'i' => 0, 'u' => 0, 'a' => '', 'bd' => 0, 'h' => 1];
            $cells[0][0] = ['v' => $title, 'b' => 1, 'i' => 0, 'u' => 1, 'a' => 'center', 'bd' => 0, 'cs' => $cols];
        }
    }
    return ['rows' => $rows, 'cols' => $cols, 'cells' => $cells];
}

/** grid -> तुलना करण्याचा Text (प्रत्येक ओळीत cells Tab ने जोडलेले) */
function gridToText(array $grid): string
{
    $lines = [];
    foreach (($grid['cells'] ?? []) as $row) {
        $vals = [];
        foreach ($row as $cell) {
            if (!empty($cell['h'])) continue;
            $v = trim((string)($cell['v'] ?? ''));
            if ($v !== '') $vals[] = $v;
        }
        if ($vals) $lines[] = implode("\t", $vals);
    }
    return implode("\n", $lines);
}

/** Excel सारखा HTML table. $answer = true -> Student साठी रिकामा, editable */
function xlGridHtml(array $grid, bool $answer, string $tableId = ''): string
{
    $rows = (int)($grid['rows'] ?? 0);
    $cols = (int)($grid['cols'] ?? 0);
    $cells = $grid['cells'] ?? [];
    $showRows = $answer ? max($rows + 8, 24) : max($rows + 2, 12);
    $showCols = $answer ? min(14, max($cols + 2, 8)) : max($cols, 6);

    $h = '<table class="xl-table"' . ($tableId !== '' ? ' id="' . htmlspecialchars($tableId, ENT_QUOTES, 'UTF-8') . '"' : '') . '><thead><tr><th class="xl-corner"></th>';
    for ($c = 0; $c < $showCols; $c++) $h .= '<th>' . xlColName($c) . '</th>';
    $h .= '</tr></thead><tbody>';
    for ($r = 0; $r < $showRows; $r++) {
        $h .= '<tr><th>' . ($r + 1) . '</th>';
        for ($c = 0; $c < $showCols; $c++) {
            if ($answer) {
                $h .= '<td class="xl-cell" data-r="' . $r . '" data-c="' . $c . '" contenteditable="false" spellcheck="false"></td>';
                continue;
            }
            $cell = $cells[$r][$c] ?? null;
            if (is_array($cell) && !empty($cell['h'])) continue;
            $v = is_array($cell) ? (string)($cell['v'] ?? '') : '';
            $attrs = '';
            if (is_array($cell) && !empty($cell['cs']) && (int)$cell['cs'] > 1) $attrs .= ' colspan="' . (int)$cell['cs'] . '"';
            if (is_array($cell) && !empty($cell['rs']) && (int)$cell['rs'] > 1) $attrs .= ' rowspan="' . (int)$cell['rs'] . '"';
            $style = [];
            $class = 'xl-cell xl-ro';
            if (is_array($cell)) {
                if (!empty($cell['b'])) $style[] = 'font-weight:bold';
                if (!empty($cell['i'])) $style[] = 'font-style:italic';
                if (!empty($cell['u'])) $style[] = 'text-decoration:underline';
                $al = (string)($cell['a'] ?? '');
                if ($al === 'center' || $al === 'centerContinuous') $style[] = 'text-align:center';
                elseif ($al === 'right') $style[] = 'text-align:right';
                elseif ($al === 'left') $style[] = 'text-align:left';
                elseif ($v !== '' && preg_match('/^-?[\d,]+(\.\d+)?%?$/', $v)) $style[] = 'text-align:right';
                if (!empty($cell['bd'])) $class .= ' xl-bd';
            }
            $h .= '<td class="' . $class . '"' . $attrs . ($style ? ' style="' . implode(';', $style) . '"' : '') . '>' . htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
}

/* ===============================================================
   6) E-MAIL  (To, Cc, Bcc, Subject, Message, 2 Attachments)
=============================================================== */
function mailDefaultFiles(): array
{
    return ['Meeting Details.xls', 'Imp Documents.doc', 'Invitation Card.pdf', 'Load Document.xls', 'Passport Photo.png',
            'Application.doc', 'Delivery.xls', 'Invoice.pdf', 'Online Receipt.pdf', 'Brochure.pdf', 'E Certificate.doc',
            'Order List.xls', 'User.png', 'project.zip', 'Agreement.pdf', 'Demo.png'];
}

/** तुलनेसाठी Text: To, Cc, Bcc, Subject, Attachment 1, Attachment 2, Message (रिकामे सोडून, प्रत्येक वेगळ्या ओळीत) */
function buildMailText(array $m): string
{
    $lines = [];
    foreach (['to', 'cc', 'bcc', 'subject', 'att1', 'att2', 'message'] as $k) {
        $v = trim(str_replace(["\r\n", "\r"], "\n", (string)($m[$k] ?? '')));
        if ($v !== '') $lines[] = $v;
    }
    return implode("\n", $lines);
}

/** जुन्या "To: ... Cc: ..." Text वरून E-Mail fields */
function parseMailText(string $text): array
{
    $m = ['to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'att1' => '', 'att2' => '', 'message' => ''];
    $body = [];
    $attach = 0;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
        if (preg_match('/^\s*(To|Cc|Bcc|Subject)\s*:\s*(.*)$/i', $line, $mm) && trim($m['message']) === '' && !$body) {
            $m[strtolower($mm[1])] = trim($mm[2]);
        } elseif (preg_match('/^\s*Attachment\s*:\s*(.*)$/i', $line, $mm) && !$body && $attach < 2) {
            $attach++;
            $m['att' . $attach] = trim($mm[1]);
        } else {
            $body[] = $line;
        }
    }
    $m['message'] = trim(implode("\n", $body));
    return $m;
}

/** Statement / E-Mail record -> structured data (meta_json नसेल तर content वरून) */
function docMeta(string $type, array $row): array
{
    $meta = json_decode((string)($row['meta_json'] ?? ''), true);
    if (is_array($meta)) return $meta;
    $content = (string)($row['content'] ?? '');
    return $type === 'statement' ? gridFromTsv($content) : parseMailText($content);
}
