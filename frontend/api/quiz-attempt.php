<?php
/**
 * POST /api/quiz-attempt.php  { content_id: N, answers: [0,2,1,...] }
 * Login required. Scores against payload, stores attempt (once per quiz).
 * Returns {ok, score, total, results:[{correct, answer, explanation}]}
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ne_json(['ok'=>false,'error'=>'method'], 405);

$user = ne_current_user();
if (!$user) ne_json(['ok'=>false,'error'=>'auth'], 401);

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$cid = (int)($body['content_id'] ?? 0);
$answers = $body['answers'] ?? [];
if ($cid <= 0 || !is_array($answers)) ne_json(['ok'=>false,'error'=>'bad_req'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

try {
    $stmt = $db->prepare("SELECT payload FROM student_content WHERE id = :c AND kind = 'mcq'");
    $stmt->execute([':c'=>$cid]);
    $row = $stmt->fetch();
    if (!$row) ne_json(['ok'=>false,'error'=>'not_found'], 404);

    $payload = json_decode($row['payload'], true) ?: [];
    $questions = $payload['questions'] ?? [];
    $total = count($questions);
    if ($total === 0) ne_json(['ok'=>false,'error'=>'empty'], 500);

    $score = 0; $results = [];
    foreach ($questions as $i => $q) {
        $given = isset($answers[$i]) ? (int)$answers[$i] : -1;
        $correct = (int)($q['answer'] ?? 0);
        $ok = $given === $correct;
        if ($ok) $score++;
        $results[] = ['correct'=>$ok, 'answer'=>$correct, 'explanation'=>$q['explanation'] ?? ''];
    }

    // Store (first attempt counts; ignore duplicates)
    $db->prepare(
        "INSERT IGNORE INTO web_quiz_attempts (user_id, content_id, score, total, answers)
         VALUES (:u, :c, :s, :t, :a)"
    )->execute([':u'=>$user['id'], ':c'=>$cid, ':s'=>$score, ':t'=>$total,
                ':a'=>json_encode($answers)]);

    ne_json(['ok'=>true, 'score'=>$score, 'total'=>$total, 'results'=>$results]);
} catch (Throwable $e) {
    error_log('[quiz-attempt] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
