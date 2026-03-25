<?php
declare(strict_types=1);

require __DIR__ . '/_inc/auth.php';
require_login();

require __DIR__ . '/_inc/db.php';
require __DIR__ . '/_inc/csrf.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

$msg = null;
$msg_type = 'success';

function redirect_with(array $params): never {
  header('Location: ?' . http_build_query($params));
  exit;
}

$view  = ($_GET['view'] ?? 'form') === 'passages' ? 'passages' : 'form';
$saved = isset($_GET['saved']);

if (!$msg && isset($_GET['m'])) {
  $msg = (string)$_GET['m'];
  $msg_type = (string)($_GET['t'] ?? 'success');
}

$username = (string)($_SESSION['username'] ?? 'user');

/* =========================
   POST
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_validate($_POST['csrf_token'] ?? null);

    // DELETE passage
    if (isset($_POST['delete_passage'])) {
      $pid = (int)($_POST['passage_id'] ?? 0);
      if ($pid <= 0) throw new RuntimeException('Ungültige Passage-ID.');

      $stmt = $pdo->prepare('DELETE FROM passages WHERE passage_id = :id');
      $stmt->execute([':id' => $pid]);

      redirect_with(['view'=>'passages','m'=>'Passage gelöscht.','t'=>'success']);
    }

    // SAVE person + ASSIGN tag to person (NO passage insert here!)
    if (isset($_POST['save_person'])) {
      $name        = trim((string)($_POST['name'] ?? ''));
      $email       = mb_strtolower(trim((string)($_POST['email'] ?? '')));
      $tag_id      = (int)($_POST['tag_id'] ?? 0);
      $location_id = (int)($_POST['location_id'] ?? 0); // bleibt fürs UI, wird NICHT in passages gespeichert

      if ($name === '' || $email === '' || $tag_id <= 0) {
        throw new RuntimeException('Bitte Name, Email und Tag auswählen.');
      }

      $pdo->beginTransaction();

      // Upsert person by UNIQUE(email)
      $stmt = $pdo->prepare("
        INSERT INTO persons (person_name, email)
        VALUES (:n, :e)
        ON DUPLICATE KEY UPDATE person_name = VALUES(person_name)
      ");
      $stmt->execute([':n'=>$name, ':e'=>$email]);

      // Robust person_id resolve:
      $person_id = (int)$pdo->lastInsertId();
      if ($person_id === 0) {
        $stmt = $pdo->prepare("SELECT person_id FROM persons WHERE email = :e LIMIT 1");
        $stmt->execute([':e'=>$email]);
        $person_id = (int)($stmt->fetchColumn() ?: 0);
      }
      if ($person_id <= 0) {
        throw new RuntimeException('Person-ID konnte nicht ermittelt werden.');
      }

      // Tag existiert?
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM tags WHERE tag_id = :t");
      $stmt->execute([':t'=>$tag_id]);
      if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Ungültiger Tag.');
      }

      // OPTIONAL: Location existiert? (nur damit dein UI nicht Müll auswählt)
      if ($location_id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE location_id = :l");
        $stmt->execute([':l'=>$location_id]);
        if ((int)$stmt->fetchColumn() !== 1) {
          throw new RuntimeException('Ungültige Location.');
        }
      }

      // Assign tag -> person (requires tags.person_id column!)
      $stmt = $pdo->prepare("
        UPDATE tags
        SET person_id = :p
        WHERE tag_id = :t
      ");
      $stmt->execute([':p'=>$person_id, ':t'=>$tag_id]);

      // Wenn rowCount=0 kann es sein, dass es eh schon zugewiesen ist -> OK.
      // Aber falls tag_id nicht existiert, hätten wir oben schon abgebrochen.

      $pdo->commit();
      redirect_with(['view'=>'passages','saved'=>1,'m'=>'Person gespeichert & Tag zugewiesen.','t'=>'success']);
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('INDEX ERROR: ' . $e->getMessage());
    $msg = 'Fehler: ' . $e->getMessage();
    $msg_type = 'warning';
  }
}

/* =========================
   UI data
   ========================= */

try {
  $tags = $pdo->query("
    SELECT
      t.tag_id,
      TRIM(t.tag_uid) AS tag_uid,
      t.person_id,
      p.person_name,
      p.email
    FROM tags t
    LEFT JOIN persons p ON p.person_id = t.person_id
    ORDER BY TRIM(t.tag_uid)
  ")->fetchAll();
} catch (Throwable $e) { error_log('LOAD TAGS: '.$e->getMessage()); $tags=[]; }

try {
  $locations = $pdo->query("
    SELECT MIN(location_id) AS location_id, TRIM(location_name) AS location_name
    FROM locations
    GROUP BY TRIM(location_name)
    ORDER BY TRIM(location_name)
  ")->fetchAll();
} catch (Throwable $e) { error_log('LOAD LOC: '.$e->getMessage()); $locations=[]; }

$passages = [];
if ($view === 'passages' || $saved) {
  try {
    $passages = $pdo->query("
      SELECT
        ps.passage_id,
        ps.passage_time,
        p.person_name,
        p.email,
        t.tag_uid,
        l.location_name
      FROM passages ps
      JOIN persons   p ON p.person_id   = ps.person_id
      JOIN tags      t ON t.tag_id      = ps.tag_id
      JOIN locations l ON l.location_id = ps.location_id
      ORDER BY ps.passage_time DESC, ps.passage_id DESC
      LIMIT 200
    ")->fetchAll();
  } catch (Throwable $e) { error_log('LOAD PASSAGES: '.$e->getMessage()); $passages=[]; }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>RFID Verwaltung</title>

<style>
  :root{
    --bg0:#050712;
    --bg1:#0a0f22;

    --card:rgba(255,255,255,.06);
    --stroke:rgba(255,255,255,.12);

    --text:rgba(255,255,255,.92);
    --muted:rgba(255,255,255,.64);

    --ok:#34d399;
    --warn:#fbbf24;
    --danger:#fb7185;

    --accent:#a78bfa;
    --accent2:#7c3aed;
    --accentGlow: rgba(167,139,250,.38);

    --r:18px;
    --r2:14px;

    --shadow2: 0 12px 34px rgba(0,0,0,.40);

    --focus: 0 0 0 4px rgba(167,139,250,.20);
  }

  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0;
    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
    color:var(--text);
    background:
      radial-gradient(1200px 700px at 20% -10%, rgba(167,139,250,.14), transparent 60%),
      radial-gradient(900px 600px at 115% 0%, rgba(124,58,237,.12), transparent 55%),
      radial-gradient(700px 420px at 40% 110%, rgba(251,191,36,.06), transparent 60%),
      linear-gradient(180deg, var(--bg0), var(--bg1));
  }

  header{
    position:sticky; top:0; z-index:10;
    background:rgba(5,7,18,.72);
    backdrop-filter: blur(12px);
    border-bottom:1px solid var(--stroke);
  }
  .header-inner{
    max-width:1120px;
    margin:0 auto;
    padding:14px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
  }

  .brand{
    display:flex;
    align-items:center;
    gap:10px;
    min-width: 0;
  }
  .brand-logo{
    width:28px;
    height:28px;
    object-fit:contain;
    border-radius:10px;
    border:1px solid rgba(255,255,255,.10);
    background: rgba(255,255,255,.04);
    box-shadow: 0 0 22px rgba(167,139,250,.14);
  }
  .brand-title{
    margin:0;
    font-size:13px;
    letter-spacing:.45px;
    text-transform:uppercase;
    color:rgba(255,255,255,.84);
    font-weight:950;
    white-space:nowrap;
  }

  .right{
    display:flex; align-items:center; gap:10px;
    font-size:13px;
    color:var(--muted);
    font-weight:800;
  }
  .pill{
    display:inline-flex; align-items:center; gap:8px;
    padding:8px 10px;
    border:1px solid var(--stroke);
    border-radius:999px;
    background:rgba(255,255,255,.04);
  }
  header a{
    color:var(--accent);
    text-decoration:none;
    font-weight:950;
  }
  header a:hover{
    text-decoration:underline;
    text-shadow: 0 0 18px var(--accentGlow);
  }

  .wrap{max-width:1120px;margin:22px auto;padding:0 16px}

  .tabs{display:flex; gap:10px; margin:14px 0 16px 0; flex-wrap:wrap}
  .tab{
    border:1px solid var(--stroke);
    background:rgba(255,255,255,.03);
    color:var(--muted);
    padding:10px 12px;
    border-radius:999px;
    cursor:pointer;
    font-weight:950;
    transition: transform .06s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
  }
  .tab:hover{
    background:rgba(167,139,250,.10);
    border-color:rgba(167,139,250,.28);
    box-shadow: 0 0 0 4px rgba(167,139,250,.10), 0 18px 40px rgba(0,0,0,.20);
  }
  .tab:active{transform:translateY(1px)}
  .tab.active{
    background:rgba(167,139,250,.16);
    color:rgba(255,255,255,.92);
    border-color:rgba(167,139,250,.42);
    box-shadow: 0 0 22px rgba(167,139,250,.18);
  }

  .msg{
    margin:12px 0 16px;
    padding:12px 12px;
    border-radius:14px;
    border:1px solid var(--stroke);
    font-weight:900;
    background:rgba(255,255,255,.04);
  }
  .success{background:rgba(52,211,153,.10); color:var(--ok); border-color:rgba(52,211,153,.28)}
  .warning{background:rgba(251,191,36,.10); color:var(--warn); border-color:rgba(251,191,36,.28)}

  .card{
    background:linear-gradient(180deg, rgba(255,255,255,.075), rgba(255,255,255,.05));
    border:1px solid var(--stroke);
    border-radius:var(--r);
    box-shadow:var(--shadow2);
    overflow:hidden;
  }
  .card-pad{padding:16px}
  .card-head{
    padding:16px;
    border-bottom:1px solid var(--stroke);
    background:rgba(0,0,0,.14);
  }
  .card-head h2{
    margin:0;
    font-size:16px;
    font-weight:980;
    letter-spacing:.2px;
  }
  .small{
    font-size:12.5px;
    color:var(--muted);
    font-weight:700;
    margin-top:6px;
    line-height:1.35;
  }

  label{
    display:block;
    margin-top:12px;
    color:rgba(255,255,255,.72);
    font-weight:950;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.35px;
  }
  input,select{
    width:100%;
    padding:12px 12px;
    margin-top:7px;
    border-radius:var(--r2);
    border:1px solid rgba(255,255,255,.14);
    background:rgba(8,12,26,.58);
    color:var(--text);
    outline:none;
    transition: box-shadow .15s ease, border-color .15s ease, background .15s ease, transform .06s ease;
  }
  input::placeholder{ color: rgba(255,255,255,.40) }
  input:focus, select:focus{
    border-color:rgba(167,139,250,.52);
    box-shadow:var(--focus);
    background:rgba(8,12,26,.78);
  }

  .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media(max-width:900px){.row{grid-template-columns:1fr}}

  .actions{display:flex; gap:10px; justify-content:flex-end; margin-top:14px; flex-wrap:wrap}
  .btn{
    border:1px solid rgba(255,255,255,.14);
    background:rgba(255,255,255,.06);
    color:var(--text);
    padding:10px 12px;
    border-radius:14px;
    cursor:pointer;
    font-weight:980;
    transition: transform .06s ease, opacity .15s ease, background .15s ease, border-color .15s ease, box-shadow .18s ease;
  }
  .btn:hover{
    background:rgba(255,255,255,.09);
    box-shadow: 0 0 0 4px rgba(167,139,250,.10), 0 0 26px rgba(167,139,250,.18);
    border-color: rgba(167,139,250,.28);
  }
  .btn:active{transform:translateY(1px)}
  .btn.primary{
    border:0;
    background:linear-gradient(135deg, var(--accent2), var(--accent));
    color:#080515;
    box-shadow: 0 10px 30px rgba(167,139,250,.18);
  }
  .btn.primary:hover{
    opacity:.96;
    box-shadow: 0 0 0 4px rgba(167,139,250,.14), 0 0 34px rgba(167,139,250,.28);
  }
  .btn.danger{
    border:1px solid rgba(251,113,133,.45);
    background:rgba(251,113,133,.16);
    color:rgba(255,255,255,.92);
  }
  .btn.danger:hover{
    background:rgba(251,113,133,.22);
    box-shadow: 0 0 0 4px rgba(251,113,133,.12), 0 0 28px rgba(251,113,133,.18);
  }

  .table-wrap{
    overflow:auto;
    border-top:1px solid var(--stroke);
  }
  table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    min-width:880px;
  }
  thead th{
    position:sticky; top:0;
    background:rgba(0,0,0,.24);
    backdrop-filter: blur(10px);
    font-size:11px;
    color:rgba(255,255,255,.62);
    text-transform:uppercase;
    letter-spacing:.35px;
    text-align:left;
    padding:12px;
    border-bottom:1px solid rgba(255,255,255,.10);
  }
  tbody td{
    padding:12px;
    border-bottom:1px solid rgba(255,255,255,.08);
    color:rgba(255,255,255,.90);
    white-space:nowrap;
  }
  tbody tr:hover td{background:rgba(167,139,250,.045)}
  .td-right{text-align:right}
  .empty{
    text-align:center;
    padding:18px;
    color:rgba(255,255,255,.60);
  }
  .tag-pill{
    display:inline-flex;
    align-items:center;
    padding:6px 9px;
    border-radius:999px;
    border:1px solid rgba(167,139,250,.26);
    background:rgba(167,139,250,.10);
    color:rgba(255,255,255,.92);
    font-weight:900;
    font-size:12px;
    letter-spacing:.2px;
  }
</style>

<script>
  function showSection(id){
    document.getElementById('section-form').style.display = (id==='form') ? 'block':'none';
    document.getElementById('section-passages').style.display = (id==='passages') ? 'block':'none';
    document.getElementById('tab-form').classList.toggle('active', id==='form');
    document.getElementById('tab-passages').classList.toggle('active', id==='passages');
    const url = new URL(window.location.href);
    url.searchParams.set('view', id);
    window.history.replaceState({}, '', url);
  }
  document.addEventListener('DOMContentLoaded',()=>{
    const initial = '<?= ($view==='passages' || $saved) ? 'passages' : 'form' ?>';
    showSection(initial);
  });
</script>
</head>

<body>
<header>
  <div class="header-inner">
    <div class="brand">
      <img class="brand-logo" src="/RFID-Gate.png" alt="RFID Gate">
      <h1 class="brand-title">RFID Verwaltung</h1>
    </div>

    <div class="right">
      <span class="pill"><?= htmlspecialchars($username) ?></span>
      <a href="/logout.php">Logout</a>
    </div>
  </div>
</header>

<div class="wrap">

  <div class="tabs">
    <button id="tab-form" class="tab" type="button" onclick="showSection('form')">Person + Tag zuweisen</button>
    <button id="tab-passages" class="tab" type="button" onclick="showSection('passages')">Passages</button>
  </div>

  <?php if ($msg): ?>
    <div class="msg <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- FORM -->
  <div id="section-form" class="card" style="display:none">
    <div class="card-head">
      <h2>Person speichern & Tag zuweisen</h2>
      <div class="small">Legt Person (falls neu) an und weist den ausgewählten Tag dieser Person zu. Passages entstehen erst beim Scan vom ESP.</div>
    </div>

    <div class="card-pad">
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <div class="row">
          <div>
            <label>Name</label>
            <input type="text" name="name" required placeholder="Max Mustermann">
          </div>
          <div>
            <label>Email</label>
            <input type="email" name="email" required placeholder="max@example.com">
          </div>
        </div>

        <div class="row">
          <div>
            <label>Tag wählen</label>
            <select name="tag_id" required>
              <option value="">-- Tag wählen --</option>
              <?php foreach($tags as $t): ?>
                <?php
                  $label = (string)$t['tag_uid'];
                  if (!empty($t['person_id'])) {
                    $label .= ' (zugewiesen an: ' . (string)($t['person_name'] ?? '') . ')';
                  }
                ?>
                <option value="<?= (int)$t['tag_id'] ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="small">Tipp: In der DB muss tag_uid der echten UID entsprechen (z.B. 10-stellig HEX).</div>
          </div>

          <div>
            <label>Location wählen (nur Anzeige / optional)</label>
            <select name="location_id">
              <option value="">-- optional --</option>
              <?php foreach($locations as $l): ?>
                <option value="<?= (int)$l['location_id'] ?>"><?= htmlspecialchars((string)$l['location_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="small">Die echte Location kommt beim Scan vom ESP (z.B. W119).</div>
          </div>
        </div>

        <div class="actions">
          <button class="btn primary" type="submit" name="save_person">Speichern</button>
        </div>
      </form>
    </div>
  </div>

  <!-- PASSAGES -->
  <div id="section-passages" class="card" style="display:none; margin-top:16px;">
    <div class="card-head">
      <h2>Passages</h2>
      <div class="small">Neueste zuerst · Diese Einträge werden beim RFID-Scan erzeugt.</div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Zeit</th>
            <th>Name</th>
            <th>Email</th>
            <th>Tag</th>
            <th>Location</th>
            <th class="td-right">Aktion</th>
          </tr>
        </thead>

        <tbody>
        <?php if (empty($passages)): ?>
          <tr>
            <td class="empty" colspan="6">Keine Einträge</td>
          </tr>
        <?php else: foreach($passages as $p): ?>
          <tr>
            <td><?= htmlspecialchars((string)$p['passage_time']) ?></td>
            <td><?= htmlspecialchars((string)$p['person_name']) ?></td>
            <td><?= htmlspecialchars((string)$p['email']) ?></td>
            <td><span class="tag-pill"><?= htmlspecialchars((string)$p['tag_uid']) ?></span></td>
            <td><?= htmlspecialchars((string)$p['location_name']) ?></td>
            <td class="td-right">
              <form method="post" style="margin:0" onsubmit="return confirm('Wirklich löschen?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="passage_id" value="<?= (int)$p['passage_id'] ?>">
                <button class="btn danger" type="submit" name="delete_passage">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>