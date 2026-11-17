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
   1) Database upgrade (जुन्या data ला धक्का न लावता)
---------------------------------------------------------------- */
function ensureSchema(mysqli $db): void
{
    $columns = [
        ['passages', 'content_html', 'ALTER TABLE passages ADD COLUMN content_html MEDIUMTEXT NULL'],
        ['business_letters', 'content_html', 'ALTER TABLE business_letters ADD COLUMN content_html MEDIUMTEXT NULL'],
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
    foreach (['passage', 'letter'] as $kind) {
        foreach ($base as $k => $v) $out[$kind . '_' . $k] = $v;
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
function normalizeWord(string $w): string
{
    $w = mb_strtolower($w, 'UTF-8');
    $n = preg_replace('/[\p{P}\p{S}]+/u', '', $w);
    return $n === null ? $w : $n;
}

function evaluateTyping(array $orig, array $typed): array
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
                if ($orig[$i - 1] === $typed[$j - 1]) $sub = 0;
                elseif ($normO[$i - 1] !== '' && $normO[$i - 1] === $normT[$j - 1]) $sub = 1;
                else $sub = 2;
                $best = $cost[$i - 1][$j - 1] + $sub;
                $del = $cost[$i - 1][$j] + 2;
                $ins = $cost[$i][$j - 1] + 2;
                if ($del < $best) $best = $del;
                if ($ins < $best) $best = $ins;
                $cost[$i][$j] = $best;
            }
        }

        $i = $n;
        $j = $m;
        $rev = [];
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0) {
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
