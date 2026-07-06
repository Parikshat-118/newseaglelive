<?php
/**
 * POST /api/quiz-attempt.php
 * Body: { quiz_id: N, answers: ["A","C",...], time_taken_sec: 90 }
 * Login required. Scores answers, stores attempt (once per quiz).
 * Returns { ok, score, total, accuracy, results:[{correct, answer, explanation}] }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ne_json(['ok' => false, 'error' => 'method'], 405);
}

$user = ne_current_user();
if (!$user) {
    ne_json(['ok' => false, 'error' => 'auth'], 401);
}

$body    = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$quizId  = (int)($body['quiz_id']        ?? 0);
$answers = $body['answers']              ?? [];
$timeSec = isset($body['time_taken_sec']) ? (int)$body['time_taken_sec'] : null;

if ($quizId <= 0 || !is_array($answers)) {
    ne_json(['ok' => false, 'error' => 'bad_req'], 400);
}

$db = ne_db();
if (!$db) {
    ne_json(['ok' => false, 'error' => 'db'], 500);
}

try {
    // Load quiz questions
    $stmt = $db->prepare(
        "SELECT id, correct_option, explanation
         FROM quiz_questions
         WHERE quiz_id = :qi ORDER BY order_no"
    );
    $stmt->execute([':qi' => $quizId]);
    $questions = $stmt->fetchAll();

    if (!$questions) {
        ne_json(['ok' => false, 'error' => 'not_found'], 404);
    }

    // Score answers
    $score   = 0;
    $total   = count($questions);
    $results = [];

    foreach ($questions as $i => $q) {
        $given   = isset($answers[$i]) ? strtoupper((string)$answers[$i]) : '';
        $correct = strtoupper($q['correct_option']);
        $ok      = $given === $correct;
        if ($ok) $score++;
        $results[] = [
            'correct'     => $ok,
            'answer'      => $correct,
            'explanation' => $q['explanation'] ?? '',
        ];
    }

    $accuracy = $total > 0 ? round($score / $total * 100, 2) : 0.0;

    // Check if already attempted
    $existing = $db->prepare(
        "SELECT attempt_number FROM web_quiz_attempts WHERE user_id=:u AND quiz_id=:q"
    );
    $existing->execute([':u' => $user['id'], ':q' => $quizId]);
    $prev = $existing->fetch();

    if ($prev) {
        // Already attempted — return cached result without saving again
        ne_json(['ok' => true, 'score' => $score, 'total' => $total,
                 'accuracy' => $accuracy, 'results' => $results]);
    }

    // Store first attempt
    $db->prepare(
        "INSERT INTO web_quiz_attempts
             (user_id, quiz_id, score, total, accuracy, time_taken_sec, attempt_number, answers)
         VALUES (:u, :q, :s, :t, :ac, :ti, 1, :a)"
    )->execute([
        ':u'  => $user['id'],
        ':q'  => $quizId,
        ':s'  => $score,
        ':t'  => $total,
        ':ac' => $accuracy,
        ':ti' => $timeSec,
        ':a'  => json_encode($answers),
    ]);

    ne_json(['ok' => true, 'score' => $score, 'total' => $total,
             'accuracy' => $accuracy, 'results' => $results]);

} catch (Throwable $e) {
    error_log('[quiz-attempt] ' . $e->getMessage());
    ne_json(['ok' => false, 'error' => 'server'], 500);
}
