<?php
declare(strict_types=1);
// ===== UTM Admin Panel (same database as index.php / profile.php) =====
$life = 86400 * 30;
ini_set('session.gc_maxlifetime', (string)$life);
session_name('UTMADMINSESS'); // student session पासून वेगळा
session_set_cookie_params([
    'lifetime' => $life, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/utm_common.php';

$db = utmConnect();
if ($db->connect_errno) { http_response_code(500); exit('Database सापडला नाही. प्रथम install.php चालवा.'); }
$db->set_charset('utf8mb4');
ensureSchema($db);

// uploads/ मध्ये PHP फाईल चालू नये यासाठी संरक्षण
$upBase = __DIR__ . '/uploads';
if (!is_dir($upBase)) @mkdir($upBase, 0775, true);
if (is_dir($upBase) && !is_file($upBase . '/.htaccess')) {
    @file_put_contents($upBase . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\n    Require all denied\n</FilesMatch>\n");
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

// ---------- PDF upload helpers ----------
function adminUpload(string $field, string $folder): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('PDF upload करताना त्रुटी आली (php.ini मध्ये upload_max_filesize तपासा).');
    if ($f['size'] > 12 * 1024 * 1024) throw new RuntimeException('PDF कमाल 12 MB असावा.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if ($ext !== 'pdf' || $mime !== 'application/pdf') throw new RuntimeException('फक्त PDF फाईल स्वीकारली जाईल.');
    $base = __DIR__ . '/uploads/' . $folder;
    if (!is_dir($base)) mkdir($base, 0775, true);
    $name = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($f['tmp_name'], $base . '/' . $name)) throw new RuntimeException('PDF सेव्ह करता आला नाही.');
    return 'uploads/' . $folder . '/' . $name;
}
function removeFile(?string $rel): void {
    if (!$rel) return;
    $full = realpath(__DIR__ . '/' . $rel);
    $root = realpath(__DIR__ . '/uploads');
    if ($full && $root && strpos($full, $root) === 0 && is_file($full)) @unlink($full);
}
function adminReadDocx(string $field): array {
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Word फाईल upload करताना त्रुटी आली.');
    if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('Word फाईल कमाल 5 MB असावी.');
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'docx') {
        throw new RuntimeException('फक्त Word (.docx) फाईल स्वीकारली जाईल. (.doc असल्यास Word मध्ये Save As -> .docx करा)');
    }
    $r = docxToContent($f['tmp_name']);
    if (trim($r['text']) === '') throw new RuntimeException('Word फाईलमध्ये मजकूर सापडला नाही.');
    return $r;
}
function one(mysqli $db, string $sql, string $types, ...$params): ?array {
    $s = $db->prepare($sql); if (!$s) return null;
    $s->bind_param($types, ...$params); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ?: null;
}
function run(mysqli $db, string $sql, string $types, ...$params): void {
    $s = $db->prepare($sql);
    if (!$s) throw new RuntimeException('Query error: ' . $db->error);
    $s->bind_param($types, ...$params);
    if (!$s->execute()) { $e = $s->error; $s->close(); throw new RuntimeException('Query error: ' . $e); }
    $s->close();
}

// Admin Login: 5 चुकीचे प्रयत्न = 10 मिनिटे Lock
function adminLockRemaining(mysqli $db, string $ip): int {
    $r = one($db, 'SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS s FROM admin_attempts WHERE ip=? AND locked_until IS NOT NULL', 's', $ip);
    return ($r && (int)$r['s'] > 0) ? (int)$r['s'] : 0;
}
function adminFail(mysqli $db, string $ip): void {
    run($db, 'INSERT INTO admin_attempts(ip,tries) VALUES(?,1) ON DUPLICATE KEY UPDATE tries=tries+1', 's', $ip);
    $r = one($db, 'SELECT tries FROM admin_attempts WHERE ip=?', 's', $ip);
    if ($r && (int)$r['tries'] >= 5) {
        run($db, 'UPDATE admin_attempts SET tries=0, locked_until=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE ip=?', 's', $ip);
    }
}

$msg = ''; $err = '';

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $err = 'Security token चुकीचा आहे. पेज Refresh करा.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        try {
            if ($act === 'admin_login') {
                $u = trim((string)($_POST['username'] ?? '')); $p = (string)($_POST['password'] ?? '');
                $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
                $wait = adminLockRemaining($db, $ip);
                if ($wait > 0) throw new RuntimeException('खूप चुकीचे प्रयत्न झाले. कृपया ' . (int)ceil($wait / 60) . ' मिनिटांनी पुन्हा प्रयत्न करा.');
                $a = one($db, 'SELECT id,password_hash,must_change_password FROM admins WHERE username=?', 's', $u);
                if (!$a || !password_verify($p, $a['password_hash'])) {
                    adminFail($db, $ip);
                    throw new RuntimeException('Admin ID किंवा Password चुकीचा आहे.');
                }
                run($db, 'DELETE FROM admin_attempts WHERE ip=?', 's', $ip);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$a['id']; $_SESSION['admin_name'] = $u;
                $_SESSION['admin_force_change'] = (int)$a['must_change_password'];
                header('Location: admin.php'); exit;
            }
            if (empty($_SESSION['admin_id'])) throw new RuntimeException('प्रथम Admin Login करा.');

            if ($act === 'admin_logout') {
                unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_force_change']);
                header('Location: admin.php'); exit;
            }

            if ($act === 'change_admin_password') {
                $old = (string)($_POST['old_password'] ?? ''); $new = (string)($_POST['new_password'] ?? '');
                if (strlen($new) < 8) throw new RuntimeException('नवीन Password किमान 8 अक्षरांचा असावा.');
                $id = (int)$_SESSION['admin_id'];
                $a = one($db, 'SELECT password_hash FROM admins WHERE id=?', 'i', $id);
                if (!$a || !password_verify($old, $a['password_hash'])) throw new RuntimeException('जुना Password चुकीचा आहे.');
                run($db, 'UPDATE admins SET password_hash=?, must_change_password=0 WHERE id=?', 'si', password_hash($new, PASSWORD_DEFAULT), $id);
                $_SESSION['admin_force_change'] = 0; $msg = 'Admin Password बदलला.';
            }

            // ----- Passage (add / edit) : Word (.docx) किंवा Text, PDF optional -----
            if ($act === 'save_passage') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? '')); $lang = (string)($_POST['language'] ?? 'English');
                $level = (string)($_POST['difficulty'] ?? 'Medium');
                $content = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['content'] ?? '')));
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!in_array($lang, ['Marathi', 'English', 'Hindi'], true)) $lang = 'English';
                if (!in_array($level, ['Easy', 'Medium', 'Hard'], true)) $level = 'Medium';
                $useFor = (string)($_POST['use_for'] ?? 'both');
                if (!in_array($useFor, ['practice', 'preexam', 'both'], true)) $useFor = 'both';
                $html = null; $docChanged = false;
                if (!empty($_FILES['docx']) && $_FILES['docx']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $parsed = adminReadDocx('docx');
                    $content = $parsed['text']; $html = $parsed['html']; $docChanged = true;
                }
                if ($title === '' || $content === '') throw new RuntimeException('Passage चे नाव आणि (Word फाईल किंवा Text) आवश्यक आहे.');
                $pdf = adminUpload('pdf', 'passages');
                if ($id) {
                    $old = one($db, 'SELECT pdf_path, content, content_html FROM passages WHERE id=?', 'i', $id);
                    if (!$old) throw new RuntimeException('Passage सापडला नाही.');
                    if ($pdf) removeFile($old['pdf_path'] ?? null);
                    if (!$docChanged) $html = ($content === trim(str_replace(["\r\n", "\r"], "\n", (string)$old['content']))) ? $old['content_html'] : null;
                    run($db, 'UPDATE passages SET title=?,language=?,difficulty=?,content=?,content_html=?,pdf_path=COALESCE(?,pdf_path),use_for=?,is_active=? WHERE id=?', 'sssssssii', $title, $lang, $level, $content, $html, $pdf, $useFor, $active, $id);
                    $msg = 'Passage Update झाला.';
                } else {
                    run($db, 'INSERT INTO passages(title,language,difficulty,content,content_html,pdf_path,use_for,is_active) VALUES(?,?,?,?,?,?,?,?)', 'sssssssi', $title, $lang, $level, $content, $html, $pdf, $useFor, $active);
                    $msg = 'नवीन Passage सेव्ह झाला. Student ला UTM Practice / Pre-Exam मध्ये दिसेल.';
                }
            }

            // ----- Business Letter (add / edit) : Word (.docx) किंवा Text, PDF optional -----
            if ($act === 'save_letter') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? '')); $lang = (string)($_POST['language'] ?? 'English');
                $content = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['content'] ?? '')));
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!in_array($lang, ['Marathi', 'English', 'Hindi'], true)) $lang = 'English';
                $html = null; $docChanged = false;
                if (!empty($_FILES['docx']) && $_FILES['docx']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $parsed = adminReadDocx('docx');
                    $content = $parsed['text']; $html = $parsed['html']; $docChanged = true;
                }
                if ($title === '' || $content === '') throw new RuntimeException('Letter चे नाव आणि (Word फाईल किंवा Text) आवश्यक आहे.');
                $pdf = adminUpload('pdf', 'letters');
                if ($id) {
                    $old = one($db, 'SELECT pdf_path, content, content_html FROM business_letters WHERE id=?', 'i', $id);
                    if (!$old) throw new RuntimeException('Letter सापडले नाही.');
                    if ($pdf) removeFile($old['pdf_path'] ?? null);
                    if (!$docChanged) $html = ($content === trim(str_replace(["\r\n", "\r"], "\n", (string)$old['content']))) ? $old['content_html'] : null;
                    run($db, 'UPDATE business_letters SET title=?,language=?,content=?,content_html=?,pdf_path=COALESCE(?,pdf_path),is_active=? WHERE id=?', 'sssssii', $title, $lang, $content, $html, $pdf, $active, $id);
                    $msg = 'Business Letter Update झाले.';
                } else {
                    run($db, 'INSERT INTO business_letters(title,language,content,content_html,pdf_path,is_active) VALUES(?,?,?,?,?,?)', 'sssssi', $title, $lang, $content, $html, $pdf, $active);
                    $msg = 'नवीन Business Letter सेव्ह झाले.';
                }
            }

            // ----- Statement (Excel सारखा grid): .xlsx upload किंवा Excel मधून paste -----
            if ($act === 'save_statement') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? '')); $lang = (string)($_POST['language'] ?? 'English');
                $text = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['content'] ?? '')));
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!in_array($lang, ['Marathi', 'English', 'Hindi'], true)) $lang = 'English';
                if ($title === '') throw new RuntimeException('Statement चे नाव आवश्यक आहे.');
                $old = $id ? one($db, 'SELECT content, meta_json FROM exam_documents WHERE id=? AND doc_type=?', 'is', $id, 'statement') : null;
                if ($id && !$old) throw new RuntimeException('Statement सापडला नाही.');

                $grid = null;
                if (!empty($_FILES['xlsx']) && $_FILES['xlsx']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $f = $_FILES['xlsx'];
                    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Excel फाईल upload करताना त्रुटी आली.');
                    if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('Excel फाईल कमाल 5 MB असावी.');
                    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('फक्त Excel (.xlsx) फाईल स्वीकारली जाईल. (.xls असल्यास Excel मध्ये Save As -> .xlsx करा)');
                    $grid = xlsxToGrid($f['tmp_name']);
                } elseif ($text !== '') {
                    if ($old && $text === trim(str_replace(["\r\n", "\r"], "\n", (string)$old['content'])) && !empty($old['meta_json'])) {
                        $grid = json_decode((string)$old['meta_json'], true);   // Text बदलला नाही -> जुना format तसाच
                    }
                    if (!is_array($grid)) $grid = gridFromTsv($text);
                }
                if (!is_array($grid)) throw new RuntimeException('Excel (.xlsx) फाईल निवडा किंवा Excel मधून copy केलेला Table paste करा.');
                $content = gridToText($grid);
                if ($content === '') throw new RuntimeException('Statement मध्ये मजकूर सापडला नाही.');
                $json = json_encode($grid, JSON_UNESCAPED_UNICODE);
                if ($id) {
                    run($db, 'UPDATE exam_documents SET title=?,language=?,content=?,content_html=NULL,meta_json=?,is_active=? WHERE id=? AND doc_type=?', 'ssssiis', $title, $lang, $content, $json, $active, $id, 'statement');
                    $msg = 'Statement Update झाला.';
                } else {
                    run($db, 'INSERT INTO exam_documents(doc_type,title,language,content,meta_json,is_active) VALUES(?,?,?,?,?,?)', 'sssssi', 'statement', $title, $lang, $content, $json, $active);
                    $msg = 'नवीन Statement सेव्ह झाला. Student ला दिसेल.';
                }
            }

            // ----- E-Mail (To, Cc, Bcc, Subject, Message, 2 Attachments) -----
            if ($act === 'save_mail') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? '')); $lang = (string)($_POST['language'] ?? 'English');
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!in_array($lang, ['Marathi', 'English', 'Hindi'], true)) $lang = 'English';
                $m = [];
                foreach (['to', 'cc', 'bcc', 'subject', 'att1', 'att2'] as $k) $m[$k] = trim((string)($_POST[$k] ?? ''));
                $m['message'] = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['message'] ?? '')));
                if ($title === '') throw new RuntimeException('E-Mail चे नाव आवश्यक आहे.');
                $content = buildMailText($m);
                if ($content === '') throw new RuntimeException('E-Mail ची माहिती (To / Subject / Message) भरा.');
                $json = json_encode($m, JSON_UNESCAPED_UNICODE);
                if ($id) {
                    run($db, 'UPDATE exam_documents SET title=?,language=?,content=?,content_html=NULL,meta_json=?,is_active=? WHERE id=? AND doc_type=?', 'ssssiis', $title, $lang, $content, $json, $active, $id, 'email');
                    $msg = 'E-Mail Update झाला.';
                } else {
                    run($db, 'INSERT INTO exam_documents(doc_type,title,language,content,meta_json,is_active) VALUES(?,?,?,?,?,?)', 'sssssi', 'email', $title, $lang, $content, $json, $active);
                    $msg = 'नवीन E-Mail सेव्ह झाला. Student ला दिसेल.';
                }
            }

            // ----- Objective MCQ (add / edit) -----
            if ($act === 'save_question') {
                $id = (int)($_POST['id'] ?? 0);
                $lang = (string)($_POST['language'] ?? 'English'); $q = trim((string)($_POST['question'] ?? ''));
                $a = trim((string)($_POST['a'] ?? '')); $b = trim((string)($_POST['b'] ?? ''));
                $c = trim((string)($_POST['c'] ?? '')); $d = trim((string)($_POST['d'] ?? ''));
                $correct = (string)($_POST['correct_option'] ?? ''); $ex = trim((string)($_POST['explanation'] ?? ''));
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!in_array($lang, ['Marathi', 'English', 'Hindi'], true)) $lang = 'English';
                if ($q === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($correct, ['A', 'B', 'C', 'D'], true))
                    throw new RuntimeException('प्रश्न, चार पर्याय आणि योग्य उत्तर भरा.');
                if ($id) {
                    run($db, 'UPDATE objective_questions SET language=?,question=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,explanation=?,is_active=? WHERE id=?', 'ssssssssii', $lang, $q, $a, $b, $c, $d, $correct, $ex, $active, $id);
                    $msg = 'Question Update झाला.';
                } else {
                    run($db, 'INSERT INTO objective_questions(language,question,option_a,option_b,option_c,option_d,correct_option,explanation,is_active) VALUES(?,?,?,?,?,?,?,?,?)', 'ssssssssi', $lang, $q, $a, $b, $c, $d, $correct, $ex, $active);
                    $msg = 'नवीन Objective Question सेव्ह झाला.';
                }
            }

            // ----- Study Material PDF -----
            if ($act === 'save_material') {
                $title = trim((string)($_POST['title'] ?? ''));
                $pdf = adminUpload('pdf', 'study');
                if ($title === '' || !$pdf) throw new RuntimeException('PDF चे नाव आणि PDF फाईल दोन्ही आवश्यक आहेत.');
                run($db, 'INSERT INTO study_materials(title,file_path) VALUES(?,?)', 'ss', $title, $pdf);
                $msg = 'Study Material PDF Upload झाली.';
            }

            // ----- Delete / Show-Hide -----
            if ($act === 'delete_content' || $act === 'toggle_content') {
                $type = (string)($_POST['type'] ?? ''); $id = (int)($_POST['id'] ?? 0);
                $tables = ['passage' => 'passages', 'letter' => 'business_letters', 'question' => 'objective_questions', 'material' => 'study_materials', 'statement' => 'exam_documents', 'mail' => 'exam_documents'];
                if (!isset($tables[$type])) throw new RuntimeException('अवैध प्रकार.');
                $table = $tables[$type];
                if ($act === 'delete_content') {
                    if ($type !== 'question') { $old = one($db, "SELECT " . ($type === 'material' ? 'file_path' : 'pdf_path') . " AS p FROM $table WHERE id=?", 'i', $id); removeFile($old['p'] ?? null); }
                    run($db, "DELETE FROM $table WHERE id=?", 'i', $id); $msg = 'Record Delete केला.';
                } elseif ($type !== 'material') {
                    run($db, "UPDATE $table SET is_active = 1 - is_active WHERE id=?", 'i', $id); $msg = 'Status बदलला (Show/Hide).';
                }
            }

            // ----- Exam Settings (गुण / चुकांचे नियम) -----
            if ($act === 'save_exam_settings') {
                foreach (defaultSettings() as $k => $def) {
                    $v = trim((string)($_POST[$k] ?? $def));
                    if (!is_numeric($v) || (float)$v < 0) throw new RuntimeException('सर्व किंमती 0 किंवा त्यापेक्षा मोठे अंक असाव्यात.');
                    run($db, 'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', 'ss', $k, (string)(float)$v);
                }
                $msg = 'Exam Settings सेव्ह झाल्या.';
            }

            // ----- Students -----
            if ($act === 'student_update') {
                $id = (int)($_POST['id'] ?? 0); $name = trim((string)($_POST['full_name'] ?? ''));
                $email = strtolower(trim((string)($_POST['email'] ?? ''))); $phone = preg_replace('/\D+/', '', (string)($_POST['contact_number'] ?? ''));
                $speed = (int)($_POST['speed'] ?? 30); $lang = (string)($_POST['language'] ?? 'English'); $active = (int)($_POST['is_active'] ?? 1);
                if (!in_array($speed, [30, 40, 50], true)) $speed = 30;
                if (!in_array($lang, ['Marathi', 'English', 'Hindi'], true)) $lang = 'English';
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) !== 10) throw new RuntimeException('विद्यार्थ्याची माहिती योग्य भरा (Email आणि 10 अंकी मोबाईल).');
                $dup = one($db, 'SELECT id FROM users WHERE (email=? OR contact_number=?) AND id<>? LIMIT 1', 'ssi', $email, $phone, $id);
                if ($dup) throw new RuntimeException('हा Email किंवा मोबाईल दुसऱ्या विद्यार्थ्याकडे आधीच आहे.');
                run($db, 'UPDATE users SET full_name=?,email=?,contact_number=?,selected_speed=?,selected_lang=?,is_active=? WHERE id=?', 'sssisii', $name, $email, $phone, $speed, $lang, $active, $id);
                $msg = 'Student माहिती Update केली.';
            }
            if ($act === 'student_password') {
                $id = (int)($_POST['id'] ?? 0); $pw = (string)($_POST['new_password'] ?? '');
                if (strlen($pw) < 6) throw new RuntimeException('Student Password किमान 6 अक्षरांचा असावा.');
                run($db, 'UPDATE users SET password=? WHERE id=?', 'si', password_hash($pw, PASSWORD_DEFAULT), $id);
                $msg = 'Student चा नवीन Password सेट केला.';
            }
            if ($act === 'student_delete') {
                run($db, 'DELETE FROM users WHERE id=?', 'i', (int)($_POST['id'] ?? 0));
                $msg = 'Student आणि त्याचे Results Delete केले.';
            }
        } catch (Throwable $t) { $err = $t->getMessage(); }
    }
}

// Loading animation + Back बटन (Login आणि Dashboard दोन्हीकडे)
$loaderHtml = <<<'HTML'
<style>
.utm-loader{position:fixed;inset:0;z-index:30000;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;background:rgba(240,249,255,.93);backdrop-filter:blur(3px);opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
.utm-loader.show{opacity:1;visibility:visible}
.utm-spin{position:relative;width:80px;height:80px;border-radius:50%;border:5px solid #bae6fd;border-top-color:#0369a1;border-right-color:#16a34a;animation:utmSpin .9s linear infinite}
.utm-spin span{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:30px;animation:utmSpinBack .9s linear infinite}
.utm-loader p{margin:0;font-weight:700;font-size:15px;color:#075985;font-family:Arial,sans-serif}
@keyframes utmSpin{to{transform:rotate(360deg)}}
@keyframes utmSpinBack{to{transform:rotate(-360deg)}}
</style>
<div id="utmLoader" class="utm-loader show"><div class="utm-spin"><span>🔐</span></div><p id="utmLoaderText">लोड होत आहे...</p></div>
<script>
(function () {
    var loader = document.getElementById('utmLoader'), label = document.getElementById('utmLoaderText');
    function show(msg) { if (msg) label.textContent = msg; loader.classList.add('show'); }
    function hide() { loader.classList.remove('show'); }
    window.addEventListener('load', function () { setTimeout(hide, 300); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) hide(); });
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        var a = e.target.querySelector ? e.target.querySelector('input[name="action"]') : null;
        var m = {admin_login: 'Admin Login होत आहे...', admin_logout: 'Logout होत आहे...'};
        show(a && m[a.value] ? m[a.value] : 'सेव्ह होत आहे...');
    });
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a') : null;
        if (!a || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        var h = a.getAttribute('href') || '';
        if (!h || h.charAt(0) === '#' || a.target === '_blank') return;
        show('लोड होत आहे...');
    });
})();
</script>
HTML;

$logged = !empty($_SESSION['admin_id']);
$tab = (string)($_GET['tab'] ?? 'dashboard');
$tabs = ['dashboard' => 'Dashboard', 'students' => 'Students', 'passages' => 'Passages', 'letters' => 'Business Letters', 'statements' => 'Statements', 'mails' => 'E-Mails', 'objective' => 'Objective MCQ', 'study' => 'Study PDF', 'exam' => 'Exam Settings', 'results' => 'Results', 'logs' => 'Login Activity', 'settings' => 'Admin Password'];
if (!isset($tabs[$tab])) $tab = 'dashboard';
$editId = (int)($_GET['edit'] ?? 0);

// Results -> Excel (CSV) Export
if ($logged && $tab === 'results' && ($_GET['export'] ?? '') === 'csv') {
    $fs = (int)($_GET['student'] ?? 0);
    $where = $fs ? ('WHERE t.user_id = ' . $fs) : '';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="utm_results_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Student', 'Email', 'Mobile', 'Mode', 'Exercise', 'Language', 'Marks', 'Correct', 'Wrong', 'Untyped', 'CPM', 'Gross WPM', 'Net WPM', 'Accuracy %', 'Time (sec)', 'Status'], ',', '"', '\\');
    $q = $db->query("SELECT t.*, u.full_name, u.email, u.contact_number FROM test_results t LEFT JOIN users u ON u.id = t.user_id $where ORDER BY t.id DESC");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($out, [$r['created_at'], $r['full_name'], $r['email'], $r['contact_number'], $r['exam_mode'], $r['exercise_name'], $r['language'], $r['marks'], $r['correct_words'], $r['wrong_words'], $r['untype_words'], $r['cpm'], $r['gross_wpm'], $r['net_wpm'], $r['accuracy'], $r['time_taken'], $r['result_status']], ',', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

function csrfField(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['admin_csrf']) . '">'; }
function actionButtons(string $type, int $id, int $active = -1): string {
    $tab = ['passage' => 'passages', 'letter' => 'letters', 'question' => 'objective', 'material' => 'study', 'statement' => 'statements', 'mail' => 'mails'][$type];
    $o = '';
    if ($type !== 'material') $o .= '<a class="btn" href="admin.php?tab=' . $tab . '&edit=' . $id . '">Edit</a> ';
    if ($active >= 0) $o .= '<form method="post" style="display:inline">' . csrfField() . '<input type="hidden" name="action" value="toggle_content"><input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="id" value="' . $id . '"><button class="gray">' . ($active ? 'Hide' : 'Show') . '</button></form> ';
    $o .= '<form method="post" style="display:inline" onsubmit="return confirm(\'खरंच Delete करायचे?\')">' . csrfField() . '<input type="hidden" name="action" value="delete_content"><input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="id" value="' . $id . '"><button class="danger">Delete</button></form>';
    return $o;
}
function langSelect(string $name, string $cur): string {
    $o = '<select name="' . $name . '">';
    foreach (['Marathi', 'English', 'Hindi'] as $l) $o .= '<option' . ($cur === $l ? ' selected' : '') . '>' . $l . '</option>';
    return $o . '</select>';
}

// ---------- Login screen ----------
if (!$logged) { ?>
<!doctype html><html lang="mr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UTM Admin Login</title>
<style>body{font-family:Arial,sans-serif;background:#eef3f8;display:grid;place-items:center;min-height:100vh;margin:0}.box{background:#fff;padding:32px;border-radius:14px;width:min(380px,90%);box-shadow:0 10px 35px #0002}input,button{box-sizing:border-box;width:100%;padding:12px;margin:8px 0;border:1px solid #ccd5df;border-radius:7px}button{background:#0b3c61;color:#fff;border:0;cursor:pointer}.err{color:#b91c1c}.back{display:block;text-align:center;margin-top:12px;padding:11px;border-radius:7px;background:#e2e8f0;color:#0b3c61;text-decoration:none;font-weight:700}.back:hover{background:#cbd5e1}</style></head>
<body><div class="box"><h2>UTM Admin Login</h2><?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="admin_login"><label>Admin ID</label><input name="username" required><label>Password</label><input type="password" name="password" required><button>Login</button></form>
<a class="back" href="index.php">← Student Portal ला परत जा</a></div><?= $loaderHtml ?></body></html>
<?php exit; }

// ---------- Data for dashboard ----------
$counts = [];
foreach (['users' => 'Students', 'passages' => 'Passages', 'business_letters' => 'Letters', 'objective_questions' => 'Objective Questions', 'study_materials' => 'Study PDFs', 'exam_documents' => 'Statements + E-Mails', 'test_results' => 'Exam Attempts', 'login_logs' => 'Total Logins'] as $t => $label) {
    $r = $db->query("SELECT COUNT(*) c FROM $t"); $counts[$label] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
?>
<!doctype html><html lang="mr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UTM Admin Dashboard</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;font-family:Arial,"Noto Sans Devanagari",sans-serif;color:#172033}
.top{background:#0b3c61;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center}
.top form{margin:0}.layout{display:grid;grid-template-columns:220px 1fr;min-height:calc(100vh - 56px)}
nav{background:#102f49;padding:18px}nav a{display:block;color:#fff;text-decoration:none;padding:12px;border-radius:7px;margin:4px 0}nav a:hover,nav a.on{background:#245574}
.main{padding:24px;min-width:0}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.card,.panel{background:#fff;padding:18px;border-radius:10px;box-shadow:0 2px 9px #0000000f;margin-bottom:18px}.card b{display:block;font-size:28px;color:#0b3c61}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
input,select,textarea{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px;margin:5px 0 12px;font-family:inherit}
input[type=checkbox]{width:auto;margin:0 6px 0 0}textarea{min-height:130px}
button,.btn{background:#0b3c61;color:#fff;border:0;border-radius:6px;padding:8px 12px;cursor:pointer;text-decoration:none;display:inline-block;font-size:13px}
button.danger{background:#b91c1c}button.gray{background:#64748b}
.msg{background:#dcfce7;padding:10px;border-radius:7px}.err{background:#fee2e2;padding:10px;border-radius:7px}.warn{background:#fef3c7;padding:10px;border-radius:7px}
table{border-collapse:collapse;width:100%;background:#fff;min-width:760px}th,td{border:1px solid #dbe2ea;padding:8px;text-align:left;font-size:13px;vertical-align:top}th{background:#eaf1f8}
.scroll{overflow:auto}.tag{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;background:#e0f2fe;color:#075985}.off{background:#fee2e2;color:#b91c1c}
.mini input,.mini select{margin:2px 0 6px}.muted{color:#64748b;font-size:13px}
@media(min-width:1700px){.main{max-width:1900px;font-size:15px}}
@media(max-width:1100px){.layout{grid-template-columns:185px 1fr}.main{padding:16px}}
@media(max-width:800px){.layout{grid-template-columns:1fr}nav{display:flex;overflow-x:auto;padding:10px;gap:4px;-webkit-overflow-scrolling:touch}nav a{white-space:nowrap;margin:0}.main{padding:12px}.top{flex-wrap:wrap;gap:8px;padding:12px}.grid{grid-template-columns:1fr}}
@media(pointer:coarse){input,select,textarea{font-size:16px}button,.btn{min-height:40px}}
</style></head><body>
<header class="top"><strong>Universal Typing Master | Admin Panel</strong>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="admin_logout"><button>Logout (<?= h($_SESSION['admin_name'] ?? '') ?>)</button></form></header>
<div class="layout"><nav>
<?php foreach ($tabs as $k => $v): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="admin.php?tab=<?= $k ?>"><?= $v ?></a><?php endforeach; ?>
<a href="index.php">← Student Portal</a></nav><main class="main">
<?php if ($msg): ?><p class="msg"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<?php if (!empty($_SESSION['admin_force_change']) && $tab !== 'settings'): ?><p class="warn">⚠ सुरक्षेसाठी Default Admin Password बदला → <a href="admin.php?tab=settings">Admin Password</a></p><?php endif; ?>

<?php if ($tab === 'dashboard'): ?>
<h1>Dashboard</h1>
<div class="cards"><?php foreach ($counts as $k => $v): ?><div class="card"><span><?= h($k) ?></span><b><?= $v ?></b></div><?php endforeach; ?></div>
<div class="panel"><h3>येथून काय करता येईल?</h3>
<p>• <b>Passages</b>: Text + PDF add/edit/delete — Student ला UTM Practice मध्ये दिसतात.<br>
• <b>Business Letters</b>: Text + PDF add/edit — Student ला Business Letter मध्ये दिसतात.<br>
• <b>Objective MCQ</b>: Question, 4 Options, Correct Answer — Study Material मध्ये दिसतात.<br>
• <b>Study PDF</b>: PDF Upload — Study Material मध्ये दिसतात.<br>
• <b>Students</b>: Edit, Block, Password Reset, Delete, Login Count.<br>
• <b>Login Activity</b>: कोणी कधी Login केले.</p></div>

<?php elseif ($tab === 'settings'): ?>
<section class="panel"><h2>Admin Password Change</h2><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="change_admin_password">
<label>Current Password</label><input type="password" name="old_password" required><label>New Password (किमान 8)</label><input type="password" name="new_password" minlength="8" required><button>Update Password</button></form></section>

<?php elseif ($tab === 'students'):
    $edit = $editId ? one($db, 'SELECT * FROM users WHERE id=?', 'i', $editId) : null;
    $students = $db->query('SELECT u.*, (SELECT COUNT(*) FROM login_logs l WHERE l.user_id=u.id) login_count, (SELECT MAX(logged_at) FROM login_logs l WHERE l.user_id=u.id) last_login, (SELECT COUNT(*) FROM test_results t WHERE t.user_id=u.id) tests FROM users u ORDER BY u.id DESC'); ?>
<h1>Student Management</h1>
<?php if ($edit): ?><div class="panel"><h3>Edit Student: <?= h($edit['full_name']) ?></h3>
<form method="post" class="grid"><?= csrfField() ?><input type="hidden" name="action" value="student_update"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
<div><label>Full Name</label><input name="full_name" value="<?= h($edit['full_name']) ?>" required></div>
<div><label>Email</label><input name="email" type="email" value="<?= h($edit['email']) ?>" required></div>
<div><label>Mobile (10 अंकी)</label><input name="contact_number" value="<?= h($edit['contact_number']) ?>" required></div>
<div><label>Speed</label><select name="speed"><?php foreach ([30, 40, 50] as $sp): ?><option <?= (int)$edit['selected_speed'] === $sp ? 'selected' : '' ?>><?= $sp ?></option><?php endforeach; ?></select></div>
<div><label>Language</label><?= langSelect('language', (string)$edit['selected_lang']) ?></div>
<div><label>Status</label><select name="is_active"><option value="1" <?= $edit['is_active'] ? 'selected' : '' ?>>Active</option><option value="0" <?= !$edit['is_active'] ? 'selected' : '' ?>>Blocked</option></select></div>
<div><button>Save Student</button> <a class="btn gray" href="admin.php?tab=students">Cancel</a></div></form>
<hr><h3>Password Reset</h3>
<form method="post" style="max-width:340px"><?= csrfField() ?><input type="hidden" name="action" value="student_password"><input type="hidden" name="id" value="<?= $edit['id'] ?>"><input type="text" name="new_password" placeholder="नवीन Password (किमान 6)" required><button>Set New Password</button></form>
<p class="muted">जुना Password पाहता येत नाही (तो सुरक्षित hash म्हणून साठवलेला असतो). फक्त नवीन सेट करता येतो.</p></div><?php endif; ?>
<div class="scroll"><table><tr><th>ID</th><th>Name</th><th>Email</th><th>Mobile</th><th>Speed/Lang</th><th>Tests</th><th>Logins</th><th>Last Login</th><th>Status</th><th>Action</th></tr>
<?php while ($u = $students->fetch_assoc()): ?><tr><td><?= $u['id'] ?></td><td><?= h($u['full_name']) ?></td><td><?= h($u['email']) ?></td><td><?= h($u['contact_number']) ?></td>
<td><?= (int)$u['selected_speed'] ?> / <?= h($u['selected_lang']) ?></td><td><?= (int)$u['tests'] ?></td><td><?= (int)$u['login_count'] ?></td><td><?= h($u['last_login'] ?? 'Never') ?></td>
<td><span class="tag <?= $u['is_active'] ? '' : 'off' ?>"><?= $u['is_active'] ? 'Active' : 'Blocked' ?></span></td>
<td><a class="btn" href="admin.php?tab=students&edit=<?= $u['id'] ?>">Edit / Password</a> <a class="btn gray" href="admin.php?tab=results&student=<?= $u['id'] ?>">Results</a>
<form method="post" style="display:inline" onsubmit="return confirm('हा विद्यार्थी आणि त्याचे सर्व Results delete होतील. खात्री?')"><?= csrfField() ?><input type="hidden" name="action" value="student_delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="danger">Delete</button></form></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'passages'):
    $e = $editId ? one($db, 'SELECT * FROM passages WHERE id=?', 'i', $editId) : null;
    $rows = $db->query('SELECT * FROM passages ORDER BY id ASC'); ?>
<h1>Passage Management</h1>
<div class="panel"><form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="save_passage"><input type="hidden" name="id" value="<?= $e ? (int)$e['id'] : 0 ?>">
<h3><?= $e ? 'Passage Edit करा (ID ' . (int)$e['id'] . ')' : 'नवीन Passage Add करा' ?></h3>
<div class="grid"><div><label>Passage Title</label><input name="title" value="<?= h($e['title'] ?? '') ?>" required></div>
<div><label>Language</label><?= langSelect('language', (string)($e['language'] ?? 'Marathi')) ?></div>
<div><label>Difficulty</label><select name="difficulty"><?php foreach (['Easy', 'Medium', 'Hard'] as $l): ?><option <?= ($e['difficulty'] ?? 'Medium') === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
<div><label>कुठे वापरायचा?</label><select name="use_for"><?php foreach (['both' => 'UTM Practice + Pre-Exam', 'practice' => 'फक्त UTM Practice', 'preexam' => 'फक्त Pre-Exam'] as $uk => $ul): ?><option value="<?= $uk ?>" <?= ($e['use_for'] ?? 'both') === $uk ? 'selected' : '' ?>><?= h($ul) ?></option><?php endforeach; ?></select></div></div>
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;margin-bottom:12px"><label><b>① Word (.docx) Upload — सर्वात चांगला पर्याय</b><br><span class="muted">Student ला Word मधला format (center, bold, tab, justify, table) तसाच दिसेल आणि टायपिंग तपासणीसाठी Text आपोआप घेतला जातो.</span></label><input type="file" name="docx" accept=".docx"><?php if (!empty($e['content_html'])): ?><p class="muted">✔ या Passage साठी Word format आधीच save आहे. नवीन .docx निवडल्यास बदलेल.</p><?php endif; ?></div>
<label>② Text (Word नसेल तर येथे paste करा. Word वरून आलेला Text येथे दिसतो)</label><textarea name="content" id="contentBox"><?= h($e['content'] ?? '') ?></textarea>
<label>③ PDF Upload (Optional — Word नसेल तर Student ला ही PDF जशीच्या तशी दिसेल)</label><input type="file" name="pdf" id="pdfInput" data-mode="passage" accept="application/pdf"><p class="muted" id="pdfStatus"></p>
<?php if (!empty($e['pdf_path'])): ?><p class="muted">सध्याची PDF: <a target="_blank" href="<?= h($e['pdf_path']) ?>">View</a> (नवीन निवडल्यास बदलेल)</p><?php endif; ?>
<label><input type="checkbox" name="is_active" <?= !$e || $e['is_active'] ? 'checked' : '' ?>> Student ला दाखवा (Active)</label><br>
<button><?= $e ? 'Update Passage' : 'Save Passage' ?></button> <?php if ($e): ?><a class="btn gray" href="admin.php?tab=passages">Cancel</a><?php endif; ?></form></div>
<div class="scroll"><table><tr><th>ID</th><th>Title</th><th>Language</th><th>Level / Use</th><th>Words</th><th>Word format</th><th>PDF</th><th>Status</th><th>Action</th></tr>
<?php while ($r = $rows->fetch_assoc()): ?><tr><td><?= $r['id'] ?></td><td><?= h($r['title']) ?></td><td><?= h($r['language']) ?></td><td><?= h($r['difficulty']) ?> / <?= h($r['use_for'] ?? 'both') ?></td>
<td><?= count(preg_split('/\s+/u', trim((string)$r['content']), -1, PREG_SPLIT_NO_EMPTY)) ?></td><td><?= !empty($r['content_html']) ? 'Word ✔' : '—' ?></td>
<td><?= $r['pdf_path'] ? '<a target="_blank" href="' . h($r['pdf_path']) . '">View</a>' : '—' ?></td>
<td><span class="tag <?= $r['is_active'] ? '' : 'off' ?>"><?= $r['is_active'] ? 'Active' : 'Hidden' ?></span></td><td><?= actionButtons('passage', (int)$r['id'], (int)$r['is_active']) ?></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'letters'):
    $e = $editId ? one($db, 'SELECT * FROM business_letters WHERE id=?', 'i', $editId) : null;
    $rows = $db->query('SELECT * FROM business_letters ORDER BY id ASC'); ?>
<h1>Business Letter Management</h1>
<div class="panel"><form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="save_letter"><input type="hidden" name="id" value="<?= $e ? (int)$e['id'] : 0 ?>">
<h3><?= $e ? 'Letter Edit करा (ID ' . (int)$e['id'] . ')' : 'नवीन Letter Add करा' ?></h3>
<div class="grid"><div><label>Letter Title</label><input name="title" value="<?= h($e['title'] ?? '') ?>" required></div>
<div><label>Language</label><?= langSelect('language', (string)($e['language'] ?? 'English')) ?></div></div>
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;margin-bottom:12px"><label><b>① Word (.docx) Upload — सर्वात चांगला पर्याय</b><br><span class="muted">Letter चा format (Company name center + bold, Date उजवीकडे, tab, justify, table) Student ला तसाच दिसेल आणि टायपिंग तपासणीसाठी Text आपोआप घेतला जातो.</span></label><input type="file" name="docx" accept=".docx"><?php if (!empty($e['content_html'])): ?><p class="muted">✔ या Letter साठी Word format आधीच save आहे. नवीन .docx निवडल्यास बदलेल.</p><?php endif; ?></div>
<label>② Text (Word नसेल तर येथे paste करा. Word वरून आलेला Text येथे दिसतो)</label><textarea name="content" id="contentBox" style="min-height:220px"><?= h($e['content'] ?? '') ?></textarea>
<label>③ PDF Upload (Optional — Word नसेल तर Student ला ही PDF जशीच्या तशी दिसेल)</label><input type="file" name="pdf" id="pdfInput" data-mode="letter" accept="application/pdf"><p class="muted" id="pdfStatus"></p>
<?php if (!empty($e['pdf_path'])): ?><p class="muted">सध्याची PDF: <a target="_blank" href="<?= h($e['pdf_path']) ?>">View</a></p><?php endif; ?>
<label><input type="checkbox" name="is_active" <?= !$e || $e['is_active'] ? 'checked' : '' ?>> Student ला दाखवा (Active)</label><br>
<button><?= $e ? 'Update Letter' : 'Save Letter' ?></button> <?php if ($e): ?><a class="btn gray" href="admin.php?tab=letters">Cancel</a><?php endif; ?></form></div>
<div class="scroll"><table><tr><th>ID</th><th>Title</th><th>Language</th><th>Word format</th><th>PDF</th><th>Status</th><th>Action</th></tr>
<?php while ($r = $rows->fetch_assoc()): ?><tr><td><?= $r['id'] ?></td><td><?= h($r['title']) ?></td><td><?= h($r['language']) ?></td><td><?= !empty($r['content_html']) ? 'Word ✔' : '—' ?></td>
<td><?= $r['pdf_path'] ? '<a target="_blank" href="' . h($r['pdf_path']) . '">View</a>' : '—' ?></td>
<td><span class="tag <?= $r['is_active'] ? '' : 'off' ?>"><?= $r['is_active'] ? 'Active' : 'Hidden' ?></span></td><td><?= actionButtons('letter', (int)$r['id'], (int)$r['is_active']) ?></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'objective'):
    $e = $editId ? one($db, 'SELECT * FROM objective_questions WHERE id=?', 'i', $editId) : null;
    $rows = $db->query('SELECT * FROM objective_questions ORDER BY id DESC'); ?>
<h1>Objective MCQ Management</h1>
<div class="panel"><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_question"><input type="hidden" name="id" value="<?= $e ? (int)$e['id'] : 0 ?>">
<h3><?= $e ? 'Question Edit करा (ID ' . (int)$e['id'] . ')' : 'नवीन Question Add करा' ?></h3>
<label>Language</label><?= langSelect('language', (string)($e['language'] ?? 'Marathi')) ?>
<label>Question</label><textarea name="question" required><?= h($e['question'] ?? '') ?></textarea>
<div class="grid"><?php foreach (['a', 'b', 'c', 'd'] as $x): ?><div><label>Option <?= strtoupper($x) ?></label><input name="<?= $x ?>" value="<?= h($e['option_' . $x] ?? '') ?>" required></div><?php endforeach; ?></div>
<label>Correct Option</label><select name="correct_option"><?php foreach (['A', 'B', 'C', 'D'] as $o): ?><option <?= ($e['correct_option'] ?? 'A') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
<label>Explanation (Optional)</label><textarea name="explanation"><?= h($e['explanation'] ?? '') ?></textarea>
<label><input type="checkbox" name="is_active" <?= !$e || $e['is_active'] ? 'checked' : '' ?>> Student ला दाखवा (Active)</label><br>
<button><?= $e ? 'Update Question' : 'Save Question' ?></button> <?php if ($e): ?><a class="btn gray" href="admin.php?tab=objective">Cancel</a><?php endif; ?></form></div>
<div class="scroll"><table><tr><th>ID</th><th>Question</th><th>Language</th><th>Answer</th><th>Status</th><th>Action</th></tr>
<?php while ($r = $rows->fetch_assoc()): ?><tr><td><?= $r['id'] ?></td><td><?= h($r['question']) ?></td><td><?= h($r['language']) ?></td><td><?= h($r['correct_option']) ?></td>
<td><span class="tag <?= $r['is_active'] ? '' : 'off' ?>"><?= $r['is_active'] ? 'Active' : 'Hidden' ?></span></td><td><?= actionButtons('question', (int)$r['id'], (int)$r['is_active']) ?></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'study'): $rows = $db->query('SELECT * FROM study_materials ORDER BY id DESC'); ?>
<h1>Study Material PDF</h1>
<div class="panel"><form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="save_material">
<h3>नवीन Study PDF Upload करा</h3><label>PDF चे नाव</label><input name="title" required><label>PDF फाईल (कमाल 12 MB)</label><input type="file" name="pdf" accept="application/pdf" required><button>Upload PDF</button></form></div>
<div class="scroll"><table><tr><th>ID</th><th>Title</th><th>PDF</th><th>Date</th><th>Action</th></tr>
<?php while ($r = $rows->fetch_assoc()): ?><tr><td><?= $r['id'] ?></td><td><?= h($r['title']) ?></td><td><a target="_blank" href="<?= h($r['file_path']) ?>">View</a></td><td><?= h($r['created_at']) ?></td><td><?= actionButtons('material', (int)$r['id']) ?></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'statements'):
    $e = $editId ? one($db, 'SELECT * FROM exam_documents WHERE id=? AND doc_type=?', 'is', $editId, 'statement') : null;
    $rows = $db->query("SELECT * FROM exam_documents WHERE doc_type='statement' ORDER BY id ASC"); ?>
<h1>Statement Management</h1>
<div class="panel"><form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="save_statement"><input type="hidden" name="id" value="<?= $e ? (int)$e['id'] : 0 ?>">
<h3><?= $e ? 'Statement Edit करा (ID ' . (int)$e['id'] . ')' : 'नवीन Statement Add करा' ?></h3>
<div class="grid"><div><label>Title</label><input name="title" value="<?= h($e['title'] ?? '') ?>" required></div>
<div><label>Language</label><?= langSelect('language', (string)($e['language'] ?? 'English')) ?></div></div>
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;margin-bottom:12px"><label><b>① Excel (.xlsx) Upload — सर्वात चांगला पर्याय</b><br><span class="muted">Excel मधील Table Student ला Excel सारख्याच Grid मध्ये (Bold, Underline, Center, Border सहित) दिसेल. फक्त पहिली Sheet वाचली जाते. आकडे Excel मध्ये दिसतात तसेच (उदा. 9,602) घेतले जातात.</span></label><input type="file" name="xlsx" accept=".xlsx"><?php if (!empty($e['meta_json'])): ?><p class="muted">✔ Statement चा format आधीच save आहे. नवीन .xlsx निवडल्यास बदलेल.</p><?php endif; ?></div>
<label>② किंवा Excel मधून Cells Copy करून येथे Paste करा (Format शिवाय; पहिली ओळ फक्त एका cell ची असेल तर ती Title मानली जाते)</label>
<textarea name="content" style="min-height:200px;font-family:monospace;white-space:pre"><?= h($e['content'] ?? '') ?></textarea>
<label><input type="checkbox" name="is_active" <?= !$e || $e['is_active'] ? 'checked' : '' ?>> Student ला दाखवा (Active)</label><br>
<button><?= $e ? 'Update Statement' : 'Save Statement' ?></button> <?php if ($e): ?><a class="btn gray" href="admin.php?tab=statements">Cancel</a><?php endif; ?></form></div>
<div class="scroll"><table><tr><th>ID</th><th>Title</th><th>Language</th><th>Table (ओळी × Columns)</th><th>Status</th><th>Action</th></tr>
<?php while ($r = $rows->fetch_assoc()): $g = docMeta('statement', $r); ?><tr><td><?= $r['id'] ?></td><td><?= h($r['title']) ?></td><td><?= h($r['language']) ?></td><td><?= (int)($g['rows'] ?? 0) ?> × <?= (int)($g['cols'] ?? 0) ?></td>
<td><span class="tag <?= $r['is_active'] ? '' : 'off' ?>"><?= $r['is_active'] ? 'Active' : 'Hidden' ?></span></td><td><?= actionButtons('statement', (int)$r['id'], (int)$r['is_active']) ?></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'mails'):
    $e = $editId ? one($db, 'SELECT * FROM exam_documents WHERE id=? AND doc_type=?', 'is', $editId, 'email') : null;
    $em = $e ? docMeta('email', $e) : [];
    $rows = $db->query("SELECT * FROM exam_documents WHERE doc_type='email' ORDER BY id ASC"); ?>
<h1>E-Mail Management</h1>
<div class="panel"><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_mail"><input type="hidden" name="id" value="<?= $e ? (int)$e['id'] : 0 ?>">
<h3><?= $e ? 'E-Mail Edit करा (ID ' . (int)$e['id'] . ')' : 'नवीन E-Mail Add करा' ?></h3>
<div class="grid"><div><label>Title (Admin साठी)</label><input name="title" value="<?= h($e['title'] ?? '') ?>" required></div>
<div><label>Language</label><?= langSelect('language', (string)($e['language'] ?? 'English')) ?></div></div>
<p class="muted">Student ला हा E-Mail डाव्या बाजूला (Question) दिसेल आणि उजव्या बाजूला (Answer) तो स्वतः टाइप करेल. Attachment Student "📎" दाबून यादीतून निवडेल.</p>
<div class="grid">
<div><label>TO</label><input name="to" value="<?= h($em['to'] ?? '') ?>"></div>
<div><label>CC</label><input name="cc" value="<?= h($em['cc'] ?? '') ?>"></div>
<div><label>BCC</label><input name="bcc" value="<?= h($em['bcc'] ?? '') ?>"></div>
<div><label>Subject</label><input name="subject" value="<?= h($em['subject'] ?? '') ?>"></div>
</div>
<label>Message</label><textarea name="message" style="min-height:140px"><?= h($em['message'] ?? '') ?></textarea>
<datalist id="attFiles"><?php foreach (mailDefaultFiles() as $af): ?><option value="<?= h($af) ?>"><?php endforeach; ?></datalist>
<div class="grid">
<div><label>Attachment 1 (यादीतून निवडा किंवा नाव लिहा)</label><input name="att1" list="attFiles" value="<?= h($em['att1'] ?? '') ?>"></div>
<div><label>Attachment 2</label><input name="att2" list="attFiles" value="<?= h($em['att2'] ?? '') ?>"></div>
</div>
<label><input type="checkbox" name="is_active" <?= !$e || $e['is_active'] ? 'checked' : '' ?>> Student ला दाखवा (Active)</label><br>
<button><?= $e ? 'Update E-Mail' : 'Save E-Mail' ?></button> <?php if ($e): ?><a class="btn gray" href="admin.php?tab=mails">Cancel</a><?php endif; ?></form></div>
<div class="scroll"><table><tr><th>ID</th><th>Title</th><th>Language</th><th>To</th><th>Subject</th><th>Status</th><th>Action</th></tr>
<?php while ($r = $rows->fetch_assoc()): $rm = docMeta('email', $r); ?><tr><td><?= $r['id'] ?></td><td><?= h($r['title']) ?></td><td><?= h($r['language']) ?></td><td><?= h($rm['to'] ?? '') ?></td><td><?= h($rm['subject'] ?? '') ?></td>
<td><span class="tag <?= $r['is_active'] ? '' : 'off' ?>"><?= $r['is_active'] ? 'Active' : 'Hidden' ?></span></td><td><?= actionButtons('mail', (int)$r['id'], (int)$r['is_active']) ?></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'results'):
    $fs = (int)($_GET['student'] ?? 0);
    $where = $fs ? ('WHERE t.user_id = ' . $fs) : '';
    $resq = $db->query("SELECT t.*, u.full_name FROM test_results t LEFT JOIN users u ON u.id = t.user_id $where ORDER BY t.id DESC LIMIT 500"); ?>
<h1>Results<?= $fs ? ' (एका विद्यार्थ्याचे)' : ' (Latest 500)' ?></h1>
<p><a class="btn" href="admin.php?tab=results&export=csv<?= $fs ? '&student=' . $fs : '' ?>">⬇ Excel (CSV) Export</a> <?php if ($fs): ?><a class="btn gray" href="admin.php?tab=results">सर्व विद्यार्थी</a><?php endif; ?></p>
<div class="scroll"><table><tr><th>Date</th><th>Student</th><th>Mode</th><th>Exercise</th><th>Marks</th><th>Correct</th><th>Wrong</th><th>Untyped</th><th>Net WPM</th><th>Accuracy</th><th>Status</th></tr>
<?php while ($r = $resq->fetch_assoc()): ?><tr><td><?= h($r['created_at']) ?></td><td><?= h($r['full_name'] ?? 'Deleted') ?></td><td><?= h($r['exam_mode']) ?></td><td><?= h($r['exercise_name']) ?></td><td><b><?= h($r['marks']) ?></b></td><td><?= (int)$r['correct_words'] ?></td><td><?= (int)$r['wrong_words'] ?></td><td><?= (int)$r['untype_words'] ?></td><td><?= h($r['net_wpm']) ?></td><td><?= h($r['accuracy']) ?>%</td><td><span class="tag <?= $r['result_status'] === 'Pass' ? '' : 'off' ?>"><?= h($r['result_status']) ?></span></td></tr><?php endwhile; ?></table></div>

<?php elseif ($tab === 'exam'): $cfg = loadSettings($db); ?>
<h1>Exam Settings (गुण / चुकांचे नियम)</h1>
<div class="panel"><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_exam_settings">
<p class="muted"><b>Full mistake</b> = चुकीचा शब्द, जास्तीचा शब्द किंवा मधला सुटलेला शब्द. <b>Half mistake</b> = फक्त Capital/small अक्षर किंवा punctuation (. , ; इ.) चा फरक. <b>Untyped</b> = शेवटी टाइप न झालेले शब्द. येथे तुमच्या संस्थेच्या / MSCE च्या अधिकृत सूचनेनुसार आकडे भरा.</p>
<div class="grid">
<?php foreach (['passage' => 'Speed Passage', 'letter' => 'Business Letter', 'statement' => 'Statement', 'email' => 'E-Mail'] as $kind => $label): ?>
<div><h3><?= h($label) ?></h3>
<?php foreach (['total_marks' => 'एकूण गुण', 'pass_marks' => 'Pass होण्यासाठी किमान गुण', 'max_mistakes' => 'जास्तीत जास्त चुका (0 = मर्यादा नाही)', 'full_penalty' => 'प्रत्येक Full mistake चे गुण वजा', 'half_penalty' => 'प्रत्येक Half mistake चे गुण वजा', 'untyped_penalty' => 'प्रत्येक Untyped शब्दाचे गुण वजा'] + ($kind !== 'passage' ? ['minutes' => 'परीक्षेचा वेळ (मिनिटे)'] : []) as $f => $lab): $key = $kind . '_' . $f; ?>
<label><?= h($lab) ?></label><input name="<?= h($key) ?>" value="<?= h($cfg[$key]) ?>" type="number" step="0.1" min="0" required>
<?php endforeach; ?></div>
<?php endforeach; ?>
</div><button>Save Settings</button></form></div>

<?php elseif ($tab === 'logs'): $logs = $db->query('SELECT l.*, u.full_name FROM login_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 300'); ?>
<h1>Login Activity (Latest 300)</h1>
<div class="scroll"><table><tr><th>Student</th><th>Login ID</th><th>IP</th><th>Date / Time</th></tr>
<?php while ($r = $logs->fetch_assoc()): ?><tr><td><?= h($r['full_name'] ?? 'Deleted Student') ?></td><td><?= h($r['login_identifier']) ?></td><td><?= h($r['ip_address'] ?? '') ?></td><td><?= h($r['logged_at']) ?></td></tr><?php endwhile; ?></table></div>
<?php endif; ?>

<?php if (in_array($tab, ['passages', 'letters'], true)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
// PDF मधील text आपोआप काढून Text box मध्ये भरतो (browser मध्येच; PDF server वर जशीची तशी save होते).
(function () {
    const input = document.getElementById('pdfInput');
    const box = document.getElementById('contentBox');
    const status = document.getElementById('pdfStatus');
    if (!input || !box || typeof pdfjsLib === 'undefined') {
        if (status) status.textContent = 'PDF वाचन library लोड झाली नाही (Internet तपासा). Text हाताने paste करा; PDF तरीही Student ला दिसेल.';
        return;
    }
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const keepLayout = input.dataset.mode === 'letter';

    async function extract(file) {
        const pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
        const paragraphs = [];
        for (let pg = 1; pg <= pdf.numPages; pg++) {
            const page = await pdf.getPage(pg);
            const content = await page.getTextContent();
            const items = content.items.filter(i => i.str !== undefined && (i.str.trim() !== '' || i.hasEOL));
            // ओळींमध्ये गट करा (y समान असलेले)
            const lines = [];
            items.forEach(it => {
                const y = it.transform[5], x = it.transform[4];
                let line = lines.find(l => Math.abs(l.y - y) < 3);
                if (!line) { line = { y: y, parts: [], h: it.height || 12 }; lines.push(line); }
                line.parts.push({ x: x, w: it.width, s: it.str });
            });
            lines.sort((a, b) => b.y - a.y);
            let prevY = null;
            lines.forEach(line => {
                line.parts.sort((a, b) => a.x - b.x);
                let text = '', prev = null;
                line.parts.forEach(part => {
                    if (prev) {
                        const gap = part.x - (prev.x + prev.w);
                        if (keepLayout && gap > 18) text += '\t';
                        else if (gap > 1 && !/\s$/.test(text) && !/^\s/.test(part.s)) text += ' ';
                    }
                    text += part.s;
                    prev = part;
                });
                text = text.replace(/[ ]+$/g, '');
                if (text === '') return;
                const blank = prevY !== null && (prevY - line.y) > line.h * 1.7;
                if (keepLayout) {
                    if (blank) paragraphs.push('');
                    paragraphs.push(text);
                } else {
                    if (blank || !paragraphs.length) paragraphs.push(text);
                    else paragraphs[paragraphs.length - 1] += ' ' + text;
                }
                prevY = line.y;
            });
        }
        return keepLayout ? paragraphs.join('\n') : paragraphs.join('\n');
    }

    input.addEventListener('change', async function () {
        const file = input.files[0];
        if (!file) return;
        if (box.value.trim() !== '' && !confirm('Text box मध्ये आधीच मजकूर आहे. PDF मधील Text ने बदलायचा का?')) return;
        status.textContent = 'PDF मधून Text काढत आहे...';
        try {
            const text = (await extract(file)).trim();
            if (!text) { status.textContent = 'या PDF मध्ये Text सापडला नाही (scanned image PDF असू शकते). कृपया Text हाताने paste करा.'; return; }
            box.value = text;
            status.textContent = '✔ PDF मधील Text भरला. कृपया खाली तपासा (विशेषतः मराठी/हिंदी PDF मधील अक्षरे) आणि गरज असल्यास दुरुस्त करा.';
        } catch (err) {
            status.textContent = 'PDF वाचता आली नाही: ' + err.message + ' — Text हाताने paste करा.';
        }
    });
})();
</script>
<script>
// Text box मध्ये Tab दाबला की cursor बाहेर न जाता खरा Tab (paragraph चा आत सरकलेला भाग) पडतो.
(function () {
    const box = document.getElementById('contentBox');
    if (!box) return;
    box.addEventListener('keydown', function (e) {
        if (e.key === 'Tab' && !e.shiftKey) {
            e.preventDefault();
            box.setRangeText('\t', box.selectionStart, box.selectionEnd, 'end');
        }
    });
})();
</script>
<?php endif; ?>
</main></div><?= $loaderHtml ?></body></html>
