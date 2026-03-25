<?php
declare(strict_types=1);

require __DIR__ . '/../_inc/db.php';

header('Content-Type: application/json; charset=utf-8');

// =====================
// HIER SETZEN!
// =====================
const API_KEY = 'Marcel123!';  // <-- [ÄNDERN]
const ALLOW_AUTO_CREATE_LOCATION = true;      // true = Location wird angelegt, wenn nicht vorhanden

function respond(int $code, array $payload): never {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, ['ok'=>false, 'error'=>'POST required']);
}

$apiKey = (string)($_POST['api_key'] ?? '');
$tagUid = trim((string)($_POST['tag_uid'] ?? ''));
$loc    = trim((string)($_POST['location'] ?? ''));

if ($apiKey === '' || !hash_equals(API_KEY, $apiKey)) {
  respond(401, ['ok'=>false, 'error'=>'unauthorized']);
}

if ($tagUid === '' || $loc === '') {
  respond(400, ['ok'=>false, 'error'=>'tag_uid and location required']);
}

// Minimal normalize
$tagUid = mb_strtoupper($tagUid);
$loc    = mb_strtoupper($loc);

// Optional: wenn du willst, erzwingen wir dein DEC-Format: "74,11,..." (5 bytes)
// Lass es drin, weil du genau so liest.
if (!preg_match('/^\d{1,3}(,\d{1,3}){4}$/', $tagUid)) {
  respond(400, ['ok'=>false, 'error'=>'invalid tag_uid format. expected: 74,11,23,99,0']);
}

try {
  $pdo->beginTransaction();

  // location_id holen oder anlegen
  $stmt = $pdo->prepare("SELECT location_id FROM locations WHERE location_name = :n LIMIT 1");
  $stmt->execute([':n'=>$loc]);
  $locationId = (int)($stmt->fetchColumn() ?: 0);

  if ($locationId <= 0) {
    if (!ALLOW_AUTO_CREATE_LOCATION) {
      $pdo->rollBack();
      respond(404, ['ok'=>false, 'error'=>'unknown location']);
    }
    $stmt = $pdo->prepare("INSERT INTO locations (location_name) VALUES (:n)");
    $stmt->execute([':n'=>$loc]);
    $locationId = (int)$pdo->lastInsertId();
  }

  // Tag finden
  $stmt = $pdo->prepare("
    SELECT tag_id, person_id
    FROM tags
    WHERE tag_uid = :u
    LIMIT 1
  ");
  $stmt->execute([':u'=>$tagUid]);
  $row = $stmt->fetch();

  if (!$row) {
    $pdo->rollBack();
    respond(404, ['ok'=>false, 'error'=>'unknown tag_uid (not in DB)']);
  }

  $tagId    = (int)$row['tag_id'];
  $personId = (int)($row['person_id'] ?? 0);

  if ($personId <= 0) {
    $pdo->rollBack();
    respond(409, ['ok'=>false, 'error'=>'tag not assigned to a person yet']);
  }

  // Passage schreiben
  $stmt = $pdo->prepare("
    INSERT INTO passages (person_id, tag_id, location_id)
    VALUES (:p, :t, :l)
  ");
  $stmt->execute([':p'=>$personId, ':t'=>$tagId, ':l'=>$locationId]);

  $passageId = (int)$pdo->lastInsertId();
  $pdo->commit();

  respond(200, [
    'ok'=>true,
    'passage_id'=>$passageId,
    'tag_uid'=>$tagUid,
    'location'=>$loc
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('SCAN API ERROR: '.$e->getMessage());
  respond(500, ['ok'=>false, 'error'=>'server error']);
}