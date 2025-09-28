<?php
/*
===========================================================
 University Event Link Retriever — Single‑File Starter App
 Stack: PHP 7+/8+, MySQL (MariaDB), runs on XAMPP
===========================================================
 Setup:
 1) Create a DB (e.g., marketinghub) in phpMyAdmin.
 2) Update DB credentials below.
 3) Drop this file as index.php in htdocs/eventhub/ then open http://localhost/eventhub/
 4) On first run, click "Initialize DB" to create tables.
-----------------------------------------------------------
 Features in this single file:
 - Create/Edit/Delete Events (name, date, department, description, tags)
 - Attach multiple platform links per event (FB/IG/TikTok/YouTube/Website)
 - Fast search & filters (year range, department, platform, keywords)
 - One‑click export (CSV) and a shareable read‑only link for requesters
 - Minimal styling; easy to extend
===========================================================
*/

// ---- DB CONFIG ----
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'marketinghub';

// ---- CONNECT ----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    http_response_code(500);
    die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

// ---- HELPERS ----
function h($s){return htmlspecialchars($s ?? '', ENT_QUOTES,'UTF-8');}
function param($key,$default=null){return $_REQUEST[$key] ?? $default;}

// ---- CREATE TABLES ----
function init_db($conn){
    $sql = [];
    $sql[] = "CREATE TABLE IF NOT EXISTS events (
        event_id INT AUTO_INCREMENT PRIMARY KEY,
        event_name VARCHAR(255) NOT NULL,
        event_date DATE NULL,
        department VARCHAR(255) NULL,
        description TEXT NULL,
        tags VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sql[] = "CREATE TABLE IF NOT EXISTS links (
        link_id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        platform ENUM('Facebook','Instagram','TikTok','YouTube','Website') NOT NULL,
        url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_links_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    foreach($sql as $q){ 
        try {
            $conn->query($q); 
        } catch (Exception $e) {
            die('Database initialization failed: ' . htmlspecialchars($e->getMessage()));
        }
    }
}

if (param('action') === 'init') {
    init_db($conn);
    header('Location: '. strtok($_SERVER['REQUEST_URI'],'?'));
    exit;
}

// ---- CRUD: EVENTS ----
if (param('action') === 'save_event' && $_SERVER['REQUEST_METHOD']==='POST'){
    $id = (int)($_POST['event_id'] ?? 0);
    $name = trim($_POST['event_name'] ?? '');
    $date = $_POST['event_date'] ?: null;
    $dept = trim($_POST['department'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $tags = trim($_POST['tags'] ?? '');

    if (empty($name)) {
        header('Location: ?tab=events&error=name_required');
        exit;
    }

    try {
        $conn->begin_transaction();
        
        if ($id > 0) {
            // Update existing event
            $stmt = $conn->prepare("UPDATE events SET event_name=?, event_date=?, department=?, description=?, tags=? WHERE event_id=?");
            $stmt->bind_param('sssssi', $name, $date, $dept, $desc, $tags, $id);
            $stmt->execute();
        } else {
            // Create new event
            $stmt = $conn->prepare("INSERT INTO events (event_name,event_date,department,description,tags) VALUES (?,?,?,?,?)");
            $stmt->bind_param('sssss', $name, $date, $dept, $desc, $tags);
            $stmt->execute();
            $id = $conn->insert_id;
        }
        
        // Handle links for new events (only when creating, not updating)
        if (!$_POST['event_id']) {
            $link_platforms = $_POST['link_platform'] ?? [];
            $link_urls = $_POST['link_url'] ?? [];
            
            for ($i = 0; $i < count($link_platforms); $i++) {
                $platform = trim($link_platforms[$i] ?? '');
                $url = trim($link_urls[$i] ?? '');
                
                if (!empty($platform) && !empty($url)) {
                    // Add https if missing
                    if (!preg_match('/^https?:\/\//', $url)) {
                        $url = 'https://' . $url;
                    }
                    
                    // Validate platform
                    $allowed_platforms = ['Facebook','Instagram','TikTok','YouTube','Website'];
                    if (in_array($platform, $allowed_platforms)) {
                        $link_stmt = $conn->prepare("INSERT INTO links (event_id,platform,url) VALUES (?,?,?)");
                        $link_stmt->bind_param('iss', $id, $platform, $url);
                        $link_stmt->execute();
                    }
                }
            }
        }
        
        $conn->commit();
        header('Location: ?tab=events&edited='.$id.'&success=event_saved');
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Event save error: " . $e->getMessage());
        header('Location: ?tab=events&error=save_failed');
    }
    exit;
}

if (param('action') === 'delete_event'){
    $id = (int)param('id');
    if($id > 0){
        try {
            $stmt = $conn->prepare("DELETE FROM events WHERE event_id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
        } catch (Exception $e) {
            header('Location: ?tab=events&error=delete_failed');
            exit;
        }
    }
    header('Location: ?tab=events');
    exit;
}

// ---- LINKS: ADD/DELETE ----
if (param('action') === 'add_link' && $_SERVER['REQUEST_METHOD']==='POST'){
    $event_id = (int)($_POST['event_id'] ?? 0);
    $platform = $_POST['platform'] ?? 'Website';
    $url = trim($_POST['url'] ?? '');
    
    // Validate inputs
    if ($event_id > 0 && !empty($url)) {
        // Allow more flexible URL validation - check if it starts with http/https or add https if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        
        // Validate platform is in allowed list
        $allowed_platforms = ['Facebook','Instagram','TikTok','YouTube','Website'];
        if (!in_array($platform, $allowed_platforms)) {
            $platform = 'Website';
        }
        
        try {
            // Check if event exists first
            $check_stmt = $conn->prepare("SELECT event_id FROM events WHERE event_id=?");
            $check_stmt->bind_param('i', $event_id);
            $check_stmt->execute();
            $event_exists = $check_stmt->get_result()->fetch_assoc();
            
            if ($event_exists) {
                $stmt = $conn->prepare("INSERT INTO links (event_id,platform,url) VALUES (?,?,?)");
                $stmt->bind_param('iss', $event_id, $platform, $url);
                $stmt->execute();
                header('Location: ?tab=events&edited='.$event_id.'&success=link_added');
            } else {
                header('Location: ?tab=events&error=event_not_found');
            }
        } catch (Exception $e) {
            error_log("Link add error: " . $e->getMessage());
            header('Location: ?tab=events&edited='.$event_id.'&error=link_failed');
        }
    } else {
        header('Location: ?tab=events&edited='.$event_id.'&error=invalid_link_data');
    }
    exit;
}

if (param('action') === 'delete_link'){
    $id = (int)param('id');
    $event_id = (int)param('event_id');
    if($id > 0){
        try {
            $stmt = $conn->prepare("DELETE FROM links WHERE link_id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
        } catch (Exception $e) {
            header('Location: ?tab=events&edited='.$event_id.'&error=delete_link_failed');
            exit;
        }
    }
    header('Location: ?tab=events&edited='.$event_id);
    exit;
}

// ---- EXPORT CSV (SHAREABLE) ----
if (param('action') === 'export_csv'){
    $filters = [
        'q' => param('q'),
        'dept' => param('dept'),
        'platform' => param('platform'),
        'year_from' => param('year_from'),
        'year_to' => param('year_to')
    ];

    try {
        $rows = search_rows($conn, $filters, 100000); // big cap for export

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=event_links_export.csv');
        $out = fopen('php://output','w');
        fputcsv($out, ['Event','Date','Department','Tags','Platform','URL']);
        foreach($rows as $r){
            fputcsv($out, [$r['event_name'],$r['event_date'],$r['department'],$r['tags'],$r['platform'],$r['url']]);
        }
        fclose($out);
    } catch (Exception $e) {
        header('Location: ?tab=search&error=export_failed');
    }
    exit;
}

// ---- SEARCH CORE ----
function search_rows($conn, $filters, $limit=100){
    $where = [];
    $params = [];
    $types = '';

    if (!empty($filters['q'])){
        $where[] = "(e.event_name LIKE CONCAT('%', ?, '%') OR e.description LIKE CONCAT('%', ?, '%') OR e.tags LIKE CONCAT('%', ?, '%'))";
        $params[] = $filters['q'];
        $params[] = $filters['q'];
        $params[] = $filters['q'];
        $types .= 'sss';
    }
    if (!empty($filters['dept'])){
        $where[] = "e.department = ?";
        $params[] = $filters['dept'];
        $types .= 's';
    }
    if (!empty($filters['platform'])){
        $where[] = "l.platform = ?";
        $params[] = $filters['platform'];
        $types .= 's';
    }
    if (!empty($filters['year_from']) && is_numeric($filters['year_from'])){
        $where[] = "YEAR(e.event_date) >= ?";
        $params[] = (int)$filters['year_from'];
        $types .= 'i';
    }
    if (!empty($filters['year_to']) && is_numeric($filters['year_to'])){
        $where[] = "YEAR(e.event_date) <= ?";
        $params[] = (int)$filters['year_to'];
        $types .= 'i';
    }

    $sql = "SELECT e.event_id, e.event_name, e.event_date, e.department, e.tags, l.platform, l.url
            FROM events e
            LEFT JOIN links l ON l.event_id = e.event_id";
    if ($where){ $sql .= " WHERE ".implode(' AND ',$where); }
    $sql .= " ORDER BY e.event_date DESC, e.event_id DESC LIMIT ".(int)$limit;

    try {
        $stmt = $conn->prepare($sql);
        if ($types){ $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $res = $stmt->get_result();
        return $res->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ---- FETCH DATA FOR UI ----
$tab = param('tab','search');
$edited = (int)param('edited');
$event_to_edit = null;
$links_for_event = [];

if ($edited > 0){
    try {
        $stmt = $conn->prepare("SELECT * FROM events WHERE event_id=?");
        $stmt->bind_param('i', $edited);
        $stmt->execute();
        $event_to_edit = $stmt->get_result()->fetch_assoc();

        if ($event_to_edit) {
            $stmt = $conn->prepare("SELECT * FROM links WHERE event_id=? ORDER BY link_id DESC");
            $stmt->bind_param('i', $edited);
            $stmt->execute();
            $links_for_event = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        $event_to_edit = null;
        $links_for_event = [];
    }
}

// For sidebar: last 10 events
try {
    $recent = $conn->query("SELECT event_id, event_name, event_date FROM events ORDER BY event_id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $recent = [];
}

// Departments (dynamic distinct)
try {
    $depts = $conn->query("SELECT DISTINCT department FROM events WHERE department IS NOT NULL AND department<>'' ORDER BY department")->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $depts = [];
}

$platforms = ['Facebook','Instagram','TikTok','YouTube','Website'];

// Build shareable view URL (read‑only) based on current filters
$share_url = (function(){
    $qs = $_GET; 
    unset($qs['action']); 
    unset($qs['tab']);
    unset($qs['error']);
    $qs['tab'] = 'search';
    return strtok($_SERVER['REQUEST_URI'],'?').'?'.http_build_query($qs);
})();

// Error messages
$error_messages = [
    'name_required' => 'Event name is required.',
    'save_failed' => 'Failed to save event. Please try again.',
    'delete_failed' => 'Failed to delete event. Please try again.',
    'link_failed' => 'Failed to add link. Please try again.',
    'delete_link_failed' => 'Failed to delete link. Please try again.',
    'export_failed' => 'Failed to export data. Please try again.',
    'event_not_found' => 'Event not found. Please select a valid event.',
    'invalid_link_data' => 'Please provide a valid URL for the link.'
];
$success_messages = [
    'event_saved' => 'Event saved successfully!',
    'link_added' => 'Link added successfully!'
];
$error = param('error');
$success = param('success');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>University Event Link Retriever</title>
<style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f6f7fb;color:#1f2937}
    header{background:#d7cccc;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap}
    .brand{font-weight:700;font-size:18px}
    .wrap{display:grid;grid-template-columns:260px 1fr;gap:16px;padding:16px}
    @media (max-width: 768px) {
        .wrap{grid-template-columns:1fr;gap:12px}
        header{flex-direction:column;gap:12px}
    }
    aside{background:#fff;border-radius:16px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
    main{background:#fff;border-radius:16px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
    .tabs a{margin-right:8px;text-decoration:none;padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;color:#111827}
    .tabs a.active{background:#111827;color:#fff;border-color:#111827}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    input,select,textarea,button{padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px}
    button{cursor:pointer;background:#fff}
    button.primary{background:#111827;color:#fff;border-color:#111827}
    button.danger{background:#dc2626;color:#fff;border-color:#dc2626}
    button:hover{opacity:0.9}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;word-break:break-word}
    th{background:#f9fafb;font-weight:600}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px}
    .muted{color:#6b7280;font-size:12px}
    .danger{color:#b91c1c}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}
    .error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px;border-radius:8px;margin-bottom:12px}
    .success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px;border-radius:8px;margin-bottom:12px}
    .link-row{background:#f9fafb;padding:8px;border-radius:8px;margin-bottom:8px;}
    .section-title{font-weight:600;margin:16px 0 8px 0;color:#374151}
    ul{list-style:none;padding:0;margin:0}
    li{margin-bottom:8px;padding:8px;border-radius:6px;background:#f9fafb}
    li a{text-decoration:none;color:#111827;font-weight:500}
    .logo-container{text-align:center;margin-bottom:20px}
    .logo-container img{height:60px;max-width:200px;object-fit:contain}
    .header-content{display:flex;align-items:center;justify-content:space-between;width:100%}
    .nav-buttons{display:flex;gap:8px;flex-wrap:wrap}
</style>
</head>
<body>
<header>
  <div class="header-content">
    <div>
      <div class="logo-container">
        <img src="citlogo.png" alt="CIT-U Logo" style="height:60px;" onerror="this.style.display='none'">
      </div>
      <div class="brand">CIT-U Event Manager</div>
    </div>
    <div class="nav-buttons">
      <a href="?action=init" onclick="return confirm('Initialize DB tables? This will create required database tables.')"><button>Initialize DB</button></a>
      <a href="?tab=events"><button <?= $tab==='events'?'class="primary"':'' ?>>Manage Events</button></a>
      <a href="?tab=search"><button <?= $tab==='search'?'class="primary"':'' ?>>Search & Export</button></a>
    </div>
  </div>
</header>
<div class="wrap">
  <aside>
    <div class="muted" style="margin-bottom:12px;font-weight:600">Recent Events</div>
    <?php if (empty($recent)): ?>
      <div class="muted">No events found. <a href="?tab=events">Create your first event</a>.</div>
    <?php else: ?>
      <ul>
        <?php foreach($recent as $r): ?>
          <li>
            <a href="?tab=events&edited=<?= (int)$r['event_id'] ?>"><?= h($r['event_name']) ?></a>
            <?php if ($r['event_date']): ?>
              <br><span class="muted"><?= h(date('M j, Y', strtotime($r['event_date']))) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>
  <main>
    <?php if ($error && isset($error_messages[$error])): ?>
      <div class="error"><?= h($error_messages[$error]) ?></div>
    <?php endif; ?>
    
    <?php if ($success && isset($success_messages[$success])): ?>
      <div class="success"><?= h($success_messages[$success]) ?></div>
    <?php endif; ?>

    <?php if($tab==='events'): ?>
      <h2><?= $edited? 'Edit Event':'Add New Event' ?></h2>
      <form method="post" action="?action=save_event">
        <input type="hidden" name="event_id" value="<?= (int)($event_to_edit['event_id'] ?? 0) ?>"/>
        
      <h3>Event Details</h3>
      <div class="row" style="margin-bottom:12px;">
        <input required name="event_name" placeholder="Event name *" value="<?= h($event_to_edit['event_name'] ?? '') ?>" style="flex:2"/>
        <input type="date" name="event_date" value="<?= h($event_to_edit['event_date'] ?? '') ?>"/>
        <input name="department" placeholder="Department" value="<?= h($event_to_edit['department'] ?? '') ?>"/>
      </div>
      <div class="row" style="margin-bottom:12px;">
        <input name="tags" placeholder="Tags (comma separated)" value="<?= h($event_to_edit['tags'] ?? '') ?>" style="flex:1"/>
      </div>
      <div style="margin-bottom:12px;">
        <textarea name="description" placeholder="Event description" style="width:100%;min-height:90px;box-sizing:border-box;"><?= h($event_to_edit['description'] ?? '') ?></textarea>
      </div>
        
        <?php if (!$edited): // Only show for new events ?>
        <h3>Social Media Links <span class="muted">(Optional - you can also add these later)</span></h3>
        <div id="links-container">
          <div class="link-row row" style="margin-bottom:8px;">
            <select name="link_platform[]">
              <option value="">Select Platform</option>
              <?php foreach($platforms as $p): ?>
                <option value="<?= h($p) ?>"><?= h($p) ?></option>
              <?php endforeach; ?>
            </select>
            <input name="link_url[]" placeholder="Enter URL (e.g., facebook.com/event)" style="flex:2"/>
            <button type="button" onclick="removeLinkRow(this)" style="background:#dc2626;color:#fff;border-color:#dc2626">Remove</button>
          </div>
        </div>
        <button type="button" onclick="addLinkRow()" style="margin-bottom:16px;">+ Add Another Link</button>
        <?php endif; ?>
        
        <div class="row">
          <button class="primary" type="submit"><?= $edited ? 'Update Event' : 'Create Event' ?></button>
          <?php if($edited): ?>
            <a href="?action=delete_event&id=<?= (int)$edited ?>" onclick="return confirm('Delete this event? All associated links will also be removed.')"><button type="button" class="danger">Delete Event</button></a>
            <a href="?tab=events"><button type="button">Cancel / Add New</button></a>
          <?php endif; ?>
        </div>
      </form>

      <?php if($edited && $event_to_edit): ?>
        <hr style="margin:24px 0;"/>
        <h3>Social Media Links for "<?= h($event_to_edit['event_name']) ?>"</h3>
        <form class="row" method="post" action="?action=add_link" style="margin-bottom:16px;" onsubmit="return validateLinkForm(this)">
          <input type="hidden" name="event_id" value="<?= (int)$edited ?>"/>
          <select name="platform" required>
            <option value="">Select Platform</option>
            <?php foreach($platforms as $p): ?>
              <option value="<?= h($p) ?>"><?= h($p) ?></option>
            <?php endforeach; ?>
          </select>
          <input required name="url" placeholder="Enter URL (e.g., facebook.com/event)" style="flex:2" />
          <button class="primary" type="submit">Add Link</button>
        </form>
        
        <?php if (empty($links_for_event)): ?>
          <div class="muted">No links added yet. Add social media and website links above.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>Platform</th><th>URL</th><th style="width:80px;">Action</th></tr></thead>
            <tbody>
              <?php foreach($links_for_event as $l): ?>
                <tr>
                  <td><span class="pill"><?= h($l['platform']) ?></span></td>
                  <td class="mono">
                    <a href="<?= h($l['url']) ?>" target="_blank" rel="noopener noreferrer">
                      <?= h(strlen($l['url']) > 50 ? substr($l['url'], 0, 47).'...' : $l['url']) ?>
                    </a>
                  </td>
                  <td>
                    <a class="danger" href="?action=delete_link&id=<?= (int)$l['link_id'] ?>&event_id=<?= (int)$edited ?>" onclick="return confirm('Delete this link?')">Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php elseif($edited): ?>
        <div class="error">Event not found.</div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if($tab==='search'): ?>
      <h2>Search & Retrieve Event Links</h2>
      <form class="row" method="get" action="" style="margin-bottom:16px;">
        <input type="hidden" name="tab" value="search"/>
        <input name="q" placeholder="Search events, descriptions, tags..." value="<?= h(param('q')) ?>" style="flex:2"/>
        <select name="dept">
          <option value="">All Departments</option>
          <?php foreach($depts as $d): $v=$d['department']; ?>
            <option <?= (param('dept')===$v)?'selected':'' ?> value="<?= h($v) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="platform">
          <option value="">All Platforms</option>
          <?php foreach($platforms as $p): ?>
            <option <?= (param('platform')===$p)?'selected':'' ?> value="<?= h($p) ?>"><?= h($p) ?></option>
          <?php endforeach; ?>
        </select>
        <input name="year_from" placeholder="From (YYYY)" value="<?= h(param('year_from')) ?>" style="width:120px" pattern="[0-9]{4}" title="Enter 4-digit year"/>
        <input name="year_to" placeholder="To (YYYY)" value="<?= h(param('year_to')) ?>" style="width:120px" pattern="[0-9]{4}" title="Enter 4-digit year"/>
        <button class="primary" type="submit">Search</button>
      </form>

      <?php
        $filters = [
          'q'=>param('q'),
          'dept'=>param('dept'),
          'platform'=>param('platform'),
          'year_from'=>param('year_from'),
          'year_to'=>param('year_to'),
        ];
        $rows = search_rows($conn,$filters, 500);
      ?>

      <div class="row" style="margin-bottom:16px;">
        <a href="?<?= h(http_build_query(array_merge($_GET,['action'=>'export_csv']))) ?>"><button>⬇ Export CSV</button></a>
        <input readonly value="<?= h((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$share_url) ?>" style="flex:1" onclick="this.select()" title="Click to select shareable URL"/>
        <span class="muted">← Shareable read‑only link</span>
      </div>

      <?php if (empty($rows)): ?>
        <div class="muted" style="text-align:center;padding:40px;">
          No events found matching your criteria. <a href="?tab=events">Add some events</a> to get started.
        </div>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr>
                <th>Event</th>
                <th>Date</th>
                <th>Department</th>
                <th>Tags</th>
                <th>Platform</th>
                <th>URL</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td>
                  <a href="?tab=events&edited=<?= (int)$r['event_id'] ?>" style="text-decoration:none;color:#111827;font-weight:500">
                    <?= h($r['event_name']) ?>
                  </a>
                </td>
                <td><?= $r['event_date'] ? h(date('M j, Y', strtotime($r['event_date']))) : '—' ?></td>
                <td><?= h($r['department'] ?: '—') ?></td>
                <td><?= h($r['tags'] ?: '—') ?></td>
                <td>
                  <?php if($r['platform']): ?>
                    <span class="pill"><?= h($r['platform']) ?></span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="mono">
                  <?php if($r['url']): ?>
                    <a target="_blank" rel="noopener noreferrer" href="<?= h($r['url']) ?>" style="color:#2563eb">
                      <?= h(strlen($r['url']) > 40 ? substr($r['url'], 0, 37).'...' : $r['url']) ?>
                    </a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="muted" style="margin-top:12px;">Showing <?= count($rows) ?> result(s)</div>
      <?php endif; ?>
    <?php endif; ?>

  </main>
</div>

<script>
// Auto-select shareable URL when clicked
document.addEventListener('DOMContentLoaded', function() {
    const shareInput = document.querySelector('input[readonly]');
    if (shareInput) {
        shareInput.addEventListener('click', function() {
            this.select();
            try {
                document.execCommand('copy');
            } catch(e) {
                // Silently fail if copy not supported
            }
        });
    }
});

// Validate link form
function validateLinkForm(form) {
    const platform = form.platform.value;
    const url = form.url.value.trim();
    
    if (!platform) {
        alert('Please select a platform');
        return false;
    }
    
    if (!url) {
        alert('Please enter a URL');
        return false;
    }
    
    // Basic URL format check
    const urlPattern = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/;
    const cleanUrl = url.replace(/^https?:\/\//, '');
    
    if (!urlPattern.test(cleanUrl) && !urlPattern.test('http://' + cleanUrl)) {
        alert('Please enter a valid URL (e.g., facebook.com/event or https://facebook.com/event)');
        return false;
    }
    
    return true;
}

// Add new link row
function addLinkRow() {
    const container = document.getElementById('links-container');
    const newRow = document.createElement('div');
    newRow.className = 'link-row row';
    newRow.style.marginBottom = '8px';
    
    newRow.innerHTML = `
        <select name="link_platform[]">
            <option value="">Select Platform</option>
            <?php foreach($platforms as $p): ?>
                <option value="<?= h($p) ?>"><?= h($p) ?></option>
            <?php endforeach; ?>
        </select>
        <input name="link_url[]" placeholder="Enter URL (e.g., facebook.com/event)" style="flex:2"/>
        <button type="button" onclick="removeLinkRow(this)" style="background:#dc2626;color:#fff;border-color:#dc2626">Remove</button>
    `;
    
    container.appendChild(newRow);
}

// Remove link row
function removeLinkRow(button) {
    const container = document.getElementById('links-container');
    if (container.children.length > 1) {
        button.parentElement.remove();
    } else {
        // Clear the inputs instead of removing if it's the last row
        const row = button.parentElement;
        row.querySelector('select').selectedIndex = 0;
        row.querySelector('input').value = '';
    }
}
</script>
</body>
</html>