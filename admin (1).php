<?php

$host = 'localhost';
$user = 'radius';
$pass = 'Naren@123';
$db   = 'radius';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$uploadMsg   = '';
$uploadError = '';
$uploadDebug = '';

function phpUploadError(int $code): string {
    $msgs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini (' . ini_get('upload_max_filesize') . ')',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in the form',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was selected',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk (server permission error)',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload',
    ];
    return $msgs[$code] ?? "Unknown upload error (code $code)";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'upload_ad') {

        if (!isset($_FILES['ad_media']) || $_FILES['ad_media']['error'] === UPLOAD_ERR_NO_FILE) {
            $uploadError = 'No file selected. Please choose an image or video first.';
        } else {
            $file    = $_FILES['ad_media'];
            $allowed = ['image/jpeg','image/png','image/gif','image/webp',
                        'video/mp4','video/webm','video/ogg'];
            $maxSize = 50 * 1024 * 1024; // 50 MB

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadError = phpUploadError($file['error']);
            } elseif ($file['size'] === 0) {
                $uploadError = 'Uploaded file is empty (0 bytes).';
            } elseif (!in_array($file['type'], $allowed)) {
                $uploadError = 'File type "' . htmlspecialchars($file['type']) . '" not allowed. Use JPG/PNG/GIF/WEBP/MP4/WEBM/OGG.';
            } elseif ($file['size'] > $maxSize) {
                $uploadError = 'File too large (' . round($file['size']/1048576,1) . ' MB). Max 50 MB.';
            } else {
                $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $destDir = __DIR__ . '/uploads/ads/';

                if (!is_dir($destDir)) {
                    if (!mkdir($destDir, 0775, true)) {
                        $uploadError = 'Could not create uploads/ads/ directory. Check server permissions.';
                        goto done;
                    }
                }

                if (!is_writable($destDir)) {
                    $uploadError = 'Directory uploads/ads/ is not writable by the web server. '
                                 . 'Run: sudo chown -R www-data:www-data /var/www/html/uploads';
                    goto done;
                }

                foreach (glob($destDir . 'current_ad.*') as $old) {
                    @unlink($old);
                }

                $destFile = $destDir . 'current_ad.' . $ext;

                if (move_uploaded_file($file['tmp_name'], $destFile)) {
                    $manifest = [
                        'filename' => 'current_ad.' . $ext,
                        'mime'     => $file['type'],
                        'original' => $file['name'],
                        'size'     => $file['size'],
                        'updated'  => date('Y-m-d H:i:s'),
                    ];
                    if (file_put_contents($destDir . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT)) === false) {
                        $uploadError = 'File saved but failed to write manifest.json — check permissions on uploads/ads/';
                    } else {
                        $uploadMsg = '✓ Ad media uploaded successfully: ' . htmlspecialchars($file['name']);
                    }
                } else {
                    $uploadError = 'move_uploaded_file() failed. '
                                 . 'Verify PHP has write access to: ' . $destDir;
                }
            }
        }
        done:;
    }

    if ($_POST['action'] === 'clear_ad') {
        $destDir = __DIR__ . '/uploads/ads/';
        foreach (glob($destDir . 'current_ad.*') as $f) @unlink($f);
        @unlink($destDir . 'manifest.json');
        $uploadMsg = 'Ad media removed.';
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $result = $conn->query("SELECT username, value AS phone_or_password FROM radcheck ORDER BY id DESC");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="radius_users_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Username / Phone Number', 'Password']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [$row['username'], $row['phone_or_password']]);
    }
    fclose($out);
    exit;
}

$search = trim($_GET['search'] ?? '');
$sql    = "SELECT id, username, value AS password, attribute, op FROM radcheck";
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $sql .= " WHERE username LIKE '%$safe%'";
}
$sql   .= " ORDER BY id DESC";
$result = $conn->query($sql);
$rows   = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$total  = $conn->query("SELECT COUNT(*) AS c FROM radcheck")->fetch_assoc()['c'];

$adManifest = __DIR__ . '/uploads/ads/manifest.json';
$adInfo     = file_exists($adManifest) ? json_decode(file_get_contents($adManifest), true) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RADIUS Admin Panel</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:       #0a0c10;
    --surface:  #111318;
    --surface2: #181c24;
    --border:   #252a35;
    --accent:   #00e5ff;
    --accent2:  #ff4d6d;
    --accent3:  #b8ff57;
    --text:     #e2e8f0;
    --muted:    #64748b;
    --success:  #22c55e;
    --warning:  #f59e0b;
    --radius:   6px;
    --mono: 'JetBrains Mono', monospace;
    --sans: 'Syne', sans-serif;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--mono);
    min-height: 100vh;
    overflow-x: hidden;
  }

  /* grid noise overlay */
  body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0;
    background-image:
      linear-gradient(rgba(0,229,255,.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(0,229,255,.03) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
  }

  /* ── HEADER ── */
  header {
    position: sticky; top: 0; z-index: 100;
    background: rgba(10,12,16,.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 0 2rem;
    display: flex; align-items: center; justify-content: space-between;
    height: 56px;
  }
  .logo {
    font-family: var(--sans);
    font-weight: 800; font-size: 1.1rem; letter-spacing: .05em;
    color: var(--accent);
    display: flex; align-items: center; gap: .5rem;
  }
  .logo span { color: var(--text); }
  .logo-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent3); animation: pulse 2s infinite; }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }

  .header-meta { font-size: .7rem; color: var(--muted); text-align: right; line-height: 1.5; }
  .header-meta strong { color: var(--accent); }

  /* ── LAYOUT ── */
  .layout { display: grid; grid-template-columns: 240px 1fr; min-height: calc(100vh - 56px); position: relative; z-index: 1; }

  /* ── SIDEBAR ── */
  nav {
    background: var(--surface);
    border-right: 1px solid var(--border);
    padding: 1.5rem 0;
    position: sticky; top: 56px; height: calc(100vh - 56px); overflow-y: auto;
  }
  .nav-section { padding: .25rem 1rem .5rem; font-size: .65rem; letter-spacing: .12em; color: var(--muted); text-transform: uppercase; }
  .nav-item {
    display: flex; align-items: center; gap: .75rem;
    padding: .65rem 1.25rem;
    color: var(--muted);
    cursor: pointer;
    border-left: 2px solid transparent;
    transition: all .15s;
    font-size: .8rem;
    text-decoration: none;
  }
  .nav-item:hover { color: var(--text); background: var(--surface2); border-left-color: var(--border); }
  .nav-item.active { color: var(--accent); background: rgba(0,229,255,.06); border-left-color: var(--accent); }
  .nav-icon { width: 16px; opacity: .7; }

  main { padding: 2rem; display: flex; flex-direction: column; gap: 1.5rem; }

  .stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
  .stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.25rem 1.5rem;
    position: relative; overflow: hidden;
  }
  .stat-card::after {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 2px;
  }
  .stat-card.c1::after { background: var(--accent); }
  .stat-card.c2::after { background: var(--accent2); }
  .stat-card.c3::after { background: var(--accent3); }
  .stat-label { font-size: .7rem; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: .5rem; }
  .stat-value { font-family: var(--sans); font-size: 2rem; font-weight: 800; }
  .stat-value.blue { color: var(--accent); }
  .stat-value.red  { color: var(--accent2); }
  .stat-value.green{ color: var(--accent3); }
  .stat-sub { font-size: .7rem; color: var(--muted); margin-top: .35rem; }

  .panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
  }
  .panel-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
  }
  .panel-title {
    font-family: var(--sans);
    font-weight: 600; font-size: .95rem;
    display: flex; align-items: center; gap: .5rem;
  }
  .panel-title .badge {
    font-family: var(--mono);
    font-size: .65rem; font-weight: 400;
    background: rgba(0,229,255,.12); color: var(--accent);
    padding: .15rem .45rem; border-radius: 3px;
  }

  .controls { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
  .search-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .45rem .85rem;
    color: var(--text);
    font-family: var(--mono); font-size: .8rem;
    width: 220px;
    outline: none;
    transition: border-color .15s;
  }
  .search-box::placeholder { color: var(--muted); }
  .search-box:focus { border-color: var(--accent); }

  .btn {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem 1rem;
    border: none; border-radius: var(--radius);
    font-family: var(--mono); font-size: .78rem; font-weight: 600;
    cursor: pointer; text-decoration: none;
    transition: all .15s;
    letter-spacing: .03em;
  }
  .btn-accent  { background: var(--accent);  color: #000; }
  .btn-accent:hover  { filter: brightness(1.15); }
  .btn-danger  { background: var(--accent2); color: #fff; }
  .btn-danger:hover  { filter: brightness(1.15); }
  .btn-ghost   { background: transparent; border: 1px solid var(--border); color: var(--muted); }
  .btn-ghost:hover   { border-color: var(--text); color: var(--text); }
  .btn-success { background: var(--success); color: #000; }
  .btn-success:hover { filter: brightness(1.15); }
  .btn:disabled { opacity: .4; cursor: not-allowed; filter: none !important; }

  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: .8rem; }
  thead { background: var(--surface2); }
  th {
    text-align: left;
    padding: .75rem 1.25rem;
    font-size: .65rem; letter-spacing: .1em; text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  td {
    padding: .75rem 1.25rem;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
  }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: rgba(255,255,255,.02); }

  .tag {
    display: inline-block;
    padding: .15rem .5rem;
    border-radius: 3px; font-size: .7rem;
  }
  .tag-email { background: rgba(0,229,255,.1); color: var(--accent); }
  .tag-phone { background: rgba(184,255,87,.1); color: var(--accent3); }
  .password-mask {
    font-family: var(--mono);
    letter-spacing: .2em; color: var(--muted);
    cursor: pointer;
    padding: .15rem .4rem;
    border-radius: 3px;
    transition: background .15s;
  }
  .password-mask:hover { background: var(--surface2); color: var(--text); }

  .empty-row td { text-align: center; padding: 3rem; color: var(--muted); }

  .ad-manager { padding: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
  .ad-upload-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    padding: 2rem;
    text-align: center;
    transition: border-color .2s;
    cursor: pointer;
    position: relative;
  }
  .ad-upload-zone:hover { border-color: var(--accent); }
  .ad-upload-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
  }
  .upload-icon { font-size: 2.5rem; margin-bottom: .75rem; }
  .upload-label { font-family: var(--sans); font-size: .9rem; font-weight: 600; color: var(--text); margin-bottom: .35rem; }
  .upload-hint  { font-size: .72rem; color: var(--muted); }

  .ad-status-box {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.25rem;
  }
  .ad-status-title { font-family: var(--sans); font-weight: 600; font-size: .85rem; margin-bottom: 1rem; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
  .ad-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: .6rem; font-size: .8rem; }
  .ad-row .key { color: var(--muted); }
  .ad-row .val { color: var(--text); font-weight: 600; }
  .ad-row .val.active { color: var(--accent3); }
  .ad-row .val.none   { color: var(--muted); font-style: italic; }

  .notice {
    padding: .6rem 1rem;
    border-radius: var(--radius);
    font-size: .8rem;
    margin: 0 1.5rem 1rem;
  }
  .notice.success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3); color: var(--success); }
  .notice.error   { background: rgba(255,77,109,.1); border: 1px solid rgba(255,77,109,.3); color: var(--accent2); }

  /* ── FOOTER ── */
  .panel-footer {
    padding: .75rem 1.5rem;
    border-top: 1px solid var(--border);
    font-size: .72rem; color: var(--muted);
    display: flex; justify-content: space-between; align-items: center;
  }

  @media (max-width: 900px) {
    .layout { grid-template-columns: 1fr; }
    nav { display: none; }
    .stats { grid-template-columns: 1fr 1fr; }
    .ad-manager { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<header>
  <div class="logo">
    <div class="logo-dot"></div>
    RADIUS <span>ADMIN</span>
  </div>
  <div class="header-meta">
    <strong><?= date('D, d M Y — H:i') ?></strong><br>
    radius@localhost
  </div>
</header>

<div class="layout">

  <nav>
    <div class="nav-section">Main</div>
    <a href="#users"   class="nav-item active">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Users
    </a>
    <a href="#ads"     class="nav-item">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      Ad Manager
    </a>
    <div class="nav-section">Quick Links</div>
    <a href="ads.php"        class="nav-item" target="_blank">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      Preview Ad Page
    </a>
    <a href="emailotp.php"   class="nav-item" target="_blank">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      Email OTP Page
    </a>
    <a href="?export=csv" class="nav-item">
      <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
  </nav>

  <main>

    <div class="stats">
      <div class="stat-card c1">
        <div class="stat-label">Total Users</div>
        <div class="stat-value blue"><?= $total ?></div>
        <div class="stat-sub">in radcheck table</div>
      </div>
      <div class="stat-card c2">
        <div class="stat-label">Email Users</div>
        <?php
          $ec = $conn->query("SELECT COUNT(*) AS c FROM radcheck WHERE username LIKE '%@%'")->fetch_assoc()['c'];
        ?>
        <div class="stat-value red"><?= $ec ?></div>
        <div class="stat-sub">email-based accounts</div>
      </div>
      <div class="stat-card c3">
        <div class="stat-label">Phone Users</div>
        <?php
          $pc = $conn->query("SELECT COUNT(*) AS c FROM radcheck WHERE username NOT LIKE '%@%'")->fetch_assoc()['c'];
        ?>
        <div class="stat-value green"><?= $pc ?></div>
        <div class="stat-sub">phone-based accounts</div>
      </div>
    </div>

    <div class="panel" id="users">
      <div class="panel-header">
        <div class="panel-title">
          User Records
          <span class="badge"><?= count($rows) ?> shown</span>
        </div>
        <div class="controls">
          <form method="GET" style="display:flex;gap:.5rem;align-items:center;">
            <input class="search-box" type="text" name="search"
                   placeholder="Search username…"
                   value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-ghost" type="submit">Search</button>
            <?php if ($search): ?>
              <a href="admin.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
          </form>
          <a href="?export=csv<?= $search ? '&search='.urlencode($search) : '' ?>"
             class="btn btn-accent">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
          </a>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Username / Phone</th>
              <th>Type</th>
              <th>Password</th>
              <th>Attribute</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr class="empty-row"><td colspan="5">No records found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $i => $row): ?>
                <tr>
                  <td style="color:var(--muted);font-size:.72rem;"><?= $row['id'] ?></td>
                  <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                  <td>
                    <?php if (strpos($row['username'], '@') !== false): ?>
                      <span class="tag tag-email">EMAIL</span>
                    <?php else: ?>
                      <span class="tag tag-phone">PHONE</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="password-mask"
                          data-plain="<?= htmlspecialchars($row['password']) ?>"
                          title="Click to reveal">
                      ••••
                    </span>
                  </td>
                  <td style="color:var(--muted);font-size:.75rem;"><?= htmlspecialchars($row['attribute']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="panel-footer">
        <span>Showing <?= count($rows) ?> of <?= $total ?> records</span>
        <span>radius.radcheck · <?= date('H:i:s') ?></span>
      </div>
    </div>

    <!-- ── AD MANAGER ── -->
    <div class="panel" id="ads">
      <div class="panel-header">
        <div class="panel-title">
          Ad Manager
          <span class="badge">ads.php</span>
        </div>
        <a href="ads.php" target="_blank" class="btn btn-ghost">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Preview Live
        </a>
      </div>

      <?php if ($uploadMsg):  echo "<div class='notice success'>$uploadMsg</div>"; endif; ?>
      <?php if ($uploadError): echo "<div class='notice error'>✗ $uploadError</div>"; endif; ?>

      <div class="ad-manager">

        <form method="POST" enctype="multipart/form-data" id="adForm">
          <input type="hidden" name="action" value="upload_ad">

          <input type="file" name="ad_media" id="adFile"
                 accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/ogg"
                 style="display:none;"
                 onchange="updateDropLabel(this)">

          <div class="ad-upload-zone" id="dropzone" onclick="document.getElementById('adFile').click()">
            <div class="upload-icon" id="dropIcon">📁</div>
            <div class="upload-label" id="dropLabel">Click to choose ad media</div>
            <div class="upload-hint">Images: JPG · PNG · GIF · WEBP &nbsp;|&nbsp; Videos: MP4 · WEBM · OGG<br>Max 50 MB</div>
          </div>

          <div style="margin-top:.85rem;display:flex;gap:.6rem;align-items:center;">
            <button class="btn btn-accent" type="submit" id="uploadBtn" disabled>
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Upload &amp; Set Live
            </button>
            <span id="fileSize" style="font-size:.75rem;color:var(--muted);"></span>
          </div>
        </form>

        <div>
          <div class="ad-status-box">
            <div class="ad-status-title">Current Ad Status</div>
            <?php if ($adInfo): ?>
              <div class="ad-row"><span class="key">File</span><span class="val active"><?= htmlspecialchars($adInfo['filename']) ?></span></div>
              <div class="ad-row"><span class="key">Type</span><span class="val"><?= htmlspecialchars($adInfo['mime']) ?></span></div>
              <div class="ad-row"><span class="key">Updated</span><span class="val"><?= htmlspecialchars($adInfo['updated']) ?></span></div>
              <div class="ad-row"><span class="key">Status</span><span class="val active">● LIVE</span></div>
              <br>
              <form method="POST">
                <input type="hidden" name="action" value="clear_ad">
                <button class="btn btn-danger" type="submit"
                        onclick="return confirm('Remove current ad?')">
                  Remove Ad
                </button>
              </form>
            <?php else: ?>
              <div class="ad-row"><span class="key">File</span><span class="val none">none uploaded</span></div>
              <div class="ad-row"><span class="key">Status</span><span class="val none">● NO MEDIA</span></div>
              <br>
              <p style="font-size:.75rem;color:var(--muted);">Upload a file on the left to display it in ads.php.</p>
            <?php endif; ?>
          </div>

          <div style="margin-top:1rem;">
            <div class="ad-status-box" style="font-size:.78rem;">
              <div class="ad-status-title">Ad Page Settings</div>
              <div class="ad-row"><span class="key">Skip redirect</span><span class="val">emailotp.php</span></div>
              <div class="ad-row"><span class="key">Countdown</span><span class="val">5 seconds</span></div>
              <div class="ad-row"><span class="key">Skip btn pos.</span><span class="val">Bottom-right</span></div>
            </div>
          </div>
        </div>

      </div><!-- /.ad-manager -->
    </div><!-- /.panel -->

  </main>
</div>

<script>
function updateDropLabel(input) {
  const label   = document.getElementById('dropLabel');
  const icon    = document.getElementById('dropIcon');
  const sizeEl  = document.getElementById('fileSize');
  const btn     = document.getElementById('uploadBtn');
  const zone    = document.getElementById('dropzone');

  if (input.files && input.files.length > 0) {
    const f = input.files[0];
    label.textContent = f.name;
    icon.textContent  = f.type.startsWith('video/') ? '🎬' : '🖼️';
    sizeEl.textContent = '(' + (f.size / 1048576).toFixed(2) + ' MB)';
    btn.disabled      = false;
    zone.style.borderColor = 'var(--accent)';
    zone.style.background  = 'rgba(0,229,255,.04)';
  } else {
    label.textContent = 'Click to choose ad media';
    icon.textContent  = '📁';
    sizeEl.textContent = '';
    btn.disabled      = true;
    zone.style.borderColor = '';
    zone.style.background  = '';
  }
}

(function () {
  const zone = document.getElementById('dropzone');
  const input = document.getElementById('adFile');

  zone.addEventListener('dragover', function (e) {
    e.preventDefault();
    zone.style.borderColor = 'var(--accent)';
  });
  zone.addEventListener('dragleave', function () {
    if (!input.files || !input.files.length) zone.style.borderColor = '';
  });
  zone.addEventListener('drop', function (e) {
    e.preventDefault();
    if (e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      updateDropLabel(input);
    }
  });
})();

// Password reveal
document.querySelectorAll('.password-mask').forEach(function(el) {
  var plain = el.getAttribute('data-plain');
  el.addEventListener('click', function() {
    this.textContent = this.textContent === '••••' ? plain : '••••';
  });
});
</script>
</body>
</html>
