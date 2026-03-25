<?php
declare(strict_types=1);

require __DIR__ . '/_inc/db.php';
require __DIR__ . '/_inc/auth.php';
require __DIR__ . '/_inc/csrf.php';

$error = null;

/**
 * Erwartung: login.php hat gesetzt:
 * $_SESSION['2fa_pending_user_id'] = user_id;
 */
$pendingId = (int)($_SESSION['2fa_pending_user_id'] ?? 0);
if ($pendingId <= 0) {
  header('Location: /login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_validate($_POST['csrf_token'] ?? null);

    $code = trim((string)($_POST['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
      throw new RuntimeException('Bitte 6-stelligen Code eingeben.');
    }

    $stmt = $pdo->prepare("
      SELECT user_id, username, twofa_code_hash, twofa_code_expires_at, twofa_attempts
      FROM users
      WHERE user_id = ?
      LIMIT 1
    ");
    $stmt->execute([$pendingId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
      throw new RuntimeException('User nicht gefunden.');
    }

    if ((int)$u['twofa_attempts'] >= 5) {
      throw new RuntimeException('Zu viele Versuche. Bitte neuen Code anfordern.');
    }

    $expStr = (string)($u['twofa_code_expires_at'] ?? '');
    $exp = $expStr ? new DateTimeImmutable($expStr, new DateTimeZone('UTC')) : new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if ($now > $exp) {
      throw new RuntimeException('Code abgelaufen. Bitte neu einloggen, um einen neuen Code zu erhalten.');
    }

    $hash = (string)($u['twofa_code_hash'] ?? '');
    if ($hash === '' || !password_verify($code, $hash)) {
      $pdo->prepare("UPDATE users SET twofa_attempts = twofa_attempts + 1 WHERE user_id = ?")
          ->execute([$pendingId]);
      $error = 'Code falsch.';
    } else {
      // Code verbrauchen
      $pdo->prepare("
        UPDATE users
        SET twofa_code_hash = NULL,
            twofa_code_expires_at = NULL,
            twofa_attempts = 0
        WHERE user_id = ?
      ")->execute([$pendingId]);

      // Wichtig: echte Login-Session so setzen wie dein System es erwartet
      session_regenerate_id(true);
      login_user((int)$u['user_id'], (string)$u['username']);

      unset($_SESSION['2fa_pending_user_id']);

      header('Location: /index.php');
      exit;
    }
  } catch (Throwable $e) {
    $error = 'Fehler: ' . $e->getMessage();
  }
}

// HTTPS Badge wie im Login
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>2FA · RFID Verwaltung</title>
<style>
  :root{
    --bg0:#050712;
    --bg1:#0a0f22;
    --stroke:rgba(255,255,255,.12);
    --text:rgba(255,255,255,.92);
    --muted:rgba(255,255,255,.64);

    --accent:#a78bfa;
    --accent2:#7c3aed;
    --accentGlow: rgba(167,139,250,.40);

    --warn:#fbbf24;
    --r:18px;
    --focus: 0 0 0 4px rgba(167,139,250,.20);
    --shadow: 0 18px 70px rgba(0,0,0,.55);
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
    min-height:100vh;
    display:grid;
    place-items:center;
    color:var(--text);
    background:
      radial-gradient(1200px 700px at 20% -10%, rgba(167,139,250,.14), transparent 60%),
      radial-gradient(900px 600px at 115% 0%, rgba(124,58,237,.12), transparent 55%),
      linear-gradient(180deg, var(--bg0), var(--bg1));
    padding:18px;
  }
  .card{
    width:min(460px, 94vw);
    background:linear-gradient(180deg, rgba(255,255,255,.075), rgba(255,255,255,.05));
    border:1px solid var(--stroke);
    border-radius:var(--r);
    padding:18px;
    box-shadow:var(--shadow);
  }
  .top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
  }
  .brand{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .brand-logo{
    width:30px;
    height:30px;
    object-fit:contain;
    border-radius:10px;
    border:1px solid rgba(255,255,255,.10);
    background: rgba(255,255,255,.04);
    box-shadow: 0 0 22px rgba(167,139,250,.14);
  }
  h1{
    margin:0;
    font-size:18px;
    font-weight:980;
    letter-spacing:.2px;
  }
  .sub{
    margin-top:6px;
    color:var(--muted);
    font-size:13px;
    font-weight:700;
    line-height:1.35;
  }
  .badge{
    display:inline-flex;
    align-items:center;
    padding:7px 10px;
    border-radius:999px;
    border:1px solid var(--stroke);
    background:rgba(255,255,255,.04);
    color:rgba(255,255,255,.78);
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
  }
  label{
    display:block;
    margin-top:12px;
    color:rgba(255,255,255,.72);
    font-weight:950;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.35px
  }
  input{
    width:100%;
    padding:12px 12px;
    margin-top:7px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(8,12,26,.58);
    color:var(--text);
    outline:none;
    transition: box-shadow .15s ease, border-color .15s ease, background .15s ease, transform .06s ease;
  }
  input::placeholder{ color: rgba(255,255,255,.40) }
  input:focus{
    border-color:rgba(167,139,250,.52);
    box-shadow:var(--focus);
    background:rgba(8,12,26,.78);
  }
  button{
    margin-top:14px;
    width:100%;
    padding:12px;
    border:0;
    border-radius:14px;
    font-weight:980;
    cursor:pointer;
    background:linear-gradient(135deg, var(--accent2), var(--accent));
    color:#090516;
    transition: transform .06s ease, opacity .15s ease, box-shadow .18s ease;
    box-shadow: 0 10px 30px rgba(167,139,250,.18);
  }
  button:hover{
    opacity:.96;
    box-shadow: 0 0 0 4px rgba(167,139,250,.14), 0 0 34px rgba(167,139,250,.30);
  }
  button:active{ transform: translateY(1px); }
  .err{
    margin-top:12px;
    padding:12px 12px;
    border-radius:14px;
    background:rgba(251,191,36,.10);
    border:1px solid rgba(251,191,36,.28);
    color:var(--warn);
    font-weight:900;
    line-height:1.35;
  }
  .foot{
    margin-top:12px;
    color:rgba(255,255,255,.55);
    font-size:12px;
    font-weight:650;
    line-height:1.35;
  }
</style>
</head>
<body>
  <div class="card">
    <div class="top">
      <div>
        <div class="brand">
          <img class="brand-logo" src="/RFID-Gate.png" alt="RFID Gate">
          <h1>RFID Verwaltung</h1>
        </div>
        <div class="sub">2FA Code eingeben, um den Login abzuschließen.</div>
      </div>
      <div class="badge"><?= $https ? 'HTTPS' : 'HTTP' ?></div>
    </div>

    <?php if ($error): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

      <label>6-stelliger Code</label>
      <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="123456">

      <button type="submit">Bestätigen</button>
    </form>

    <div class="foot">
      Hinweis: Der Code ist 10 Minuten gültig. Nach 5 falschen Versuchen musst du neu einloggen.
    </div>
  </div>
</body>
</html>
