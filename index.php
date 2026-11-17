<?php
declare(strict_types=1);
// Login session 30 दिवस टिकेल; प्रत्येक Option क्लिक करताना पुन्हा Login लागणार नाही.
$sessionLifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/utm_common.php';

const PRE_EXAM_DURATION = 420; // जुन्या sessions साठी default

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// १. डेटाबेस कनेक्शन
$db = new mysqli('localhost', 'root', '', 'student');
if ($db->connect_errno) {
    http_response_code(500);
    exit('<h2>Database Error: MySQL सुरू असल्याची आणि student डेटाबेस अस्तित्वात असल्याची खात्री करा (प्रथम install.php चालवा).</h2>');
}
$db->set_charset('utf8mb4');
ensureSchema($db);

// Login/Register साठी आवश्यक columns नसल्यास सुरक्षितपणे add करा.
// जुन्या users आणि test_results मधील डेटा delete किंवा replace होत नाही.
$requiredUserColumns = [
    'email' => "ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL",
    'contact_number' => "ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) NULL",
    'is_active' => "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"
];
foreach ($requiredUserColumns as $column => $alterSql) {
    $columnCheck = $db->query("SHOW COLUMNS FROM users LIKE '" . $db->real_escape_string($column) . "'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        if (!$db->query($alterSql)) {
            http_response_code(500);
            exit('Database update error: ' . htmlspecialchars($db->error, ENT_QUOTES, 'UTF-8'));
        }
    }
    if ($columnCheck) $columnCheck->free();
}

function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getWordList(string $text): array {
    $text = trim($text);
    if ($text === '') return [];
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($words) ? $words : [];
}

// Admin ने add केलेले active passages (selected language नुसार)
function dbPassages(mysqli $db, string $lang): array {
    $rows = [];
    $s = $db->prepare('SELECT id, title, difficulty, pdf_path FROM passages WHERE is_active = 1 AND language = ? ORDER BY id DESC');
    if ($s) {
        $s->bind_param('s', $lang);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc()) $rows[] = $x;
        $s->close();
    }
    return $rows;
}

// Admin ने add केलेली active business letters (selected language नुसार)
function dbLetters(mysqli $db, string $lang): array {
    $rows = [];
    $s = $db->prepare('SELECT id, title, pdf_path FROM business_letters WHERE is_active = 1 AND language = ? ORDER BY id DESC');
    if ($s) {
        $s->bind_param('s', $lang);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc()) $rows[] = $x;
        $s->close();
    }
    return $rows;
}

// Login Activity (Admin ला दिसते)
function logLogin(mysqli $db, int $userId, string $identifier): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $s = $db->prepare('INSERT INTO login_logs (user_id, login_identifier, ip_address) VALUES (?, ?, ?)');
    if ($s) {
        $s->bind_param('iss', $userId, $identifier, $ip);
        $s->execute();
        $s->close();
    }
}

// २. Word (.docx) फाइलमधून टेक्स्ट काढणे
function readDocx(string $filePath): string {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
        throw new RuntimeException('PHP ZipArchive किंवा DOM extension सुरू नाही.');
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) throw new RuntimeException('.docx फाईल उघडता आली नाही.');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) throw new RuntimeException('Word फाईलमध्ये मजकूर सापडला नाही.');

    $dom = new DOMDocument();
    $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $nodes = $xpath->query('//w:body//w:p');
    $lines = [];
    if ($nodes) {
        foreach ($nodes as $node) {
            $tNodes = $xpath->query('.//w:t', $node);
            $l = '';
            foreach ($tNodes as $tn) { $l .= $tn->textContent; }
            $lines[] = $l;
        }
    }
    return trim(implode("\n", $lines));
}

$error = '';
$action = $_POST['action'] ?? '';

// सर्व POST requests साठी CSRF सुरक्षा
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(403);
        exit('Invalid request. कृपया पेज refresh करून पुन्हा प्रयत्न करा.');
    }
}

// विद्यार्थ्याने निवडलेल्या mode नुसार योग्य स्क्रीन उघडा.
function utmRedirectToMode(string $mode): void {
    if ($mode === 'custom_doc') {
        header('Location: index.php?view=custom_upload');
    } elseif ($mode === 'pre_exam') {
        header('Location: index.php?view=pre_exam_select');
    } elseif ($mode === 'business_letter') {
        header('Location: index.php?view=letter_select');
    } elseif ($mode === 'study_material') {
        header('Location: index.php?view=study_material');
    } else {
        header('Location: index.php?view=inbuilt_select');
    }
    exit;
}

$validModes = ['custom_doc', 'inbuilt', 'pre_exam', 'business_letter', 'study_material'];

// Login झाल्यानंतर दुसरा option निवडल्यास पुन्हा माहिती विचारू नका.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'select_mode') {
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php?view=landing');
        exit;
    }
    $mode = (string)($_POST['exam_mode'] ?? 'inbuilt');
    if (!in_array($mode, $validModes, true)) $mode = 'inbuilt';
    $selectedSpeed = (int)($_POST['selected_speed'] ?? ($_SESSION['speed'] ?? 30));
    $selectedLanguage = (string)($_POST['selected_language'] ?? ($_SESSION['language'] ?? 'English'));
    $practiceMinutes = (int)($_POST['selected_minutes'] ?? ($_SESSION['practice_minutes'] ?? 7));
    if (!in_array($selectedSpeed, [30, 40, 50], true)) $selectedSpeed = 30;
    if (!in_array($selectedLanguage, ['Marathi', 'English', 'Hindi'], true)) $selectedLanguage = 'English';
    if (!in_array($practiceMinutes, [5, 7, 10], true)) $practiceMinutes = 7;
    if ($mode !== 'business_letter' && $mode !== 'study_material') {
        $_SESSION['speed'] = $selectedSpeed;
        $_SESSION['language'] = $selectedLanguage;
        $_SESSION['practice_minutes'] = $practiceMinutes;
        $updatePrefs = $db->prepare('UPDATE users SET selected_speed = ?, selected_lang = ? WHERE id = ?');
        if ($updatePrefs) {
            $studentId = (int)$_SESSION['user_id'];
            $updatePrefs->bind_param('isi', $selectedSpeed, $selectedLanguage, $studentId);
            $updatePrefs->execute();
            $updatePrefs->close();
        }
    } elseif ($mode === 'business_letter') {
        $_SESSION['language'] = $selectedLanguage;
        $_SESSION['test_duration'] = 1800;
    }
    $_SESSION['exam_mode'] = $mode;
    if ($mode !== 'business_letter' && $mode !== 'study_material') {
        $_SESSION['test_duration'] = $practiceMinutes * 60;
    }
    unset($_SESSION['test_result'], $_SESSION['started_at'], $_SESSION['ex_name']);
    utmRedirectToMode($mode);
}

// नवीन विद्यार्थ्याची Registration.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register_student') {
    $name = trim((string)($_POST['full_name'] ?? ''));
    $name = mb_substr($name, 0, 100, 'UTF-8');
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $contact = preg_replace('/\D+/', '', (string)($_POST['contact_number'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $speed = (int)($_POST['speed'] ?? 30);
    $lang = (string)($_POST['language'] ?? 'English');
    $mode = (string)($_POST['exam_mode'] ?? 'inbuilt');
    $practiceMinutes = (int)($_POST['practice_minutes'] ?? 7);

    if ($name === '') $error = 'कृपया पूर्ण नाव भरा.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'कृपया योग्य Email Address टाका.';
    elseif (!preg_match('/^[0-9]{10}$/', $contact)) $error = 'Contact Number 10 अंकी असावा.';
    elseif (strlen($password) < 6) $error = 'Password किमान 6 अक्षरांचा असावा.';

    if (!in_array($speed, [30, 40, 50], true)) $speed = 30;
    if (!in_array($lang, ['English', 'Marathi', 'Hindi'], true)) $lang = 'English';
    if (!in_array($mode, $validModes, true)) $mode = 'inbuilt';
    if (!in_array($practiceMinutes, [5, 7, 10], true)) $practiceMinutes = 7;

    if ($error === '') {
        $duplicate = $db->prepare('SELECT id FROM users WHERE email = ? OR contact_number = ? LIMIT 1');
        if (!$duplicate) {
            $error = 'Database query तयार करता आली नाही: ' . $db->error;
        } else {
            $duplicate->bind_param('ss', $email, $contact);
            $duplicate->execute();
            $already = $duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($already) $error = 'हा Email किंवा Contact Number आधीच Register आहे. कृपया Login करा.';
        }
    }

    if ($error === '') {
        $username = 'utm_' . bin2hex(random_bytes(8));
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $insert = $db->prepare('INSERT INTO users (full_name, username, password, selected_speed, selected_lang, email, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) {
            $error = 'Registration query तयार करता आली नाही: ' . $db->error;
        } else {
            $insert->bind_param('sssisss', $name, $username, $hashedPassword, $speed, $lang, $email, $contact);
            if (!$insert->execute()) {
                $error = 'Registration करता आली नाही: ' . $insert->error;
            } else {
                $userId = (int)$insert->insert_id;
                session_regenerate_id(true);
                logLogin($db, $userId, $email);
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $name;
                $_SESSION['speed'] = $speed;
                $_SESSION['language'] = $lang;
                $_SESSION['exam_mode'] = $mode;
                $_SESSION['practice_minutes'] = $mode === 'inbuilt' ? $practiceMinutes : 7;
                $_SESSION['test_duration'] = $mode === 'inbuilt' ? $practiceMinutes * 60 : ($mode === 'business_letter' ? 1800 : PRE_EXAM_DURATION);
                $insert->close();
                header('Location: index.php?view=landing&welcome=1');
                exit;
            }
            $insert->close();
        }
    }
}

// आधीच Register झालेल्या विद्यार्थ्याचा Login.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login_student') {
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $mode = (string)($_POST['exam_mode'] ?? 'inbuilt');
    if (!in_array($mode, $validModes, true)) $mode = 'inbuilt';

    if ($loginId === '' || $password === '') {
        $error = 'Email/Mobile आणि Password दोन्ही भरा.';
    } else {
        $loginId = strtolower($loginId);
        $contactLogin = preg_replace('/\D+/', '', $loginId);
        $stmt = $db->prepare('SELECT id, full_name, password, selected_speed, selected_lang, is_active FROM users WHERE email = ? OR contact_number = ? LIMIT 1');
        if (!$stmt) {
            $error = 'Login query तयार करता आली नाही: ' . $db->error;
        } else {
            $stmt->bind_param('ss', $loginId, $contactLogin);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$student || !password_verify($password, (string)$student['password'])) {
                $error = 'Email/Mobile किंवा Password चुकीचा आहे.';
            } elseif ((int)$student['is_active'] !== 1) {
                $error = 'तुमचे खाते Admin ने Block केले आहे. कृपया संस्थेशी संपर्क करा.';
            } else {
                session_regenerate_id(true);
                logLogin($db, (int)$student['id'], $loginId);
                $_SESSION['user_id'] = (int)$student['id'];
                $_SESSION['user_name'] = (string)$student['full_name'];
                $_SESSION['speed'] = (int)$student['selected_speed'];
                $_SESSION['language'] = (string)$student['selected_lang'];
                $_SESSION['exam_mode'] = $mode;
                $practiceMinutes = (int)($_SESSION['practice_minutes'] ?? 7);
                if (!in_array($practiceMinutes, [5, 7, 10], true)) $practiceMinutes = 7;
                $_SESSION['practice_minutes'] = $mode === 'inbuilt' ? $practiceMinutes : 7;
                $_SESSION['test_duration'] = $mode === 'inbuilt' ? $practiceMinutes * 60 : ($mode === 'business_letter' ? 1800 : PRE_EXAM_DURATION);
                header('Location: index.php?view=landing&welcome=1');
                exit;
            }
        }
    }
}

// जुन्या landing_login POST ला सुरक्षित compatibility fallback.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'landing_login') {
    $error = 'कृपया नवीन Login किंवा Register फॉर्म वापरा.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'start_test') {
    $mode = $_SESSION['exam_mode'] ?? 'inbuilt';
    $passageText = '';
    $exName = $_POST['exercise_name'] ?? 'Speed Passage 1';

    $_SESSION['opt_backspace'] = ($_POST['opt_backspace'] ?? 'off') === 'off';
    $_SESSION['opt_delete'] = ($_POST['opt_delete'] ?? 'off') === 'off';
    $_SESSION['passage_pdf'] = ''; // Admin ने PDF दिली असेल तरच खाली भरली जाते
    $_SESSION['passage_html'] = ''; // Admin ने Word (.docx) दिले असेल तरच खाली भरले जाते

    if ($mode === 'custom_doc') {
        $upSpeed = (int)($_POST['upload_speed'] ?? ($_SESSION['speed'] ?? 30));
        $upLang = (string)($_POST['upload_language'] ?? ($_SESSION['language'] ?? 'English'));
        $upMinutes = (int)($_POST['upload_minutes'] ?? ($_SESSION['practice_minutes'] ?? 7));
        if (!in_array($upSpeed, [30,40,50], true)) $upSpeed = 30;
        if (!in_array($upLang, ['Marathi','English','Hindi'], true)) $upLang = 'English';
        if (!in_array($upMinutes, [5,7,10], true)) $upMinutes = 7;
        $_SESSION['speed'] = $upSpeed; $_SESSION['language'] = $upLang; $_SESSION['practice_minutes'] = $upMinutes;
        if (!isset($_FILES['word_file'])) {
            $error = 'कृपया Word (.docx) फाईल निवडा.';
        } elseif ($_FILES['word_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'फाईल अपलोड करताना त्रुटी आली. पुन्हा प्रयत्न करा.';
        } elseif ($_FILES['word_file']['size'] > 5 * 1024 * 1024) {
            $error = 'फाईलचा आकार 5 MB पेक्षा कमी असावा.';
        } elseif (strtolower(pathinfo($_FILES['word_file']['name'], PATHINFO_EXTENSION)) !== 'docx') {
            $error = 'फक्त Word .docx फाईल स्वीकारली जाईल.';
        } else {
            try {
                $_SESSION['test_duration'] = max(60, min(600, (int)($_SESSION['practice_minutes'] ?? 7) * 60));
                $passageText = readDocx($_FILES['word_file']['tmp_name']);
                $safeFileName = basename((string)$_FILES['word_file']['name']);
                $exName = 'Uploaded: ' . mb_substr($safeFileName, 0, 80, 'UTF-8');
            } catch (Throwable $ex) {
                $error = $ex->getMessage();
            }
        }
    } elseif ($mode === 'business_letter') {
        // फक्त Admin ने add केलेले Business Letter
        $lid = (int)($_POST['letter_id'] ?? 0);
        $ls = $db->prepare('SELECT title, content, content_html, pdf_path FROM business_letters WHERE id = ? AND is_active = 1 LIMIT 1');
        if ($ls) {
            $ls->bind_param('i', $lid);
            $ls->execute();
            $row = $ls->get_result()->fetch_assoc();
            $ls->close();
            if ($row) {
                $exName = 'Business Letter: ' . $row['title'];
                $passageText = (string)$row['content'];
                $_SESSION['letter_style'] = 'Block Address | Block Paragraph | Left Close';
                $_SESSION['letter_number'] = $lid;
                $_SESSION['passage_pdf'] = (string)($row['pdf_path'] ?? '');
                $_SESSION['passage_html'] = (string)($row['content_html'] ?? '');
            }
        }
        $_SESSION['test_duration'] = 1800;
    } else {
        // UTM Practice आणि Pre-Exam: दोन्ही फक्त Admin ने add केलेले Passages वापरतात
        $pid = (int)($_POST['passage_id'] ?? 0);
        $ps = $db->prepare('SELECT title, content, content_html, pdf_path FROM passages WHERE id = ? AND is_active = 1 LIMIT 1');
        if ($ps) {
            $ps->bind_param('i', $pid);
            $ps->execute();
            $row = $ps->get_result()->fetch_assoc();
            $ps->close();
            if ($row) {
                $exName = ($mode === 'pre_exam' ? 'Pre-Exam: ' : 'Practice: ') . $row['title'];
                $passageText = (string)$row['content'];
                $_SESSION['passage_pdf'] = (string)($row['pdf_path'] ?? '');
                $_SESSION['passage_html'] = (string)($row['content_html'] ?? '');
            }
        }
        $_SESSION['test_duration'] = max(60, min(600, (int)($_SESSION['practice_minutes'] ?? 7) * 60));
    }

    if ($passageText === '') {
        $error = 'Passage लोड झाला नाही. कृपया यादीतून Admin ने दिलेला उतारा / पत्र निवडा.';
    } else {
        $_SESSION['passage'] = $passageText;
        $_SESSION['ex_name'] = $exName;
        $_SESSION['started_at'] = time();
        header('Location: index.php?view=exam');
        exit;
    }
}

// ६. परीक्षा सबमिट करणे व ४० गुणांचे मूल्यांकन (Test Results Insert)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit_exam') {
    $duration = (int)($_SESSION['test_duration'] ?? PRE_EXAM_DURATION);
    $timeUsed = max(1, min($duration, time() - (int)($_SESSION['started_at'] ?? time()) - 10));
    $origText = (string)($_SESSION['passage'] ?? '');
    $typedText = (string)($_POST['typed_text'] ?? '');

    if (empty($_SESSION['user_id']) || empty($_SESSION['started_at']) || $origText === '') {
        header('Location: index.php?view=landing');
        exit;
    }

    // Admin Settings नुसार गुण / चुकांचे नियम
    $cfg = loadSettings($db);
    $kind = (($_SESSION['exam_mode'] ?? '') === 'business_letter') ? 'letter' : 'passage';
    $totalMarks = (float)$cfg[$kind . '_total_marks'];
    $passMarks = (float)$cfg[$kind . '_pass_marks'];
    $maxMistakes = (float)$cfg[$kind . '_max_mistakes'];
    $fullPenalty = (float)$cfg[$kind . '_full_penalty'];
    $halfPenalty = (float)$cfg[$kind . '_half_penalty'];
    $untypedPenalty = (float)$cfg[$kind . '_untyped_penalty'];

    $origWords = getWordList($origText);
    $typedWords = getWordList($typedText);
    $typedCount = count($typedWords);

    // शब्द-शब्द तुलना: एक शब्द सुटला तर फक्त 1 चूक, पुढचे शब्द चुकीचे ठरत नाहीत.
    $eval = evaluateTyping($origWords, $typedWords);
    $correct = (int)$eval['correct'];
    $full = (int)$eval['full'];
    $half = (int)$eval['half'];
    $untyped = (int)$eval['untyped'];
    $wrong = $full + $half;                      // database मधील wrong_words
    $mistakeValue = $full + ($half * 0.5);

    $minutes = max(1 / 60, $timeUsed / 60);
    $cpm = (int)round(mb_strlen($typedText, 'UTF-8') / $minutes);
    $grossWpm = round($typedCount / $minutes, 2);
    $netWpm = max(0, round(($typedCount - $mistakeValue) / $minutes, 2));
    $accuracy = ($typedCount > 0) ? round((max(0, $typedCount - $mistakeValue) / $typedCount) * 100, 2) : 0.00;

    $marks = max(0.00, round($totalMarks - ($full * $fullPenalty) - ($half * $halfPenalty) - ($untyped * $untypedPenalty), 2));
    $mistakeWords = $full + $half + $untyped;
    $failed = ($marks < $passMarks) || ($maxMistakes > 0 && $mistakeWords > $maxMistakes);
    $status = $failed ? 'Fail' : 'Pass';

    $ins = $db->prepare('INSERT INTO test_results (user_id, exam_mode, exercise_name, language, marks, correct_words, wrong_words, untype_words, cpm, gross_wpm, net_wpm, accuracy, time_taken, result_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    if ($ins === false) {
        die("<h3>Database Error (INSERT test_results): " . htmlspecialchars($db->error) . "</h3>");
    }

    $uId = (int)($_SESSION['user_id'] ?? 1);
    $mName = (string)($_SESSION['exam_mode'] ?? 'inbuilt');
    $eName = (string)($_SESSION['ex_name'] ?? 'Speed Passage 1');
    $lName = (string)($_SESSION['language'] ?? 'English');

    $ins->bind_param(
        'isssdiiiidddis',
        $uId,
        $mName,
        $eName,
        $lName,
        $marks,
        $correct,
        $wrong,
        $untyped,
        $cpm,
        $grossWpm,
        $netWpm,
        $accuracy,
        $timeUsed,
        $status
    );

    if (!$ins->execute()) {
        $dbError = $ins->error;
        $ins->close();
        http_response_code(500);
        exit('Result save error: ' . e($dbError));
    }
    $ins->close();

    $_SESSION['test_result'] = [
        'marks' => $marks,
        'correct' => $correct,
        'wrong' => $wrong,
        'untyped' => $untyped,
        'cpm' => $cpm,
        'gross_wpm' => $grossWpm,
        'net_wpm' => $netWpm,
        'accuracy' => $accuracy,
        'time' => $timeUsed,
        'status' => $status,
        'full' => $full,
        'half' => $half,
        'total' => $totalMarks,
        'ops' => $eval['ops'],
        'original' => $origText,
        'typed' => $typedText
    ];
    header('Location: index.php?view=result');
    exit;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

$view = (string)($_GET['view'] ?? 'landing');
$allowedViews = ['landing', 'custom_upload', 'inbuilt_select', 'pre_exam_select', 'letter_select', 'study_material', 'exam', 'result', 'compare'];
if (!in_array($view, $allowedViews, true)) $view = 'landing';
if (in_array($view, ['custom_upload', 'inbuilt_select', 'pre_exam_select', 'letter_select', 'study_material', 'exam'], true) && empty($_SESSION['user_id'])) $view = 'landing';
if (in_array($view, ['result', 'compare'], true) && empty($_SESSION['test_result'])) $view = 'landing';
?>
<!DOCTYPE html>
<html lang="mr">
<head>
    <meta charset="UTF-8">
    <title>Universal Typing Master - Kolhapur</title>
    <link rel="stylesheet" href="style.css">

<style>
/* Login/Register panel: successful login/register नंतर हलके fade-slide होऊन बंद होते. */
.auth-panel-leaving { animation: authPanelExit .9s ease forwards; pointer-events:none; }
.landing-split-card.auth-dismissed-start { animation: authLayoutExpand .95s ease forwards; }
/* Login/Register गायब झाल्यावर cards साठी पूर्ण रुंदी वापरा. */
.landing-split-card.auth-expanded {
    display:grid !important;
    grid-template-columns:minmax(0,1fr) !important;
    width:min(1500px, calc(100vw - 40px)) !important;
    max-width:none !important;
    margin-left:auto !important;
    margin-right:auto !important;
    transition:width .7s ease, max-width .7s ease;
}
.landing-split-card.auth-expanded .landing-left-panel {
    width:100% !important;
    max-width:none !important;
    grid-column:1 / -1;
}
.landing-split-card.auth-expanded .mode-cards-container {
    display:grid !important;
    grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
    gap:24px !important;
    width:100% !important;
}
.landing-split-card.auth-expanded .mode-box {
    width:100% !important;
    min-height:270px;
    transform-origin:center;
    animation:cardRise .65s ease both;
}
@keyframes authPanelExit { 0% {opacity:1; transform:translateX(0); max-width:100%;} 65% {opacity:0; transform:translateX(35px);} 100% {opacity:0; transform:translateX(35px); visibility:hidden; width:0; max-width:0; padding:0; margin:0; overflow:hidden;} }
@keyframes authLayoutExpand { to {grid-template-columns:minmax(0,1fr);} }
@media(max-width:1000px){.landing-split-card.auth-expanded .mode-cards-container{grid-template-columns:repeat(2,minmax(0,1fr)) !important;}}
@media(max-width:650px){.landing-split-card.auth-expanded{width:calc(100vw - 20px) !important}.landing-split-card.auth-expanded .mode-cards-container{grid-template-columns:1fr !important;gap:14px !important}.landing-split-card.auth-expanded .mode-box{min-height:220px}}
.utm-settings-backdrop { position:fixed; inset:0; z-index:9999; display:none; align-items:center; justify-content:center; padding:18px; background:rgba(15,23,42,.58); backdrop-filter:blur(4px); }
.utm-settings-backdrop.show { display:flex; animation:utmFadeIn .2s ease; }
.utm-settings-dialog { position:relative; width:min(460px,100%); padding:28px; border-radius:18px; background:#fff; box-shadow:0 22px 70px rgba(0,0,0,.25); animation:utmDialogIn .25s ease; }
.utm-settings-dialog h2 { margin:0 0 7px; color:#075985; text-align:center; }
.utm-settings-dialog>p { margin:0 0 22px; color:#64748b; text-align:center; }
.utm-settings-close { position:absolute; top:9px; right:13px; border:0; background:none; font-size:28px; cursor:pointer; color:#64748b; }
.utm-setting-group { margin:16px 0; padding:15px; border:1px solid #dbe5ef; border-radius:12px; }
.utm-setting-group h3 { margin:0 0 12px; font-size:16px; color:#1e3a5f; }
.utm-setting-group label { display:inline-flex; align-items:center; gap:5px; margin:5px 13px 5px 0; cursor:pointer; }
.utm-settings-continue { width:100%; margin-top:8px; padding:12px; border:0; border-radius:8px; background:#086087; color:#fff; font-weight:700; font-size:16px; cursor:pointer; }
@keyframes utmFadeIn {from{opacity:0}to{opacity:1}}
@keyframes utmDialogIn {from{opacity:0;transform:translateY(15px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
/* Welcome user dropdown in the navigation bar */
.user-menu{position:relative;display:flex;align-items:center;margin-left:auto;z-index:10010}
.user-menu-toggle{display:flex;align-items:center;gap:9px;padding:10px 15px;border:1px solid rgba(255,255,255,.25);border-radius:24px;background:rgba(255,255,255,.12);color:black;font-weight:700;cursor:pointer;transition:background .25s,transform .25s}
.user-menu-toggle:hover{background:rgba(255,255,255,.22);transform:translateY(-2px)}
.user-menu-caret{font-size:12px;transition:transform .2s}
.user-menu.open .user-menu-caret{transform:rotate(180deg)}
.user-dropdown{position:absolute;right:0;top:calc(100% + 10px);min-width:190px;padding:8px;background:#fff;border:1px solid #e2e8f0;border-radius:13px;box-shadow:0 15px 38px rgba(15,23,42,.2);opacity:0;visibility:hidden;transform:translateY(-8px);transition:opacity .2s,transform .2s,visibility .2s}
.user-menu.open .user-dropdown{opacity:1;visibility:visible;transform:translateY(0)}
.user-dropdown a{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:8px;color:#1e293b;text-decoration:none;font-weight:600;transition:background .2s,padding-left .2s}
.user-dropdown a:hover{background:#eff6ff;padding-left:17px}
.user-dropdown a.logout-item{color:#dc2626}
/* Page entrance and card stagger animations */
@keyframes pageRise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes cardRise{from{opacity:0;transform:translateY(22px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
.utm-body{animation:pageRise .45s ease both}
.mode-box{animation:cardRise .55s cubic-bezier(.2,.75,.25,1) both;transition:transform .25s ease,box-shadow .25s ease}
.mode-box:nth-child(1){animation-delay:.04s}.mode-box:nth-child(2){animation-delay:.10s}.mode-box:nth-child(3){animation-delay:.16s}.mode-box:nth-child(4){animation-delay:.22s}.mode-box:nth-child(5){animation-delay:.28s}
.mode-box:hover{transform:translateY(-6px);box-shadow:0 16px 32px rgba(15,23,42,.13)}
.top-institute-bar,.top-menu-bar{animation:pageRise .4s ease both}
.landing-header-title{animation:pageRise .55s ease both}
.landing-auth-panel{animation:cardRise .6s ease both}
@media(max-width:700px){.user-menu{width:100%;justify-content:flex-end}.user-dropdown{right:0}.user-menu-toggle{max-width:100%}}
</style>

</head>
<body class="utm-body">

<!-- Loading animation: पेज उघडताना / Login / Submit करताना दिसते -->
<div id="utmLoader" class="utm-loader show" aria-live="polite">
    <div class="utm-spin"><span>⌨️</span></div>
    <p id="utmLoaderText">लोड होत आहे...</p>
</div>

<div class="top-institute-bar">
    <div class="inst-info">
        The Best Computer Education Institute, KOLHAPUR | .
    </div>
    <div class="inst-cert">
        INSTITUTE CODE : 41220 | DISTRICT : KOLHAPUR
    </div>
</div>

<div class="top-menu-bar">
    <div class="menu-items">
        <a href="index.php?view=landing" class="active">Speed Passage</a>
        <a href="<?= !empty($_SESSION['user_id']) ? 'index.php?view=letter_select' : 'index.php?view=landing&open=business_letter' ?>">Business Letter</a>
        <a href="<?= !empty($_SESSION['user_id']) ? 'index.php?view=study_material' : 'index.php?view=landing' ?>">Exam Objective</a>
    </div>
    <div class="menu-auth">
        <a href="admin.php" class="menu-admin-btn" title="Admin Login">🔐 Admin Login</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-menu-toggle" id="userMenuToggle" aria-expanded="false" aria-haspopup="true">
                    <span>👤 Welcome, <?= e((string)($_SESSION['user_name'] ?? 'Student')) ?></span><span class="user-menu-caret">▼</span>
                </button>
                <div class="user-dropdown" id="userDropdown">
                    <a href="profile.php">👤 Profile</a>
                    <a href="profile.php#performanceGraph">📊 Graph</a>
                    <a href="index.php?logout=1" class="logout-item">↪ Logout</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<main class="main-screen-container">
    <?php if ($error !== ''): ?>
        <div class="error-strip"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- VIEW 1: Landing page with Login/Register on the RIGHT side -->
    <?php if ($view === 'landing'): ?>
        <div class="desktop-landing-bg">
            <div class="landing-twin-card landing-split-card<?= !empty($_SESSION['user_id']) ? (isset($_GET['welcome']) ? ' auth-dismissed-start' : ' auth-expanded') : '' ?>">
                <section class="landing-left-panel">
                    <div class="landing-header-title">
                        The Best Computer Education Institute, KOLHAPUR<br>
                        <span>GCC-TBC Typing Practice Portal</span>
                    </div>

                    <div class="mode-cards-container">
                        <div class="mode-box" onclick="openMode('custom_doc')">
                            <div class="mode-badge">Option 1</div>
                            <div class="mode-icon-circle blue-icon">📄</div>
                            <h3>WORD FILE UPLOAD</h3>
                            <p>स्वतःची Word (.docx) फाईल अपलोड करून परीक्षा द्या</p>
                            <button type="button" class="btn-portal">Upload Word File</button>
                        </div>

                        <div class="mode-box" onclick="openMode('inbuilt')">
                            <div class="mode-badge">Option 2</div>
                            <div class="mode-icon-circle green-icon">⌨️</div>
                            <h3>UTM PRACTICE</h3>
                            <p>संस्थेने दिलेले उतारे निवडून टायपिंग प्रॅक्टिस करा</p>
                            <button type="button" class="btn-portal green-btn">UTM Practice</button>
                        </div>

                        <div class="mode-box" onclick="openMode('pre_exam')">
                            <div class="mode-badge">Option 3</div>
                            <div class="mode-icon-circle orange-icon">⏱️</div>
                            <h3>PRE-EXAM</h3>
                            <p>GCC-TBC 40 गुण, १० सेकंद काऊंटडाऊन आणि गुणांकन</p>
                            <button type="button" class="btn-portal orange-btn">Start Pre-Exam</button>
                        </div>

                        <div class="mode-box" onclick="openMode('business_letter')">
                            <div class="mode-badge">Option 4</div>
                            <div class="mode-icon-circle orange-icon">✉️</div>
                            <h3>BUSINESS LETTER</h3>
                            <p>GCC-TBC पद्धतीनुसार Business Letters चा सराव</p>
                            <button type="button" class="btn-portal orange-btn">Business Letter</button>
                        </div>
                        <div class="mode-box" onclick="openMode('study_material')">
                            <div class="mode-badge">Option 5</div>
                            <div class="mode-icon-circle blue-icon">📚</div>
                            <h3>STUDY MATERIAL PDF / OBJECTIVE</h3>
                            <p>Admin कडून उपलब्ध केलेले Study PDF आणि Objective प्रश्न</p>
                            <button type="button" class="btn-portal">Study Material</button>
                        </div>
                    </div>
                    <p class="landing-help-text">परीक्षा सुरू करण्यासाठी प्रथम एकदाच Login / Register करा. त्यानंतर कोणताही Option निवडता येईल.</p>
                </section>

                <?php if (empty($_SESSION['user_id']) || isset($_GET['welcome'])): ?>
                <aside class="landing-auth-panel<?= isset($_GET['welcome']) && !empty($_SESSION['user_id']) ? ' auth-panel-leaving' : '' ?>" id="landingAuthPanel">
                    <?php if (empty($_SESSION['user_id'])): ?>
                        <div class="landing-auth-heading">
                            <h2>Student Login / Registration</h2>
                            <p>पुढे जाण्यासाठी एकदाच Login करा</p>
                        </div>
                        <div class="auth-tabs">
                            <button type="button" class="auth-tab active" id="loginTabBtn" onclick="showAuthTab('login')">Login</button>
                            <button type="button" class="auth-tab" id="registerTabBtn" onclick="showAuthTab('register')">Register</button>
                        </div>

                        <form id="studentLoginForm" method="POST" action="index.php" class="utm-window-form auth-form landing-auth-form">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="login_student">
                            <div class="form-row">
                                <label>Contact No किंवा Email <span class="req">*</span></label>
                                <input type="text" name="login_id" required class="win-input" placeholder="मोबाईल नंबर किंवा ईमेल">
                            </div>
                            <div class="form-row">
                                <label>Password <span class="req">*</span></label>
                                <input type="password" name="password" required class="win-input" placeholder="पासवर्ड टाका">
                            </div>
                            <div class="win-btn-row"><button type="submit" class="win-btn-start">Login</button></div>
                        </form>

                        <form id="studentRegisterForm" method="POST" action="index.php" class="utm-window-form auth-form landing-auth-form" style="display:none;">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="register_student">
                            <div class="form-row">
                                <label>Full Name <span class="req">*</span></label>
                                <input type="text" name="full_name" required maxlength="100" class="win-input" placeholder="पूर्ण नाव प्रविष्ट करा">
                            </div>
                            <div class="form-row">
                                <label>Email Address <span class="req">*</span></label>
                                <input type="email" name="email" required class="win-input" placeholder="student@example.com">
                            </div>
                            <div class="form-row">
                                <label>Contact Number <span class="req">*</span></label>
                                <input type="tel" name="contact_number" required pattern="[0-9]{10}" maxlength="10" class="win-input" placeholder="१० अंकी मोबाईल नंबर">
                            </div>
                            <div class="form-row">
                                <label>Password <span class="req">*</span></label>
                                <input type="password" name="password" required minlength="6" class="win-input" placeholder="किमान ६ अक्षरे">
                            </div>
                            <div class="win-btn-row"><button type="submit" class="win-btn-start">Register</button></div>
                        </form>
                        <div class="win-version">UTM (V 1.11.1)</div>
                    <?php else: ?>
                        <!-- Login/Register panel login नंतर animation ने बंद होते; account menu navbar मध्ये उपलब्ध आहे. -->
                        <div class="logged-in-panel" aria-hidden="true"></div>
                    <?php endif; ?>
                </aside>
                <?php endif; ?>
            </div>
        </div>

        <form id="modeSelectForm" method="POST" action="index.php" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="select_mode">
            <input type="hidden" name="exam_mode" id="quickSelectedExamMode" value="inbuilt">
            <input type="hidden" name="selected_speed" id="quickSelectedSpeed" value="30">
            <input type="hidden" name="selected_language" id="quickSelectedLanguage" value="English">
            <input type="hidden" name="selected_minutes" id="quickSelectedMinutes" value="7">
        </form>

        <div id="utmSettingsModal" class="utm-settings-backdrop" aria-hidden="true">
            <div class="utm-settings-dialog" role="dialog" aria-modal="true" aria-labelledby="utmSettingsTitle">
                <button type="button" class="utm-settings-close" onclick="closeUtmSettings()" aria-label="Close">×</button>
                <h2 id="utmSettingsTitle">Exam Settings</h2>
                <p id="utmSettingsDescription">परीक्षेसाठी Speed, Language आणि Time निवडा.</p>
                <div class="utm-setting-group" id="speedSettingGroup">
                    <h3>1. Typing Speed</h3>
                    <label><input type="radio" name="utm_speed_choice" value="30" checked> 30 WPM</label>
                    <label><input type="radio" name="utm_speed_choice" value="40"> 40 WPM</label>
                    <label><input type="radio" name="utm_speed_choice" value="50"> 50 WPM</label>
                </div>
                <div class="utm-setting-group">
                    <h3>2. Language</h3>
                    <label><input type="radio" name="utm_language_choice" value="Marathi"> मराठी</label>
                    <label><input type="radio" name="utm_language_choice" value="English" checked> English</label>
                    <label><input type="radio" name="utm_language_choice" value="Hindi"> हिंदी</label>
                </div>
                <div class="utm-setting-group" id="timeSettingGroup">
                    <h3>3. Exam Time</h3>
                    <label><input type="radio" name="utm_time_choice" value="5"> 5 मिनिटे</label>
                    <label><input type="radio" name="utm_time_choice" value="7" checked> 7 मिनिटे</label>
                    <label><input type="radio" name="utm_time_choice" value="10"> 10 मिनिटे</label>
                </div>
                <p id="businessTimeNote" style="display:none;font-weight:700;color:#075985;">Business Letter साठी वेळ: 30 मिनिटे (Fixed)</p>
                <button type="button" class="utm-settings-continue" onclick="continueSelectedMode()">Continue →</button>
            </div>
        </div>

    <!-- VIEW 2: WORD फाईल अपलोड स्क्रीन -->
    <?php elseif ($view === 'custom_upload'): ?>
        <div class="utm-instructions-window">
            <div class="inst-win-header">
                <span>Option 1: Upload Word File (.docx)</span>
                <a href="index.php?view=landing" class="close-lnk">✕</a>
            </div>
            <form method="POST" action="index.php" enctype="multipart/form-data" style="padding: 30px;">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="start_test">
                <div class="upload-zone-box">
                    <div style="font-size:45px; margin-bottom:10px;">📄</div>
                    <h3 style="color:#0369a1;">तुमची Word फाईल (.docx) निवडा</h3>
                    <p style="color:#64748b; font-size:13px; margin:8px 0 20px;">फाईलमधील सर्व मजकूर थेट टायपिंग टेस्ट स्क्रीनवर लोड होईल.</p>
                    <input type="file" name="word_file" accept=".docx" required class="win-input" style="max-width:380px;">
                </div>

                <div class="utm-setting-group" style="margin:20px 0;">
                    <h3>Typing Speed निवडा</h3>
                    <label><input type="radio" name="upload_speed" value="30" <?= (int)($_SESSION['speed'] ?? 30) === 30 ? 'checked' : '' ?>> 30 WPM</label>
                    <label><input type="radio" name="upload_speed" value="40" <?= (int)($_SESSION['speed'] ?? 30) === 40 ? 'checked' : '' ?>> 40 WPM</label>
                    <label><input type="radio" name="upload_speed" value="50" <?= (int)($_SESSION['speed'] ?? 30) === 50 ? 'checked' : '' ?>> 50 WPM</label>
                    <h3 style="margin-top:14px;">Language निवडा</h3>
                    <label><input type="radio" name="upload_language" value="Marathi" <?= ($_SESSION['language'] ?? 'English') === 'Marathi' ? 'checked' : '' ?>> मराठी</label>
                    <label><input type="radio" name="upload_language" value="English" <?= ($_SESSION['language'] ?? 'English') === 'English' ? 'checked' : '' ?>> English</label>
                    <label><input type="radio" name="upload_language" value="Hindi" <?= ($_SESSION['language'] ?? 'English') === 'Hindi' ? 'checked' : '' ?>> हिंदी</label>
                    <h3 style="margin-top:14px;">Exam Time निवडा</h3>
                    <label><input type="radio" name="upload_minutes" value="5" <?= (int)($_SESSION['practice_minutes'] ?? 7) === 5 ? 'checked' : '' ?>> 5 मिनिटे</label>
                    <label><input type="radio" name="upload_minutes" value="7" <?= (int)($_SESSION['practice_minutes'] ?? 7) === 7 ? 'checked' : '' ?>> 7 मिनिटे</label>
                    <label><input type="radio" name="upload_minutes" value="10" <?= (int)($_SESSION['practice_minutes'] ?? 7) === 10 ? 'checked' : '' ?>> 10 मिनिटे</label>
                </div>
                <div class="key-restrictions-strip">
                    <span style="font-weight:bold; margin-right:15px; color:#0369a1;">Key Settings:</span>
                    <label><input type="radio" name="opt_backspace" value="off" checked> Backspace OFF (Block)</label>
                    <label><input type="radio" name="opt_backspace" value="on"> Backspace ON</label>
                    &nbsp;|&nbsp;
                    <label><input type="radio" name="opt_delete" value="off" checked> Delete OFF (Block)</label>
                    <label><input type="radio" name="opt_delete" value="on"> Delete ON</label>
                </div>

                <div style="margin-top:25px; text-align:right;">
                    <button type="submit" class="btn-start-exam-blue" style="padding:10px 30px; font-size:15px;">Start Custom Exam →</button>
                </div>
            </form>
        </div>

    <!-- VIEW 3: UTM Practice / Pre-Exam - फक्त Admin ने add केलेले Passages -->
    <?php elseif ($view === 'inbuilt_select' || $view === 'pre_exam_select'): ?>
        <?php
            $isPreExam = $view === 'pre_exam_select';
            $selLang = (string)($_SESSION['language'] ?? 'English');
            $adminPassages = dbPassages($db, $selLang);
        ?>
        <div class="utm-instructions-window">
            <div class="inst-win-header">
                <span><?= $isPreExam ? 'Pre-Exam - Passage Selection' : 'UTM Practice - Passage Selection' ?></span>
                <a href="index.php?view=landing" class="close-lnk">✕</a>
            </div>
            <form method="POST" action="index.php" class="inst-split-layout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="start_test">
                <div class="inst-left-list">
                    <h4>Select Passage (<?= (int)($_SESSION['speed'] ?? 30) ?> WPM | <?= e($selLang) ?> | <?= (int)($_SESSION['practice_minutes'] ?? 7) ?> मिनिटे)</h4>
                    <?php if ($adminPassages): ?>
                        <div class="passage-list-scroll">
                            <?php foreach ($adminPassages as $i => $ap): ?>
                                <label class="passage-item">
                                    <input type="radio" name="passage_id" value="<?= (int)$ap['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                    <?= e((string)$ap['title']) ?> (<?= e((string)$ap['difficulty']) ?>)
                                    <?php if (!empty($ap['pdf_path'])): ?> <a href="<?= e((string)$ap['pdf_path']) ?>" target="_blank" rel="noopener">📄 PDF</a><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin:8px 0;font-size:12px;">
                            <strong style="color:#0369a1;">Key Settings:</strong><br>
                            <label><input type="radio" name="opt_backspace" value="off" checked> Backspace OFF</label>
                            <label><input type="radio" name="opt_backspace" value="on"> ON</label><br>
                            <label><input type="radio" name="opt_delete" value="off" checked> Delete OFF</label>
                            <label><input type="radio" name="opt_delete" value="on"> ON</label>
                        </div>
                        <button type="submit" class="btn-start-exam-blue">Start</button>
                    <?php else: ?>
                        <p style="color:#b91c1c;font-weight:700;">निवडलेल्या भाषेत (<?= e($selLang) ?>) सध्या कोणताही Passage उपलब्ध नाही. कृपया दुसरी भाषा निवडा किंवा Admin ला Passage add करण्यास सांगा.</p>
                    <?php endif; ?>
                </div>
                <div class="inst-right-pane">
                    <h3>Instructions</h3>
                    <h2><?= $isPreExam ? 'Pre-Exam' : 'UTM Practice' ?> : <?= (int)($_SESSION['speed'] ?? 30) ?> WPM | <?= e($selLang) ?> | <?= (int)($_SESSION['practice_minutes'] ?? 7) ?> मिनिटे</h2>
                    <p>यादीतील उतारे तुमच्या संस्थेच्या Admin ने उपलब्ध करून दिले आहेत. तुम्ही निवडलेल्या भाषेतील उतारेच येथे दिसतात.</p>
                    <hr>
                    <p>प्रत्येक चुकीसाठी 1 गुण कमी करण्यात येईल. 24 पेक्षा अधिक चुका किंवा अपूर्ण शब्द असल्यास निकाल Fail होऊ शकतो.</p>
                    <p>परीक्षा सुरू होण्यापूर्वी 10 सेकंदांचा काऊंटडाऊन दिसेल. या काळात टायपिंग करता येणार नाही.</p>
                </div>
            </form>
        </div>

    <!-- VIEW 4: Business Letter - फक्त Admin ने add केलेली पत्रे -->
    <?php elseif ($view === 'letter_select'): ?>
        <?php
            $selLang = (string)($_SESSION['language'] ?? 'English');
            $adminLetters = dbLetters($db, $selLang);
        ?>
        <div class="utm-instructions-window">
            <div class="inst-win-header">
                <span>Business Letter Practice - GCC-TBC Pattern</span>
                <a href="index.php?view=landing" class="close-lnk">✕</a>
            </div>
            <form method="POST" action="index.php" class="inst-split-layout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="start_test">
                <div class="inst-left-list">
                    <h4>Select Business Letter — <?= e($selLang) ?></h4>
                    <?php if ($adminLetters): ?>
                        <div class="passage-list-scroll">
                            <?php foreach ($adminLetters as $i => $al): ?>
                                <label class="passage-item">
                                    <input type="radio" name="letter_id" value="<?= (int)$al['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                    <?= e((string)$al['title']) ?>
                                    <?php if (!empty($al['pdf_path'])): ?> <a href="<?= e((string)$al['pdf_path']) ?>" target="_blank" rel="noopener">📄 PDF</a><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin:10px 0;font-size:12px;">
                            <strong style="color:#0369a1;">Key Settings:</strong><br>
                            <label><input type="radio" name="opt_backspace" value="off" checked> Backspace OFF</label>
                            <label><input type="radio" name="opt_backspace" value="on"> ON</label><br>
                            <label><input type="radio" name="opt_delete" value="off" checked> Delete OFF</label>
                            <label><input type="radio" name="opt_delete" value="on"> ON</label>
                        </div>
                        <button type="submit" class="btn-start-exam-blue">Start Business Letter →</button>
                    <?php else: ?>
                        <p style="color:#b91c1c;font-weight:700;">निवडलेल्या भाषेत (<?= e($selLang) ?>) सध्या कोणतेही Business Letter उपलब्ध नाही. कृपया Admin ला पत्र add करण्यास सांगा.</p>
                    <?php endif; ?>
                </div>
                <div class="inst-right-pane">
                    <h3>GCC-TBC Business Letter Instructions</h3>
                    <h2>Duration: 30 Minutes</h2>
                    <p>प्रत्येक पत्र डाव्या बाजूला मूळ नमुन्यात दिसेल आणि उजव्या बाजूला तुम्ही टाइप कराल.</p>
                    <ul>
                        <li>Company name heading: Bold, Capital आणि Center.</li>
                        <li>Address/contact details heading खाली Center.</li>
                        <li>Reference number डावीकडे आणि Date उजवीकडे.</li>
                        <li>Inside address, Subject, Reference आणि paragraphs योग्य पद्धतीने मांडावेत.</li>
                        <li>Paragraphs मध्ये योग्य अंतर, alignment आणि margins ठेवा.</li>
                        <li>Complimentary close आणि signature योग्य ठिकाणी ठेवा.</li>
                    </ul>
                </div>
            </form>
        </div>

    <!-- VIEW: STUDY MATERIAL PDF / OBJECTIVE -->
    <?php elseif ($view === 'study_material'): ?>
        <div class="utm-instructions-window">
            <div class="inst-win-header"><span>Study Material PDF / Objective</span><a href="index.php?view=landing" class="close-lnk">✕</a></div>
            <div style="padding:25px;">
                <h2>Study Material PDF</h2>
                <p>Admin Panel मधून अपलोड केलेले PDF येथे उपलब्ध होतील.</p>
                <?php
                $hasStudyTable = $db->query("SHOW TABLES LIKE 'study_materials'");
                if ($hasStudyTable && $hasStudyTable->num_rows > 0):
                    $materials = $db->query("SELECT * FROM study_materials ORDER BY id DESC");
                    if ($materials && $materials->num_rows):
                        while ($material = $materials->fetch_assoc()):
                            $title = (string)($material['title'] ?? $material['file_name'] ?? 'Study PDF');
                            $filePath = (string)($material['file_path'] ?? $material['pdf_path'] ?? '');
                            if ($filePath !== ''): ?>
                                <p>📄 <a target="_blank" rel="noopener" href="<?= e($filePath) ?>"><?= e($title) ?></a></p>
                            <?php endif;
                        endwhile;
                    else: ?><p>सध्या PDF उपलब्ध नाहीत.</p><?php endif;
                else: ?><p>Study PDF साठी Admin Panel मधील upload सुविधा आणि study_materials table जोडणे आवश्यक आहे.</p><?php endif; ?>
                <hr style="margin:24px 0;">
                <h2>Objective Questions</h2>
                <?php
                $hasObjectiveTable = $db->query("SHOW TABLES LIKE 'objective_questions'");
                if ($hasObjectiveTable && $hasObjectiveTable->num_rows > 0):
                    $questions = $db->query("SELECT * FROM objective_questions WHERE is_active = 1 ORDER BY id DESC");
                    if ($questions && $questions->num_rows): $qNo = 1; while ($q = $questions->fetch_assoc()): ?>
                        <div style="padding:14px;margin:12px 0;border:1px solid #dbe5ef;border-radius:10px;">
                            <strong><?= $qNo++ ?>. <?= e((string)($q['question'] ?? $q['question_text'] ?? '')) ?></strong>
                            <p>A. <?= e((string)($q['option_a'] ?? '')) ?>　 B. <?= e((string)($q['option_b'] ?? '')) ?></p>
                            <p>C. <?= e((string)($q['option_c'] ?? '')) ?>　 D. <?= e((string)($q['option_d'] ?? '')) ?></p>
                            <details style="margin-top:8px;">
                                <summary style="cursor:pointer;color:#075985;font-weight:700;">Answer पहा</summary>
                                <p>योग्य उत्तर: <strong><?= e((string)($q['correct_option'] ?? '')) ?></strong></p>
                                <?php if (!empty($q['explanation'])): ?><p><?= e((string)$q['explanation']) ?></p><?php endif; ?>
                            </details>
                        </div>
                    <?php endwhile; else: ?><p>सध्या Objective प्रश्न उपलब्ध नाहीत.</p><?php endif;
                else: ?><p>Objective प्रश्न Admin Panel मधून उपलब्ध झाल्यावर येथे दिसतील.</p><?php endif; ?>
            </div>
        </div>

    <!-- VIEW 5: परीक्षा स्क्रीन - १० सेकंद काऊंटडाऊन व स्प्लिट व्ह्यू -->
    <?php elseif ($view === 'exam'): ?>
        <div id="preExamOverlay" class="pre-exam-overlay">
            <div class="pre-exam-modal">
                <p>Please wait Speed Passage Section will start after</p>
                <h1 id="preCountDigit">10</h1>
                <p>Seconds.</p>
            </div>
        </div>

        <div class="exam-screen-layout">
            <div class="exam-status-bar">
                <span><?= e($_SESSION['ex_name'] ?? 'Speed Passage 1') ?></span>
                <span class="timer-red">Used Typing Time : <strong id="examTimer">00:00</strong> / <?= sprintf('%02d:%02d', intdiv((int)($_SESSION['test_duration'] ?? 420), 60), ((int)($_SESSION['test_duration'] ?? 420)) % 60) ?></span>
            </div>

            <form method="POST" action="index.php" id="examForm" class="exam-split-grid">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="submit_exam">
                <div class="exam-left-ref">
                    <div class="ref-title"><?= ($_SESSION['exam_mode'] ?? '') === 'business_letter' ? 'Question Paper - Unformatted Business Letter' : 'Reference Passage (Left)' ?></div>
                    <?php if (!empty($_SESSION['passage_html'])): ?>
                        <!-- Admin ने upload केलेल्या Word (.docx) चा format जसाच्या तसा -->
                        <div class="ref-content ref-doc"><?= $_SESSION['passage_html'] ?></div>
                    <?php elseif (!empty($_SESSION['passage_pdf'])): ?>
                        <!-- Admin ने upload केलेली PDF जशीच्या तशी (alignment, tabs, justify सहित) दिसते -->
                        <iframe class="ref-pdf" src="<?= e((string)$_SESSION['passage_pdf']) ?>#toolbar=0&navpanes=0&view=FitH" title="Reference PDF"></iframe>
                    <?php else: ?>
                        <div class="ref-content"><?= e($_SESSION['passage'] ?? '') ?></div>
                    <?php endif; ?>
                </div>

                <div class="exam-right-typing">
                    <?php if (($_SESSION['exam_mode'] ?? '') === 'business_letter'): ?>
                        <div class="ref-title">Type & Format Your Business Letter (Right)</div>
                        <div class="letter-style-hint">Suggested format: <?= e((string)($_SESSION['letter_style'] ?? 'Block Address | Block Paragraph')) ?> | Duration: 30 Minutes</div>
                        <div class="letter-toolbar">
                            <button type="button" data-cmd="bold"><b>B</b></button>
                            <button type="button" data-cmd="underline"><u>U</u></button>
                            <button type="button" data-cmd="justifyLeft">Left</button>
                            <button type="button" data-cmd="justifyCenter">Center</button>
                            <button type="button" data-cmd="justifyRight">Right</button>
                            <button type="button" data-cmd="justifyFull">Justify</button>
                            <select id="letterFontSize" aria-label="Font size"><option value="3">12 pt</option><option value="4" selected>14 pt</option><option value="5">18 pt</option><option value="6">24 pt</option></select>
                            <button type="button" id="letterBottomBorder">Bottom Border</button>
                            <button type="button" id="letterInsertTable">Insert Table</button>
                        </div>
                        <div class="word-ruler-wrap">
                            <div class="word-ruler" id="wordRuler">
                                <?php for ($i = 0; $i <= 6; $i++): ?><span class="ruler-number" style="left:<?= ($i / 6) * 100 ?>%;"><?= $i ?></span><?php endfor; ?>
                                <span class="ruler-marker first-line" id="rulerFirst" title="First line indent"></span>
                                <span class="ruler-marker left-indent" id="rulerLeft" title="Left indent"></span>
                                <span class="ruler-marker right-indent" id="rulerRight" title="Right indent"></span>
                            </div>
                        </div>
                        <div id="letterEditor" class="letter-editor" contenteditable="false" spellcheck="false" data-placeholder="Type your business letter here..."></div>
                        <textarea name="typed_text" id="examTypingBox" style="display:none" disabled></textarea>
                    <?php else: ?>
                        <div class="ref-title">Type Your Passage Here (Right)</div>
                        <textarea name="typed_text" id="examTypingBox" class="typing-textarea" placeholder="Start typing here..." spellcheck="false" autocomplete="off" disabled></textarea>
                    <?php endif; ?>
                </div>

                <div class="exam-bottom-actions">
                    <button type="submit" id="examSubmitBtn" class="btn-exam-submit" tabindex="-1" disabled>Submit Exam</button>
                </div>
            </form>
        </div>

        <script>
            window.BLOCK_BACKSPACE = <?= !empty($_SESSION['opt_backspace']) ? 'true' : 'false' ?>;
            window.BLOCK_DELETE = <?= !empty($_SESSION['opt_delete']) ? 'true' : 'false' ?>;
            window.EXAM_DURATION = <?= (int)($_SESSION['test_duration'] ?? 420) ?>;
        </script>

    <!-- VIEW 5: GCC-TBC ४० गुणांचे निकाल पत्रक -->
    <?php elseif ($view === 'result' && isset($_SESSION['test_result'])): ?>
        <?php $res = $_SESSION['test_result']; ?>
        <div class="result-modal-backdrop">
            <div class="result-table-window">
                <div class="result-win-head">
                    <span><?= ($_SESSION['exam_mode'] ?? '') === 'business_letter' ? 'Business Letter Result' : 'Speed Passage Result' ?></span>
                    <a href="index.php?view=landing" class="close-lnk">✕</a>
                </div>
                <div class="result-win-body">
                    <div class="result-sub-info">
                        <strong><?= e($_SESSION['ex_name'] ?? 'Speed Passage') ?></strong>
                        <span>Used Typing Time : <?= sprintf('%02dmin %02dsec', intdiv($res['time'], 60), $res['time'] % 60) ?></span>
                    </div>

                    <table class="utm-result-grid">
                        <tr><th>Attributes</th><th>Correct/Incorrect</th></tr>
                        <tr class="highlight-row"><td>Marks :</td><td><strong><?= $res['marks'] ?> Out of <?= e((string)($res['total'] ?? 40)) ?></strong></td></tr>
                        <tr><td>No. of Correct Words :</td><td><?= $res['correct'] ?></td></tr>
                        <tr><td>Full Mistakes :</td><td><?= (int)($res['full'] ?? $res['wrong']) ?></td></tr>
                        <tr><td>Half Mistakes (Capital / Punctuation) :</td><td><?= (int)($res['half'] ?? 0) ?></td></tr>
                        <tr><td>No. of Untype Words :</td><td><?= $res['untyped'] ?></td></tr>
                        <tr><td>CPM :</td><td><?= $res['cpm'] ?></td></tr>
                        <tr><td>Gross WPM :</td><td><?= $res['gross_wpm'] ?></td></tr>
                        <tr><td>Net WPM :</td><td><strong><?= $res['net_wpm'] ?></strong></td></tr>
                        <tr><td>Accuracy :</td><td><?= $res['accuracy'] ?>%</td></tr>
                    </table>

                    <?php if (($_SESSION['exam_mode'] ?? '') === 'business_letter'): ?><div class="letter-result-note">हा निकाल typing comparison वर आधारित आहे. GCC-TBC presentation marks (margins, ruler indents, font, alignment, table आणि letter layout) शिक्षकांनी स्वतंत्रपणे तपासावेत.</div><?php endif; ?>
                    <div class="result-verdict <?= $res['status'] === 'Pass' ? 'pass-green' : 'fail-red' ?>">
                        Result : <?= $res['status'] ?>
                    </div>

                    <div class="result-actions-row">
                        <a href="index.php?view=compare" class="btn-utm-gray">Back To Compare Screen</a>
                        <a href="index.php?view=landing" class="btn-utm-blue">Exit</a>
                    </div>
                </div>
            </div>
        </div>

    <!-- VIEW 6: चुकांचे लाल रंगातील मूल्यांकन -->
    <?php elseif ($view === 'compare' && isset($_SESSION['test_result'])): ?>
        <?php $res = $_SESSION['test_result']; ?>
        <div class="compare-container">
            <div class="compare-head">
                <h2>Passage Evaluation</h2>
                <a href="index.php?view=result" class="btn-utm-blue">← Back to Scorecard</a>
            </div>
            <p class="compare-legend"><span class="text-correct">हिरवा = बरोबर</span> &nbsp;|&nbsp; <span class="text-half">केशरी = Half mistake (Capital / Punctuation)</span> &nbsp;|&nbsp; <span class="text-wrong">लाल = चूक</span> &nbsp;|&nbsp; <span class="text-wrong text-omit">[शब्द] = सुटलेला शब्द</span> &nbsp;|&nbsp; <span class="text-wrong text-extra">आडवी रेघ = जास्तीचा शब्द</span></p>
            <div class="compare-grid">
                <div class="compare-box">
                    <h3>Original Reference Passage</h3>
                    <div class="compare-text"><?= e($res['original']) ?></div>
                </div>
                <div class="compare-box">
                    <h3>Your Typed Passage (Evaluated)</h3>
                    <div class="compare-text">
                        <?php
                            $out = [];
                            $untypedRun = 0;
                            $flushUntyped = function () use (&$out, &$untypedRun) {
                                if ($untypedRun > 0) {
                                    $out[] = '<span class="text-untyped">[' . $untypedRun . ' शब्द टाइप केले नाहीत]</span>';
                                    $untypedRun = 0;
                                }
                            };
                            if (empty($res['ops'])) {
                                echo e((string)$res['typed']);
                            } else {
                                foreach ($res['ops'] as $op) {
                                    $type = (string)$op[0];
                                    $ow = (string)$op[1];
                                    $tw = (string)$op[2];
                                    if ($type === 'untyped') { $untypedRun++; continue; }
                                    $flushUntyped();
                                    if ($type === 'ok') {
                                        $out[] = '<span class="text-correct">' . e($tw) . '</span>';
                                    } elseif ($type === 'half') {
                                        $out[] = '<span class="text-half" title="Half mistake. बरोबर: ' . e($ow) . '">' . e($tw) . '</span>';
                                    } elseif ($type === 'wrong') {
                                        $out[] = '<span class="text-wrong" title="बरोबर: ' . e($ow) . '">' . e($tw) . '</span>';
                                    } elseif ($type === 'extra') {
                                        $out[] = '<span class="text-wrong text-extra" title="जास्तीचा शब्द">' . e($tw) . '</span>';
                                    } else {
                                        $out[] = '<span class="text-wrong text-omit" title="हा शब्द सुटला">[' . e($ow) . ']</span>';
                                    }
                                }
                                $flushUntyped();
                                echo implode(' ', $out);
                            }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<div class="utm-footer-bar">
    <div class="ft-left">Universal Exam Services • Maharashtra GCC-TBC Pattern</div>
    <div class="ft-right">Design & Developed for UTM Exam Systems</div>
</div>

<script>
// Login/Register आता landing page च्या उजव्या बाजूला दिसते.
function openMode(mode) {
    const isLoggedIn = <?= !empty($_SESSION['user_id']) ? 'true' : 'false' ?>;
    if (!isLoggedIn) {
        alert('कृपया प्रथम Login किंवा Register करा.');
        const loginField = document.querySelector('#studentLoginForm input[name="login_id"]');
        if (loginField) loginField.focus();
        return;
    }
    if (mode === 'study_material') { submitSelectedMode(mode); return; }
    const modal = document.getElementById('utmSettingsModal');
    const title = document.getElementById('utmSettingsTitle');
    const desc = document.getElementById('utmSettingsDescription');
    const speedGroup = document.getElementById('speedSettingGroup');
    const timeGroup = document.getElementById('timeSettingGroup');
    const businessNote = document.getElementById('businessTimeNote');
    window.currentSelectedMode = mode;
    const labels = {custom_doc:'Upload Word File Settings', inbuilt:'UTM Practice Settings', pre_exam:'Pre-Exam Settings', business_letter:'Business Letter Settings'};
    title.textContent = labels[mode] || 'Exam Settings';
    desc.textContent = 'Speed, Language आणि Exam Time निवडा.';
    speedGroup.style.display = mode === 'business_letter' ? 'none' : 'block';
    timeGroup.style.display = mode === 'business_letter' ? 'none' : 'block';
    businessNote.style.display = mode === 'business_letter' ? 'block' : 'none';
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function submitSelectedMode(mode) {
    const form = document.getElementById('modeSelectForm');
    document.getElementById('quickSelectedExamMode').value = mode;
    if (window.utmShowLoader) window.utmShowLoader('लोड होत आहे...');
    form.submit();
}

function closeUtmSettings() {
    const modal = document.getElementById('utmSettingsModal');
    if (modal) { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }
}

function continueSelectedMode() {
    const mode = window.currentSelectedMode || 'inbuilt';
    const speed = document.querySelector('input[name="utm_speed_choice"]:checked');
    const language = document.querySelector('input[name="utm_language_choice"]:checked');
    const minutes = document.querySelector('input[name="utm_time_choice"]:checked');
    document.getElementById('quickSelectedSpeed').value = speed ? speed.value : '30';
    document.getElementById('quickSelectedLanguage').value = language ? language.value : 'English';
    document.getElementById('quickSelectedMinutes').value = mode === 'business_letter' ? '30' : (minutes ? minutes.value : '7');
    closeUtmSettings();
    submitSelectedMode(mode);
}

function showAuthTab(tab) {
    const loginForm = document.getElementById('studentLoginForm');
    const registerForm = document.getElementById('studentRegisterForm');
    const loginBtn = document.getElementById('loginTabBtn');
    const registerBtn = document.getElementById('registerTabBtn');
    if (!loginForm || !registerForm || !loginBtn || !registerBtn) return;
    const registering = tab === 'register';
    loginForm.style.display = registering ? 'none' : 'block';
    registerForm.style.display = registering ? 'block' : 'none';
    loginBtn.classList.toggle('active', !registering);
    registerBtn.classList.toggle('active', registering);
}

// Welcome dropdown: Profile, Graph आणि Logout.
document.addEventListener('DOMContentLoaded', function () {
    const userMenu = document.getElementById('userMenu');
    const userToggle = document.getElementById('userMenuToggle');
    if (userMenu && userToggle) {
        userToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const opened = userMenu.classList.toggle('open');
            userToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (!userMenu.contains(event.target)) {
                userMenu.classList.remove('open');
                userToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                userMenu.classList.remove('open');
                userToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }
});

// Exam countdown, selected duration timer, automatic submit, and Backspace/Delete settings.
document.addEventListener('DOMContentLoaded', function () {
    const authPanel = document.getElementById('landingAuthPanel');
    const landingCard = document.querySelector('.landing-split-card.auth-dismissed-start');
    if (authPanel && landingCard) {
        window.setTimeout(function () {
            authPanel.remove();
            landingCard.classList.remove('auth-dismissed-start');
            landingCard.classList.add('auth-expanded');
            // Five exam options पुन्हा मोठ्या, पूर्ण रुंदीच्या layout मध्ये animate होतील.
            document.querySelectorAll('.landing-split-card.auth-expanded .mode-box').forEach(function (card, index) {
                card.style.animationDelay = (index * 0.09) + 's';
            });
        }, 950);
    }
    <?php if ($error !== ''): ?>
    showAuthTab(<?= json_encode($action === 'register_student' ? 'register' : 'login') ?>);
    <?php endif; ?>
    const overlay = document.getElementById('preExamOverlay');
    const digit = document.getElementById('preCountDigit');
    const timer = document.getElementById('examTimer');
    const box = document.getElementById('examTypingBox');
    const letterEditor = document.getElementById('letterEditor');
    const form = document.getElementById('examForm');
    const submit = document.getElementById('examSubmitBtn');
    if (!overlay || !digit || !timer || !box || !form || !submit) return;
    const typingTarget = letterEditor || box;

    let preCount = 10;
    let remaining = Number(window.EXAM_DURATION || 420);
    let started = false;
    if (letterEditor) letterEditor.contentEditable = 'false';
    box.disabled = true;
    submit.disabled = true;
    digit.textContent = String(preCount);

    const showTime = (seconds) => {
        const m = Math.floor(seconds / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        timer.textContent = m + ':' + s;
    };
    showTime(remaining);

    let autoSubmit = false;
    // Cursor नेहमी टायपिंग box मध्येच ठेवा (Tab दाबण्याची गरज नाही)
    function focusTyping() {
        try { typingTarget.focus({ preventScroll: true }); } catch (e) { typingTarget.focus(); }
        if (letterEditor && document.activeElement === letterEditor) {
            const range = document.createRange();
            range.selectNodeContents(letterEditor);
            range.collapse(false);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }

    const preTimer = window.setInterval(function () {
        preCount -= 1;
        if (preCount > 0) {
            digit.textContent = String(preCount);
            return;
        }
        window.clearInterval(preTimer);
        overlay.style.display = 'none';
        if (letterEditor) letterEditor.contentEditable = 'true';
        box.disabled = false;
        submit.disabled = false;
        started = true;
        focusTyping();
        window.setTimeout(focusTyping, 60);
        window.setTimeout(focusTyping, 350);

        const examTimer = window.setInterval(function () {
            if (!started) { window.clearInterval(examTimer); return; }
            remaining -= 1;
            showTime(Math.max(0, remaining));
            if (remaining <= 0) {
                started = false;
                if (letterEditor) letterEditor.contentEditable = 'false';
                box.disabled = true;
                submit.disabled = true;
                window.clearInterval(examTimer);
                autoSubmit = true;
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }
        }, 1000);
    }, 1000);

    // परीक्षा सुरू असताना Tab ने cursor बाहेर (Submit बटनावर) जाऊ नये.
    document.addEventListener('keydown', function (event) {
        if (!started) return;
        if (event.key === 'Tab' && event.target !== typingTarget) {
            event.preventDefault();
            focusTyping();
            return;
        }
        // Focus दुसरीकडे गेला असेल आणि अक्षर दाबले तर पुन्हा typing box मध्ये आणा
        if (document.activeElement !== typingTarget && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            focusTyping();
        }
    });

    typingTarget.addEventListener('keydown', function (event) {
        if (event.key === 'Tab') {
            // Tab ने cursor बाहेर जाऊ देऊ नका; त्याऐवजी खरा Tab (अंतर) टाइप होईल
            event.preventDefault();
            if (started) {
                const inserted = document.execCommand('insertText', false, '\t');
                if (!inserted && !letterEditor) {
                    box.setRangeText('\t', box.selectionStart, box.selectionEnd, 'end');
                }
            }
            return;
        }
        if (event.key === 'Backspace' && window.BLOCK_BACKSPACE === true) {
            event.preventDefault();
        }
        if (event.key === 'Delete' && window.BLOCK_DELETE === true) {
            event.preventDefault();
        }
    });

    form.addEventListener('submit', function (event) {
        if (!autoSubmit && !window.confirm('परीक्षा Submit करायची आहे का?')) {
            event.preventDefault();
            focusTyping();
            return;
        }
        started = false;
        if (letterEditor) { box.value = letterEditor.innerText || letterEditor.textContent || ''; letterEditor.contentEditable = 'false'; }
        box.disabled = false; // Ensure typed text is submitted.
        submit.disabled = true;
    });

    // Business Letter formatting toolbar
    if (letterEditor) {
        document.querySelectorAll('.letter-toolbar [data-cmd]').forEach(function (button) {
            button.addEventListener('click', function () {
                letterEditor.focus();
                document.execCommand(button.getAttribute('data-cmd'), false, null);
            });
        });
        const fontSize = document.getElementById('letterFontSize');
        if (fontSize) fontSize.addEventListener('change', function () {
            letterEditor.focus(); document.execCommand('fontSize', false, fontSize.value);
        });
        const borderBtn = document.getElementById('letterBottomBorder');
        if (borderBtn) borderBtn.addEventListener('click', function () {
            letterEditor.focus();
            const sel = window.getSelection();
            let node = sel && sel.anchorNode ? sel.anchorNode : letterEditor;
            while (node && node.parentElement && node.parentElement !== letterEditor) node = node.parentElement;
            const block = node && node.nodeType === 1 ? node : (node ? node.parentElement : null);
            if (block && block !== letterEditor) block.style.borderBottom = '1px solid #222';
        });
        const tableBtn = document.getElementById('letterInsertTable');
        if (tableBtn) tableBtn.addEventListener('click', function () {
            letterEditor.focus();
            document.execCommand('insertHTML', false, '<table border="1" style="border-collapse:collapse;width:100%"><tr><th>Sr. No.</th><th>Particulars</th><th>Quantity</th></tr><tr><td>1</td><td>Item description</td><td>1</td></tr><tr><td>2</td><td>Item description</td><td>1</td></tr></table><p><br></p>');
        });

        // Ruler markers adjust current paragraph indents; drag horizontally.
        const ruler = document.getElementById('wordRuler');
        const markers = [
            {el: document.getElementById('rulerFirst'), prop: 'textIndent'},
            {el: document.getElementById('rulerLeft'), prop: 'marginLeft'},
            {el: document.getElementById('rulerRight'), prop: 'marginRight'}
        ];
        function currentBlock() {
            const sel = window.getSelection();
            let node = sel && sel.anchorNode ? sel.anchorNode : letterEditor;
            if (node.nodeType === 3) node = node.parentElement;
            while (node && node !== letterEditor && !/^(P|DIV|LI|H1|H2|H3)$/i.test(node.tagName)) node = node.parentElement;
            return node && node !== letterEditor ? node : letterEditor;
        }
        markers.forEach(function (item) {
            if (!item.el || !ruler) return;
            item.el.addEventListener('pointerdown', function (event) {
                event.preventDefault();
                const block = currentBlock();
                const rect = ruler.getBoundingClientRect();
                const move = function (ev) {
                    const px = Math.max(0, Math.min(rect.width, ev.clientX - rect.left));
                    const inches = (px / rect.width) * 6;
                    const value = (inches * 96).toFixed(0) + 'px';
                    if (item.prop === 'textIndent') block.style.textIndent = value;
                    else if (item.prop === 'marginLeft') block.style.marginLeft = value;
                    else block.style.marginRight = Math.max(0, (rect.width - px) / rect.width * 6 * 96).toFixed(0) + 'px';
                    if (item.prop === 'marginRight') item.el.style.left = px + 'px';
                    else item.el.style.left = px + 'px';
                };
                const up = function () { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); };
                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', up);
            });
        });
    }
});
</script>

<script>
// Loading animation: Login / Register / परीक्षा सुरू / निकाल / पेज बदल
(function () {
    const loader = document.getElementById('utmLoader');
    const label = document.getElementById('utmLoaderText');
    window.utmShowLoader = function (msg) {
        if (!loader) return;
        if (msg && label) label.textContent = msg;
        loader.classList.add('show');
    };
    window.utmHideLoader = function () { if (loader) loader.classList.remove('show'); };
    window.addEventListener('load', function () { window.setTimeout(window.utmHideLoader, 300); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) window.utmHideLoader(); });

    const messages = {
        login_student: 'Login होत आहे...',
        register_student: 'Register होत आहे...',
        start_test: 'परीक्षा तयार होत आहे...',
        submit_exam: 'निकाल तपासला जात आहे...',
        select_mode: 'लोड होत आहे...'
    };
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        const f = e.target;
        const a = f && f.querySelector ? f.querySelector('input[name="action"]') : null;
        window.utmShowLoader(a && messages[a.value] ? messages[a.value] : 'कृपया थांबा...');
    });
    document.addEventListener('click', function (e) {
        const a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        const h = a.getAttribute('href') || '';
        if (!h || h.charAt(0) === '#' || a.target === '_blank' || a.hasAttribute('download')) return;
        if (/^(mailto:|tel:|javascript:)/i.test(h)) return;
        if (a.origin && a.origin !== location.origin) return;
        window.utmShowLoader(h.indexOf('admin.php') === 0 ? 'Admin पेज उघडत आहे...' : 'लोड होत आहे...');
    });
})();
</script>

</body>
</html>
