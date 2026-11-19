<?php
declare(strict_types=1);

// index.php प्रमाणेच session cookie settings ठेवा; त्यामुळे login session profile.php मध्येही उपलब्ध राहील.
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

/*
|--------------------------------------------------------------------------
| Universal Typing Master - Student Profile
|--------------------------------------------------------------------------
| Uses the same database and session variables as index.php:
| $_SESSION['user_id'], $_SESSION['user_name']
*/

if (empty($_SESSION['user_id'])) {
    header('Location: index.php?view=landing');
    exit;
}

$db = utmConnect();

if ($db->connect_errno) {
    http_response_code(500);
    exit('Database connection failed. Please check XAMPP MySQL and database settings (database: student).');
}

$db->set_charset('utf8mb4');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$userId = (int)$_SESSION['user_id'];

/* Fetch student details */
$userStmt = $db->prepare(
    'SELECT id, full_name, username, selected_speed, selected_lang, created_at
     FROM users
     WHERE id = ?
     LIMIT 1'
);

if (!$userStmt) {
    http_response_code(500);
    exit('Unable to prepare user query: ' . e($db->error));
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_destroy();
    header('Location: index.php?view=landing');
    exit;
}

/* Password बदला (Student स्वतः) */
$pwMsg = '';
$pwErr = '';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $pwErr = 'Security token चुकीचा आहे. पेज Refresh करा.';
    } else {
        $oldPw = (string)($_POST['old_password'] ?? '');
        $newPw = (string)($_POST['new_password'] ?? '');
        $newPw2 = (string)($_POST['new_password2'] ?? '');
        $ps = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $row = null;
        if ($ps) {
            $ps->bind_param('i', $userId);
            $ps->execute();
            $row = $ps->get_result()->fetch_assoc();
            $ps->close();
        }
        if (!$row || !password_verify($oldPw, (string)$row['password'])) $pwErr = 'जुना Password चुकीचा आहे.';
        elseif (strlen($newPw) < 6) $pwErr = 'नवीन Password किमान 6 अक्षरांचा असावा.';
        elseif ($newPw !== $newPw2) $pwErr = 'नवीन Password दोन्ही ठिकाणी सारखा टाका.';
        else {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $up = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
            if ($up) {
                $up->bind_param('si', $hash, $userId);
                $up->execute();
                $up->close();
                $pwMsg = 'Password यशस्वीपणे बदलला.';
            } else {
                $pwErr = 'Password बदलता आला नाही.';
            }
        }
    }
}

/* Fetch all results for this student */
$resultStmt = $db->prepare(
    'SELECT id, exam_mode, exercise_name, language, marks,
            correct_words, wrong_words, untype_words, cpm,
            gross_wpm, net_wpm, accuracy, time_taken,
            result_status, created_at
     FROM test_results
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC'
);

if (!$resultStmt) {
    http_response_code(500);
    exit('Unable to prepare result query: ' . e($db->error));
}

$resultStmt->bind_param('i', $userId);
$resultStmt->execute();
$resultQuery = $resultStmt->get_result();

$results = [];
while ($row = $resultQuery->fetch_assoc()) {
    $results[] = $row;
}
$resultStmt->close();

/* Calculate summary cards */
$totalTests = count($results);
$totalPass = 0;
$totalFail = 0;
$totalCorrect = 0;
$totalWrong = 0;
$totalUntyped = 0;
$bestNetWpm = 0.0;
$bestAccuracy = 0.0;
$marksTotal = 0.0;

foreach ($results as $row) {
    if ($row['result_status'] === 'Pass') {
        $totalPass++;
    } else {
        $totalFail++;
    }

    $totalCorrect += (int)$row['correct_words'];
    $totalWrong += (int)$row['wrong_words'];
    $totalUntyped += (int)$row['untype_words'];
    $marksTotal += (float)$row['marks'];
    $bestNetWpm = max($bestNetWpm, (float)$row['net_wpm']);
    $bestAccuracy = max($bestAccuracy, (float)$row['accuracy']);
}

$averageMarks = $totalTests > 0 ? round($marksTotal / $totalTests, 2) : 0;

/* Prepare last 10 tests for graph in chronological order */
$chartRows = array_slice($results, 0, 10);
$chartRows = array_reverse($chartRows);

$chartLabels = [];
$chartWpm = [];
$chartAccuracy = [];

foreach ($chartRows as $index => $row) {
    $chartLabels[] = 'Test ' . ($index + 1);
    $chartWpm[] = (float)$row['net_wpm'];
    $chartAccuracy[] = (float)$row['accuracy'];
}

$chartLabelsJson = json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$chartWpmJson = json_encode($chartWpm, JSON_NUMERIC_CHECK);
$chartAccuracyJson = json_encode($chartAccuracy, JSON_NUMERIC_CHECK);

$db->close();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Universal Typing Master</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f1f5f9;color:#172033;font-family:Arial,"Noto Sans Devanagari",sans-serif}
        a{text-decoration:none}
        .profile-top{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:15px 4%;background:#082f49;color:#fff}
        .profile-top h1{margin:0;font-size:21px}
        .top-actions{display:flex;gap:9px;flex-wrap:wrap}
        .top-actions a{padding:9px 13px;border-radius:7px;background:#fff;color:#075985;font-size:13px;font-weight:700}
        .top-actions a.logout{background:#fee2e2;color:#b91c1c}
        .container{width:min(1250px,94%);margin:25px auto}
        .welcome{padding:22px 24px;margin-bottom:18px;border-radius:13px;background:linear-gradient(120deg,#075985,#0e7490);color:#fff;box-shadow:0 7px 20px rgba(15,23,42,.1)}
        .welcome h2{margin:0 0 7px;font-size:24px}
        .welcome p{margin:0;line-height:1.6;color:#e0f2fe}
        .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
        .card{padding:18px;border:1px solid #e2e8f0;border-radius:11px;background:#fff;box-shadow:0 3px 12px rgba(15,23,42,.04)}
        .card .label{font-size:13px;color:#64748b;font-weight:700}
        .card .value{margin-top:8px;font-size:27px;font-weight:800;color:#0f172a}
        .card .hint{margin-top:5px;color:#64748b;font-size:12px}
        .panel{padding:20px;margin-bottom:18px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(15,23,42,.04)}
        .panel h3{margin:0 0 16px;color:#075985;font-size:18px}
        .details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
        .detail{padding:12px;border-radius:8px;background:#f8fafc}
        .detail small{display:block;margin-bottom:5px;color:#64748b}
        .detail strong{overflow-wrap:anywhere}
        .chart-wrap{position:relative;width:100%;height:300px}
        canvas{width:100%;height:100%;display:block}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:900px}
        th,td{padding:11px 10px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:13px;white-space:nowrap}
        th{background:#f1f5f9;color:#334155}
        tbody tr:hover{background:#f8fafc}
        .pass{display:inline-block;padding:5px 9px;border-radius:20px;background:#dcfce7;color:#166534;font-weight:800}
        .fail{display:inline-block;padding:5px 9px;border-radius:20px;background:#fee2e2;color:#b91c1c;font-weight:800}
        .empty{text-align:center;padding:35px 15px;color:#64748b;line-height:1.7}
        .empty a{display:inline-block;margin-top:10px;padding:10px 16px;border-radius:7px;background:#075985;color:#fff;font-weight:700}
        .footer{text-align:center;padding:15px;color:#64748b;font-size:12px}
        @media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.details{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:560px){.profile-top{align-items:flex-start;flex-direction:column}.container{width:94%;margin:15px auto}.cards{grid-template-columns:1fr 1fr;gap:9px}.card{padding:13px}.card .value{font-size:23px}.details{grid-template-columns:1fr}.welcome h2{font-size:20px}.panel{padding:14px}.chart-wrap{height:240px}}
    </style>
</head>
<body>

<header class="profile-top">
    <h1>⌨️ Universal Typing Master</h1>
    <nav class="top-actions">
        <a href="index.php?view=landing">← Home</a>
        <a class="logout" href="index.php?logout=1">Logout</a>
    </nav>
</header>

<main class="container">
    <section class="welcome">
        <h2>नमस्कार, <?= e($user['full_name']) ?>! 👋</h2>
        <p>तुमच्या Typing Test ची प्रगती, स्पीड आणि मागील निकाल येथे पाहू शकता.</p>
    </section>

    <section class="cards">
        <div class="card">
            <div class="label">Total Tests</div>
            <div class="value"><?= $totalTests ?></div>
            <div class="hint">एकूण दिलेल्या परीक्षा</div>
        </div>
        <div class="card">
            <div class="label">Passed Tests</div>
            <div class="value" style="color:#15803d"><?= $totalPass ?></div>
            <div class="hint">उत्तीर्ण परीक्षा</div>
        </div>
        <div class="card">
            <div class="label">Best Net WPM</div>
            <div class="value"><?= number_format($bestNetWpm, 2) ?></div>
            <div class="hint">सर्वोत्तम टायपिंग स्पीड</div>
        </div>
        <div class="card">
            <div class="label">Average Marks</div>
            <div class="value"><?= number_format($averageMarks, 2) ?><small style="font-size:14px"> / 40</small></div>
            <div class="hint">सरासरी गुण</div>
        </div>
    </section>

    <section class="panel">
        <h3>👤 Student Information</h3>
        <div class="details">
            <div class="detail"><small>Full Name</small><strong><?= e($user['full_name']) ?></strong></div>
            <div class="detail"><small>Username</small><strong><?= e($user['username']) ?></strong></div>
            <div class="detail"><small>Selected Speed</small><strong><?= (int)$user['selected_speed'] ?> WPM</strong></div>
            <div class="detail"><small>Selected Language</small><strong><?= e($user['selected_lang']) ?></strong></div>
            <div class="detail"><small>Joined Date</small><strong><?= e(date('d-m-Y', strtotime($user['created_at']))) ?></strong></div>
            <div class="detail"><small>Best Accuracy</small><strong><?= number_format($bestAccuracy, 2) ?>%</strong></div>
        </div>
    </section>

    <section class="panel">
        <h3>🔒 Password बदला</h3>
        <?php if ($pwMsg !== ''): ?><p style="padding:10px;border-radius:7px;background:#dcfce7;color:#166534"><?= e($pwMsg) ?></p><?php endif; ?>
        <?php if ($pwErr !== ''): ?><p style="padding:10px;border-radius:7px;background:#fee2e2;color:#b91c1c"><?= e($pwErr) ?></p><?php endif; ?>
        <form method="POST" action="profile.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;align-items:end">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="change_password">
            <label>जुना Password<br><input type="password" name="old_password" required style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px"></label>
            <label>नवीन Password (किमान 6)<br><input type="password" name="new_password" required minlength="6" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px"></label>
            <label>नवीन Password पुन्हा<br><input type="password" name="new_password2" required minlength="6" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px"></label>
            <button type="submit" style="padding:10px;border:0;border-radius:6px;background:#075985;color:#fff;font-weight:700;cursor:pointer">Password बदला</button>
        </form>
    </section>

    <section class="panel" id="performanceGraph">
        <h3>📈 Typing Performance Graph (Last 10 Tests)</h3>
        <?php if ($totalTests > 0): ?>
            <div class="chart-wrap">
                <canvas id="performanceChart" aria-label="Net WPM and accuracy graph"></canvas>
            </div>
        <?php else: ?>
            <div class="empty">अजून कोणताही Test Result उपलब्ध नाही.<br>पहिली परीक्षा दिल्यानंतर येथे Graph दिसेल.</div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h3>📊 Overall Performance</h3>
        <div class="cards" style="margin:0">
            <div class="card"><div class="label">Correct Words</div><div class="value" style="color:#15803d"><?= $totalCorrect ?></div></div>
            <div class="card"><div class="label">Wrong Words</div><div class="value" style="color:#dc2626"><?= $totalWrong ?></div></div>
            <div class="card"><div class="label">Untyped Words</div><div class="value" style="color:#d97706"><?= $totalUntyped ?></div></div>
            <div class="card"><div class="label">Failed Tests</div><div class="value"><?= $totalFail ?></div></div>
        </div>
    </section>

    <section class="panel">
        <h3>📝 Test History</h3>
        <?php if ($totalTests > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Sr. No.</th>
                            <th>Date & Time</th>
                            <th>Exam Mode</th>
                            <th>Exercise</th>
                            <th>Language</th>
                            <th>Marks / 40</th>
                            <th>Correct</th>
                            <th>Wrong</th>
                            <th>Untyped</th>
                            <th>Net WPM</th>
                            <th>Accuracy</th>
                            <th>Time (sec)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $index => $row): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= e(date('d-m-Y h:i A', strtotime($row['created_at']))) ?></td>
                            <td><?= e($row['exam_mode']) ?></td>
                            <td><?= e($row['exercise_name']) ?></td>
                            <td><?= e($row['language']) ?></td>
                            <td><strong><?= number_format((float)$row['marks'], 2) ?></strong></td>
                            <td><?= (int)$row['correct_words'] ?></td>
                            <td><?= (int)$row['wrong_words'] ?></td>
                            <td><?= (int)$row['untype_words'] ?></td>
                            <td><?= number_format((float)$row['net_wpm'], 2) ?></td>
                            <td><?= number_format((float)$row['accuracy'], 2) ?>%</td>
                            <td><?= (int)$row['time_taken'] ?></td>
                            <td>
                                <span class="<?= $row['result_status'] === 'Pass' ? 'pass' : 'fail' ?>">
                                    <?= e($row['result_status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">
                तुमचा पहिला Typing Test अजून झालेला नाही.<br>
                Test पूर्ण केल्यानंतर तुमचे सर्व Results येथे सेव्ह होतील.
                <br><a href="index.php?view=landing">Start Typing Test</a>
            </div>
        <?php endif; ?>
    </section>
</main>

<footer class="footer">Universal Typing Master • Student Performance Dashboard</footer>

<?php if ($totalTests > 0): ?>
<script>
(function () {
    const labels = <?= $chartLabelsJson ?: '[]' ?>;
    const wpm = <?= $chartWpmJson ?: '[]' ?>;
    const accuracy = <?= $chartAccuracyJson ?: '[]' ?>;
    const canvas = document.getElementById('performanceChart');

    if (!canvas || !labels.length) return;

    const ctx = canvas.getContext('2d');

    function drawChart() {
        const rect = canvas.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(300, rect.width * ratio);
        canvas.height = Math.max(180, rect.height * ratio);
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

        const width = rect.width;
        const height = rect.height;
        const pad = { left: 45, right: 45, top: 22, bottom: 38 };
        const plotW = width - pad.left - pad.right;
        const plotH = height - pad.top - pad.bottom;
        const maxWpm = Math.max(10, Math.ceil(Math.max(...wpm, 0) / 10) * 10);
        const x = i => pad.left + (labels.length === 1 ? plotW / 2 : (i * plotW / (labels.length - 1)));
        const yWpm = v => pad.top + plotH - (v / maxWpm) * plotH;
        const yAcc = v => pad.top + plotH - (Math.max(0, Math.min(100, v)) / 100) * plotH;

        ctx.clearRect(0, 0, width, height);
        ctx.font = '11px Arial';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';

        for (let tick = 0; tick <= 4; tick++) {
            const value = maxWpm * tick / 4;
            const yy = yWpm(value);
            ctx.beginPath();
            ctx.moveTo(pad.left, yy);
            ctx.lineTo(width - pad.right, yy);
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.fillStyle = '#64748b';
            ctx.fillText(value.toFixed(0), pad.left - 8, yy);
        }

        ctx.textAlign = 'left';
        ctx.fillStyle = '#64748b';
        ctx.fillText('WPM', 5, 11);
        ctx.textAlign = 'right';
        ctx.fillText('Accuracy %', width - 2, 11);

        function drawLine(values, yFn, stroke, fill) {
            if (!values.length) return;
            ctx.beginPath();
            values.forEach((value, i) => {
                const px = x(i), py = yFn(value);
                if (i === 0) ctx.moveTo(px, py);
                else ctx.lineTo(px, py);
            });
            ctx.strokeStyle = stroke;
            ctx.lineWidth = 2.5;
            ctx.stroke();

            values.forEach((value, i) => {
                const px = x(i), py = yFn(value);
                ctx.beginPath();
                ctx.arc(px, py, 4, 0, Math.PI * 2);
                ctx.fillStyle = fill;
                ctx.fill();
            });
        }

        drawLine(wpm, yWpm, '#0284c7', '#0284c7');
        drawLine(accuracy, yAcc, '#16a34a', '#16a34a');

        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        ctx.fillStyle = '#475569';
        labels.forEach((label, i) => {
            ctx.fillText(label, x(i), height - pad.bottom + 10);
        });

        const legendY = height - 8;
        ctx.textAlign = 'left';
        ctx.fillStyle = '#0284c7';
        ctx.fillRect(Math.max(10, width / 2 - 75), legendY - 7, 10, 3);
        ctx.fillStyle = '#475569';
        ctx.fillText('Net WPM', Math.max(24, width / 2 - 60), legendY - 12);
        ctx.fillStyle = '#16a34a';
        ctx.fillRect(width / 2 + 20, legendY - 7, 10, 3);
        ctx.fillStyle = '#475569';
        ctx.fillText('Accuracy', width / 2 + 34, legendY - 12);
    }

    drawChart();
    window.addEventListener('resize', drawChart);
})();
</script>
<?php endif; ?>

</body>
</html>
