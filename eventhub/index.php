 <?php
/********************************************
 * Marketing Archive Manager (InfinityFree / PHP)
 * Modern Professional UI - Enhanced Design
 * Features: Glassmorphism, animations, responsive design
 ********************************************/

// ====== CONFIG (InfinityFree) ======
$DB_HOST = "sql311.infinityfree.com";
$DB_USER = "if0_39942166";
$DB_PASS = "Marketcit2025";
$DB_NAME = "if0_39942166_marketinghub";

// Passwords
$ADMIN_PASSWORD = "Citmarketing2025!!"; // full admin
$EDIT_PASSWORD  = "EditPass2025!!";     // enable editing while already authenticated

session_start();
$isAuthed = isset($_SESSION['authed']) && $_SESSION['authed'] === true;
$canEdit  = isset($_SESSION['can_edit']) && $_SESSION['can_edit'] === true;

// ====== DB CONNECT ======
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
  $conn->set_charset("utf8mb4");
} catch (Exception $e) {
  http_response_code(500);
  die("Database connection failed. Check host/credentials. Error: " . htmlspecialchars($e->getMessage()));
}

// ====== AUTO-CREATE TABLES ======
$conn->query("
CREATE TABLE IF NOT EXISTS `events` (
  `event_id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_name` VARCHAR(255) NOT NULL,
  `event_date` DATE NOT NULL,
  `department` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `tags` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
CREATE TABLE IF NOT EXISTS `links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_id` INT(11) NOT NULL,
  `platform` ENUM('Facebook','Instagram','TikTok','YouTube','Website','Other') NOT NULL,
  `url` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `links_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ====== HELPERS ======
function post($k,$d=null){return isset($_POST[$k])?trim($_POST[$k]):$d;}
function getv($k,$d=null){return isset($_GET[$k])?trim($_GET[$k]):$d;}
function norm_url($u){ $u=trim($u); if($u==="" )return ""; if(str_starts_with($u,"http://")||str_starts_with($u,"https://")) return $u; return "https://".$u; }

// ====== AUTH ======
$flash = "";
if (post('action') === 'login') {
  if (post('password') === $ADMIN_PASSWORD) {
    $_SESSION['authed'] = true;
    $_SESSION['can_edit'] = true;
    header("Location: ?tab=search");
    exit;
  } else {
    $flash = "Incorrect admin password.";
  }
}
if (post('action') === 'enable_edit') {
  if (!$isAuthed) { $_SESSION['authed'] = true; }
  if (post('edit_password') === $EDIT_PASSWORD) {
    $_SESSION['can_edit'] = true;
  } else {
    $flash = "Incorrect edit password.";
  }
}
if (isset($_GET['viewOnly'])) {
  $_SESSION['authed'] = true;
  $_SESSION['can_edit'] = false;
  header("Location: ?tab=search");
  exit;
}
if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: ./");
  exit;
}

// ====== CRUD ======
if ($canEdit && post('action') === 'save_event') {
  $eid  = (int)post('event_id');
  $name = post('event_name','');
  $date = post('event_date','');
  $dept = post('department');
  $desc = post('description');
  $tags = post('tags');

  if ($name === '' || $date === '') {
    $flash = "Event name and date are required.";
  } else {
    try {
      if ($eid > 0) {
        $stmt = $conn->prepare("UPDATE events SET event_name=?, event_date=?, department=?, description=?, tags=? WHERE event_id=?");
        $stmt->bind_param("sssssi", $name, $date, $dept, $desc, $tags, $eid);
        $stmt->execute();
        header("Location: ?tab=events&edit=".$eid);
        exit;
      } else {
        $stmt = $conn->prepare("INSERT INTO events (event_name, event_date, department, description, tags) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $name, $date, $dept, $desc, $tags);
        $stmt->execute();
        $new_id = $stmt->insert_id;

        if (post('link_platform') && post('link_url')) {
          $p = post('link_platform');
          $u = norm_url(post('link_url'));
          $stmt = $conn->prepare("INSERT INTO links (event_id, platform, url) VALUES (?,?,?)");
          $stmt->bind_param("iss", $new_id, $p, $u);
          $stmt->execute();
        }

        header("Location: ?tab=events&edit=".$new_id);
        exit;
      }
    } catch (Exception $e) {
      $flash = "Error saving event: " . $e->getMessage();
    }
  }
}

if ($canEdit && post('action')==='delete_event') {
  $eid=(int)post('event_id');
  $stmt=$conn->prepare("DELETE FROM events WHERE event_id=?");
  $stmt->bind_param("i",$eid);
  $stmt->execute();
  header("Location: ?tab=events");
  exit;
}

if ($canEdit && post('action')==='add_link') {
  $eid=(int)post('event_id');
  $p=post('platform'); $u=norm_url(post('url'));
  if($eid && $p && $u){
    $stmt=$conn->prepare("INSERT INTO links(event_id,platform,url) VALUES(?,?,?)");
    $stmt->bind_param("iss",$eid,$p,$u);
    $stmt->execute();
  }
  header("Location: ?tab=events&edit=".$eid."#links");
  exit;
}

if ($canEdit && post('action')==='delete_link') {
  $id=(int)post('id');
  $eid=0;
  $stmt=$conn->prepare("SELECT event_id FROM links WHERE id=?");
  $stmt->bind_param("i",$id);
  $stmt->execute();
  if ($res=$stmt->get_result()->fetch_assoc()) { $eid = (int)$res['event_id']; }

  $stmt=$conn->prepare("DELETE FROM links WHERE id=?");
  $stmt->bind_param("i",$id);
  $stmt->execute();

  if ($eid>0) header("Location: ?tab=events&edit=".$eid."#links");
  else header("Location: ?tab=events");
  exit;
}

// ====== FETCH DATA ======
$search = getv('q','');
$deptF  = getv('dept','');
$platF  = getv('plat','');
$yf     = getv('yf','');
$yt     = getv('yt','');

// ====== PAGINATION ======
$page = (int)getv('page', 1);
$perPage = 10; // Events per page
$offset = ($page - 1) * $perPage;

// Build base query
$sql="SELECT * FROM events";
$countSql="SELECT COUNT(*) as total FROM events";
$args=[]; $where=[];
if($search!==''){ $where[]="(event_name LIKE ? OR description LIKE ? OR department LIKE ? OR tags LIKE ?)"; $like="%$search%"; $args=[$like,$like,$like,$like]; }
if($deptF!==''){ $where[]="department=?"; $args[]=$deptF; }

if(!empty($where)) {
  $whereClause = " WHERE ".implode(" AND ",$where);
  $sql.=$whereClause;
  $countSql.=$whereClause;
}

$sql.=" ORDER BY event_date DESC, event_id DESC LIMIT $perPage OFFSET $offset";

// Get total count for pagination
$totalEvents = 0;
if(!empty($args)){
  $types=str_repeat('s',count($args));
  $stmt=$conn->prepare($countSql);
  $stmt->bind_param($types,...$args);
  $stmt->execute();
  $totalEvents = $stmt->get_result()->fetch_assoc()['total'];
}else{
  $totalEvents = $conn->query($countSql)->fetch_assoc()['total'];
}

// Get paginated events
$events=[];
if(!empty($args)){
  $types=str_repeat('s',count($args));
  $stmt=$conn->prepare($sql);
  $stmt->bind_param($types,...$args);
  $stmt->execute();
  $events=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}else{
  $events=$conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Calculate pagination info
$totalPages = ceil($totalEvents / $perPage);

// Links for current set
$linksByEvent=[];
if(!empty($events)){
  $ids=array_column($events,'event_id');
  $in=implode(',',array_fill(0,count($ids),'?'));
  $types=str_repeat('i',count($ids));
  $stmt=$conn->prepare("SELECT * FROM links WHERE event_id IN ($in) ORDER BY created_at DESC");
  $stmt->bind_param($types,...$ids);
  $stmt->execute();
  $res=$stmt->get_result();
  while($row=$res->fetch_assoc()){
    $linksByEvent[$row['event_id']][]=$row;
  }
}

// Distinct departments (from current results)
$depts = array_values(array_filter(array_unique(array_map(fn($e)=>$e['department']??'', $events))));
sort($depts);

// Year filter helper
function passes_year($d,$yf,$yt){
  if(!$d) return true;
  $y=(int)date('Y',strtotime($d));
  if($yf!=='' && $y<(int)$yf) return false;
  if($yt!=='' && $y>(int)$yt) return false;
  return true;
}

// Single event for edit
$edit = null;
if (getv('edit')) {
  $eid=(int)getv('edit');
  $stmt=$conn->prepare("SELECT * FROM events WHERE event_id=?");
  $stmt->bind_param("i",$eid);
  $stmt->execute();
  $edit=$stmt->get_result()->fetch_assoc();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Marketin Archive Manager</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" href="citlogo.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles.css"> 
</head>
<body>
<?php if(!$isAuthed): ?>
  <!-- LOGIN SCREEN -->
<div class="login-container">
  <div class="login-card animate-float">
    <div class="text-center mb-8">
      <div class="mb-4">
        <img src="citlogo.png" alt="CIT-U Logo" class="h-20 mx-auto object-contain animate-pulse-slow">
      </div>
      <h1 class="text-3xl font-bold tracking-tight bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
        Marketing Archive Manager
      </h1>
      <p class="text-slate-600 mt-2 font-medium">University Event Link Retriever</p>
    </div>
    
    <?php if($flash): ?>
      <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>
    
    <form method="post" class="space-y-6">
      <input type="hidden" name="action" value="login">
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-2">Admin Password</label>
        <input 
          name="password" 
          type="password" 
          class="input-modern w-full" 
          placeholder="Enter admin password" 
          required
        >
      </div>
      <button class="btn btn-primary w-full text-base py-3">
        <span>Login to Dashboard</span>
      </button>
    </form>
    
    <div class="mt-6">
      <a href="?viewOnly=1" class="btn w-full text-base py-3">
        <span>View Events (Read Only)</span>
      </a>
    </div>

    <!-- Contact Info -->
    <div class="mt-8 text-center text-sm text-slate-500">
      Contact Admin: 
      <a href="mailto:hemsworthliam925@gmail.com" class="text-slate-700 font-medium hover:underline">
        hemsworthliam925@gmail.com
      </a>
    </div>
  </div>
</div>

<?php else: ?>
  <!-- APP SHELL -->
  <header class="header-glass sticky top-0 z-50">
    <div class="container mx-auto px-4 py-3">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <img src="citlogo.png" alt="CIT-U Logo" class="h-12 object-contain">
          <div>
            <h1 class="text-2xl font-bold tracking-tight bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
              Marketing Archive Manager
            </h1>
            <p class="text-slate-600 text-sm font-medium">University Event Link Retriever</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <?php if(!$canEdit): ?>
            <form method="post" class="flex items-center gap-3">
              <input type="hidden" name="action" value="enable_edit">
              <!-- Edit password form can be uncommented if needed -->
            </form>
          <?php endif; ?>
          <a href="?logout=1" class="btn">
            <span>Logout</span>
          </a>
        </div>
      </div>
    </div>
  </header>

  <main class="container mx-auto px-4 py-4">
    <?php if($flash): ?>
      <div class="mb-4 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
      <!-- Sidebar: Recent Events -->
      <aside class="xl:col-span-1">
        <div class="sidebar-card">
          <h2 class="font-bold text-lg text-slate-800 mb-3">Recent Events</h2>
          <div class="space-y-2">
            <?php if(empty($events)): ?>
              <p class="text-sm text-slate-500">No events yet.</p>
            <?php else: ?>
              <?php foreach(array_slice($events, 0, 6) as $ev): ?>
                <a href="?tab=events&edit=<?= (int)$ev['event_id'] ?>" class="recent-event-item">
                  <div class="font-medium text-slate-800 text-sm truncate"><?= htmlspecialchars($ev['event_name']) ?></div>
                  <div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($ev['event_date']) ?></div>
                  <?php if($ev['department']): ?>
                    <div class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($ev['department']) ?></div>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </aside>

      <!-- Main Content -->
      <section class="xl:col-span-4">
        <?php $tab = getv('tab', 'search'); ?>
        
        <!-- Tab Navigation -->
        <div class="flex gap-2 mb-4">
          <a class="tab-modern <?= $tab === 'search' ? 'active' : '' ?>" href="?tab=search">
            <span>Search & Export</span>
          </a>
          <a class="tab-modern <?= $tab === 'events' ? 'active' : '' ?> <?= !$canEdit ? 'opacity-50 pointer-events-none' : '' ?>" 
             href="<?= $canEdit ? '?tab=events' : '#' ?>">
            <span>Manage Events</span>
          </a>
        </div>

        <?php if($tab === 'search'): ?>
          <!-- Search & Filter Card -->
          <div class="card p-4 mb-4">
            <h2 class="font-bold text-lg text-slate-800 mb-4">Search & Retrieve Event Links</h2>
            
            <form method="get" class="space-y-4">
              <input type="hidden" name="tab" value="search">
              
              <!-- Search Input -->
              <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Search Events</label>
                <input 
                  name="q" 
                  value="<?= htmlspecialchars($search) ?>" 
                  class="input-modern w-full" 
                  placeholder="Search events, descriptions, departments, tags..."
                >
              </div>

              <!-- Filter Controls -->
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                  <label class="block text-sm font-semibold text-slate-700 mb-2">Department</label>
                  <select name="dept" class="input-modern w-full">
                    <option value="">All Departments</option>
                    <?php foreach($depts as $d): ?>
                      <option value="<?= htmlspecialchars($d) ?>" <?= $deptF === $d ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($d) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                
                <div>
                  <label class="block text-sm font-semibold text-slate-700 mb-2">Platform</label>
                  <select name="plat" class="input-modern w-full">
                    <option value="">All Platforms</option>
                    <?php foreach(['Facebook','Instagram','TikTok','YouTube','Website','Other'] as $p): ?>
                      <option value="<?= $p ?>" <?= $platF === $p ? 'selected' : ''; ?>><?= $p ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                
                <div>
                  <label class="block text-sm font-semibold text-slate-700 mb-2">From Year</label>
                  <input name="yf" value="<?= htmlspecialchars($yf) ?>" class="input-modern w-full" placeholder="2024">
                </div>
                
                <div>
                  <label class="block text-sm font-semibold text-slate-700 mb-2">To Year</label>
                  <input name="yt" value="<?= htmlspecialchars($yt) ?>" class="input-modern w-full" placeholder="2025">
                </div>
              </div>

              <!-- Action Buttons -->
              <div class="flex flex-wrap gap-2">
                <button class="btn btn-primary">
                  <span>Apply Filters</span>
                </button>
                <a href="?tab=search" class="btn">
                  <span>Clear All</span>
                </a>
                <form method="post" class="inline">
                  <button name="export" value="1" class="btn btn-success">
                    <span>Export CSV</span>
                  </button>
                </form>
              </div>
            </form>
          </div>

          <!-- Results Cards -->
          <div class="card p-4">
            <div class="mb-4">
              <h3 class="font-bold text-lg text-slate-800">Event Results</h3>
              <p class="text-sm text-slate-600 mt-1">
                Showing <?= count($events) ?> of <?= $totalEvents ?> events
                <?php if($totalPages > 1): ?>
                  (Page <?= $page ?> of <?= $totalPages ?>)
                <?php endif; ?>
              </p>
            </div>
            
            <div class="space-y-3">
              <?php
                $printed = 0;
                foreach($events as $ev) {
                  $evLinks = $linksByEvent[$ev['event_id']] ?? [];
                  if($platF !== '') { 
                    $evLinks = array_filter($evLinks, fn($l) => $l['platform'] === $platF); 
                  }

                  $tags = array_filter(array_map('trim', explode(',', (string)($ev['tags'] ?? ''))));
                  
                  echo '<a href="?tab=events&edit=' . (int)$ev['event_id'] . '" class="recent-event-item">';
                  
                  // Event Header
                  echo '<div class="flex items-start justify-between mb-3">';
                  echo '<div class="flex-1">';
                  echo '<div class="font-semibold text-slate-800 text-base mb-1">' . htmlspecialchars($ev['event_name']) . '</div>';
                  echo '<div class="text-xs text-slate-500">#' . (int)$ev['event_id'] . ' • ' . date('M d, Y', strtotime($ev['event_date'])) . '</div>';
                  echo '</div>';
                  
                  // Department
                  if($ev['department']) {
                    echo '<span class="pill-modern ml-3">' . htmlspecialchars($ev['department']) . '</span>';
                  }
                  echo '</div>';
                  
                  // Description
                  if($ev['description']) {
                    echo '<div class="text-sm text-slate-600 mb-3 line-clamp-2">' . htmlspecialchars(substr($ev['description'], 0, 150)) . (strlen($ev['description']) > 150 ? '...' : '') . '</div>';
                  }
                  
                  // Tags and Links Row
                  echo '<div class="flex items-center justify-between">';
                  
                  // Tags
                  echo '<div class="flex flex-wrap gap-1">';
                  if($tags) {
                    foreach(array_slice($tags, 0, 3) as $tg) { 
                      echo '<span class="pill-modern text-xs">' . htmlspecialchars($tg) . '</span>'; 
                    }
                    if(count($tags) > 3) {
                      echo '<span class="text-xs text-slate-500">+' . (count($tags) - 3) . ' more</span>';
                    }
                  } else { 
                    echo '<span class="text-xs text-slate-400">No tags</span>'; 
                  }
                  echo '</div>';
                  
                  // Links
                  if(!empty($evLinks)) {
                    echo '<div class="flex flex-wrap gap-1 ml-3">';
                    foreach($evLinks as $l) {
                      $plat = $l['platform'];
                      $url = htmlspecialchars($l['url']);
                      $chipClass = match($plat) {
                        'Facebook' => 'chip-facebook',
                        'Instagram' => 'chip-instagram',
                        'TikTok' => 'chip-tiktok',
                        'YouTube' => 'chip-youtube',
                        'Website' => 'chip-website',
                        default => 'chip-other'
                      };
                      echo '<a class="chip-modern ' . $chipClass . '" href="' . $url . '" target="_blank" rel="noopener">' . $plat . '</a>';
                    }
                    echo '</div>';
                  }
                  
                  echo '</div>';
                  echo '</a>';
                  $printed++;
                }
                
                if($printed === 0) {
                  echo '<div class="text-center py-12 text-slate-500">
                          <div class="text-lg font-medium mb-2">No events found</div>
                          <div class="text-sm">Try adjusting your search filters or add new events</div>
                        </div>';
                }
              ?>
            </div>

            <!-- Pagination Controls -->
            <?php if($totalPages > 1): ?>
            <div class="mt-4 pt-4 border-t border-slate-200">
              <div class="flex items-center justify-between">
                <div class="text-sm text-slate-600">
                  Page <?= $page ?> of <?= $totalPages ?> (<?= $totalEvents ?> total events)
                </div>
                
                <div class="flex items-center gap-2">
                  <?php if($page > 1): ?>
                    <a href="?tab=search&page=<?= $page - 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?><?= $deptF ? '&dept=' . urlencode($deptF) : '' ?><?= $platF ? '&plat=' . urlencode($platF) : '' ?><?= $yf ? '&yf=' . urlencode($yf) : '' ?><?= $yt ? '&yt=' . urlencode($yt) : '' ?>" 
                       class="btn">
                      <span>← Previous</span>
                    </a>
                  <?php endif; ?>
                  
                  <?php if($page < $totalPages): ?>
                    <a href="?tab=search&page=<?= $page + 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?><?= $deptF ? '&dept=' . urlencode($deptF) : '' ?><?= $platF ? '&plat=' . urlencode($platF) : '' ?><?= $yf ? '&yf=' . urlencode($yf) : '' ?><?= $yt ? '&yt=' . urlencode($yt) : '' ?>" 
                       class="btn btn-primary">
                      <span>Next →</span>
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <!-- Manage Events Tab -->
          <div class="space-y-8">
            
            <!-- Event Form Card -->
            <div class="card p-8">
              <h2 class="font-bold text-xl text-slate-800 mb-6">
                <?= $edit ? 'Edit Event' : 'Create New Event' ?>
              </h2>

              <form method="post" class="space-y-6">
                <?php if($edit): ?>
                  <input type="hidden" name="event_id" value="<?= (int)$edit['event_id'] ?>">
                <?php endif; ?>
                
                <!-- Basic Information -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                  <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Event Name *</label>
                    <input 
                      name="event_name" 
                      value="<?= htmlspecialchars($edit['event_name'] ?? '') ?>" 
                      class="input-modern w-full" 
                      placeholder="Enter event name"
                      required
                    >
                  </div>
                  
                  <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Event Date *</label>
                    <input 
                      type="date" 
                      name="event_date" 
                      value="<?= htmlspecialchars($edit['event_date'] ?? '') ?>" 
                      class="input-modern w-full" 
                      required
                    >
                  </div>
                  
                  <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Department</label>
                    <input 
                      name="department" 
                      value="<?= htmlspecialchars($edit['department'] ?? '') ?>" 
                      class="input-modern w-full" 
                      placeholder="e.g., Computer Studies, Engineering"
                    >
                  </div>
                  
                  <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Tags</label>
                    <input 
                      name="tags" 
                      value="<?= htmlspecialchars($edit['tags'] ?? '') ?>" 
                      class="input-modern w-full" 
                      placeholder="e.g., #CITUAnnouncement, #Freshmen"
                    >
                  </div>
                </div>

                <!-- Description -->
                <div>
                  <label class="block text-sm font-semibold text-slate-700 mb-2">Description</label>
                  <textarea 
                    name="description" 
                    rows="4" 
                    class="input-modern w-full resize-none"
                    placeholder="Enter event description..."
                  ><?= htmlspecialchars($edit['description'] ?? '') ?></textarea>
                </div>

                <!-- First Link (for new events) -->
                <?php if(!$edit): ?>
                <div class="border-t border-slate-200 pt-6">
                  <h3 class="font-semibold text-slate-800 mb-4">Add First Link (Optional)</h3>
                  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div>
                      <label class="block text-sm font-semibold text-slate-700 mb-2">Platform</label>
                      <select name="link_platform" class="input-modern w-full">
                        <option value="">Select Platform</option>
                        <?php foreach(['Facebook','Instagram','TikTok','YouTube','Website','Other'] as $p): ?>
                          <option value="<?= $p ?>"><?= $p ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="lg:col-span-2">
                      <label class="block text-sm font-semibold text-slate-700 mb-2">URL</label>
                      <input 
                        name="link_url" 
                        class="input-modern w-full" 
                        placeholder="https://example.com"
                      >
                    </div>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <?php if($canEdit): ?>
                <div class="flex flex-wrap gap-3 pt-4">
                  <button class="btn btn-primary" type="submit" name="action" value="save_event">
                    <span><?= $edit ? 'Update Event' : 'Create Event' ?></span>
                  </button>
                  <?php if($edit): ?>
                    <a href="?tab=events" class="btn">
                      <span>Cancel / Add New</span>
                    </a>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </form>

              <!-- Delete Button (for editing) -->
              <?php if($edit && $canEdit): ?>
                <div class="mt-6 pt-6 border-t border-slate-200">
                  <form method="post" onsubmit="return confirm('Are you sure you want to delete this event and all its links? This action cannot be undone.')">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_id" value="<?= (int)$edit['event_id'] ?>">
                    <button class="btn btn-danger" type="submit">
                      <span>Delete Event</span>
                    </button>
                  </form>
                </div>
              <?php endif; ?>
            </div>

            <!-- Links Management Card -->
            <?php if($canEdit): ?>
            <div class="card p-8" id="links">
              <div class="flex items-center justify-between mb-6">
                <h3 class="font-bold text-lg text-slate-800">Social Media Links</h3>
                <?php if($edit): ?>
                  <span class="text-sm text-slate-500">Click platform chips to open links</span>
                <?php endif; ?>
              </div>

              <?php if($edit): ?>
                <!-- Add Link Form -->
                <form method="post" class="mb-8">
                  <input type="hidden" name="action" value="add_link">
                  <input type="hidden" name="event_id" value="<?= (int)$edit['event_id'] ?>">
                  
                  <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                    <div>
                      <label class="block text-sm font-semibold text-slate-700 mb-2">Platform</label>
                      <select name="platform" class="input-modern w-full" required>
                        <option value="">Select Platform</option>
                        <?php foreach(['Facebook','Instagram','TikTok','YouTube','Website','Other'] as $p): ?>
                          <option value="<?= $p ?>"><?= $p ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="lg:col-span-2">
                      <label class="block text-sm font-semibold text-slate-700 mb-2">URL</label>
                      <input 
                        name="url" 
                        class="input-modern w-full" 
                        placeholder="https://example.com" 
                        required
                      >
                    </div>
                    <div class="flex items-end">
                      <button class="btn btn-primary w-full">
                        <span>Add Link</span>
                      </button>
                    </div>
                  </div>
                </form>

                <!-- Current Links -->
                <?php
                  $eid = (int)$edit['event_id'];
                  $stmt = $conn->prepare("SELECT * FROM links WHERE event_id=? ORDER BY created_at DESC");
                  $stmt->bind_param("i", $eid);
                  $stmt->execute();
                  $curLinks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                  if(!$curLinks): ?>
                    <div class="text-center py-8">
                      <div class="text-slate-400 text-lg mb-2">No links added yet</div>
                      <div class="text-sm text-slate-500">Add social media links to help users find your event</div>
                    </div>
                  <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                      <?php foreach($curLinks as $l): 
                        $urlRaw = $l['url'];
                        $url = htmlspecialchars($urlRaw);
                        $plat = htmlspecialchars($l['platform']);
                        $chipClass = match($l['platform']) {
                          'Facebook' => 'chip-facebook',
                          'Instagram' => 'chip-instagram',
                          'TikTok' => 'chip-tiktok',
                          'YouTube' => 'chip-youtube',
                          'Website' => 'chip-website',
                          default => 'chip-other'
                        };
                        $shortUrl = strlen($urlRaw) > 50 ? htmlspecialchars(substr($urlRaw, 0, 47) . '...') : $url;
                      ?>
                        <div class="link-card">
                          <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3 mb-3">
                              <a class="chip-modern <?= $chipClass ?>" href="<?= $url ?>" target="_blank" rel="noopener">
                                <?= $plat ?>
                              </a>
                              <span class="text-xs text-slate-400">#<?= (int)$l['id'] ?></span>
                            </div>
                            <div class="font-mono text-sm text-slate-600 truncate" title="<?= htmlspecialchars($urlRaw) ?>">
                              <?= $shortUrl ?>
                            </div>
                          </div>
                          <div class="flex items-center gap-2 flex-shrink-0">
                            <a class="btn text-sm" href="<?= $url ?>" target="_blank" rel="noopener">
                              <span>Open</span>
                            </a>
                            <button class="btn text-sm" type="button" onclick="copyToClipboard('<?= $url ?>')">
                              <span>Copy</span>
                            </button>
                            <form method="post" style="display: inline;" 
                                  onsubmit="return confirm('Delete this link?')">
                              <input type="hidden" name="action" value="delete_link">
                              <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                              <button class="btn btn-danger text-sm">
                                <span>Delete</span>
                              </button>
                            </form>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="text-center py-8">
                    <div class="text-slate-400 text-lg mb-2">Select an event to manage links</div>
                    <div class="text-sm text-slate-500">Choose an event from the table below to add social media links</div>
                  </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Manage Events Table -->
            <div class="card overflow-hidden">
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="bg-gray-100 table-head">
                    <tr>
                      <th class="text-left p-2">Date</th>
                      <th class="text-left p-2">Event</th>
                      <th class="text-left p-2">Dept</th>
                      <th class="text-left p-2">Tags</th> <!-- Added Tags Column -->
                      <th class="text-left p-2">Links</th>
                      <th class="text-left p-2">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($events as $ev): ?>
                      <tr class="border-t hover:bg-gray-50">
                        <td class="p-2 align-top"><?= htmlspecialchars($ev['event_date']) ?></td>
                        <td class="p-2 align-top">
                          <div class="font-medium"><?= htmlspecialchars($ev['event_name']) ?></div>
                          <div class="text-[11px] text-gray-500">#<?= (int)$ev['event_id'] ?></div>
                        </td>
                        <td class="p-2 align-top"><?= htmlspecialchars($ev['department']??'') ?></td>
                        <td class="p-2 align-top">
                          <!-- Display tags here -->
                          <?php 
                            $tags = explode(',', $ev['tags']);
                            if (!empty($tags)) {
                              foreach ($tags as $tag) {
                                echo '<span class="pill mr-1 mb-1 inline-block">' . htmlspecialchars(trim($tag)) . '</span>';
                              }
                            } else {
                              echo '<span class="text-gray-400">No tags</span>';
                            }
                          ?>
                        </td>
                        <td class="p-2 align-top"><?= isset($linksByEvent[$ev['event_id']]) ? count($linksByEvent[$ev['event_id']]) : 0 ?></td>
                        <td class="p-2 align-top">
                          <?php if($canEdit): ?>
                            <a class="btn text-xs" href="?tab=events&edit=<?= (int)$ev['event_id'] ?>">Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this event (and all its links)?');">
                              <input type="hidden" name="action" value="delete_event">
                              <input type="hidden" name="event_id" value="<?= (int)$ev['event_id'] ?>">
                              <button class="btn btn-danger text-xs" type="submit">Delete</button>
                            </form>
                          <?php else: ?>
                            <span class="text-slate-400 text-xs">Read Only</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; if(empty($events)) echo '<tr><td class="p-5 text-center text-gray-500" colspan="6">No events yet.</td></tr>'; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
<?php endif; ?>

<?php
// ====== CSV EXPORT ======
if (post('export') === '1' || (isset($_POST['export']) && $_POST['export'] == '1')) {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="cit_events_export_' . date('Y-m-d_H-i-s') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Event Name', 'Date', 'Department', 'Description', 'Tags', 'Platform', 'URL', 'Event ID']);

  // Get all events for export (not paginated)
  $exportSql = "SELECT * FROM events";
  $exportArgs = []; $exportWhere = [];
  if($search !== ''){ 
    $exportWhere[] = "(event_name LIKE ? OR description LIKE ? OR department LIKE ? OR tags LIKE ?)"; 
    $exportLike = "%$search%"; 
    $exportArgs = [$exportLike, $exportLike, $exportLike, $exportLike]; 
  }
  if($deptF !== ''){ 
    $exportWhere[] = "department=?"; 
    $exportArgs[] = $deptF; 
  }
  if(!empty($exportWhere)) {
    $exportSql .= " WHERE " . implode(" AND ", $exportWhere);
  }
  $exportSql .= " ORDER BY event_date DESC, event_id DESC";

  // Get all events for export
  $exportEvents = [];
  if(!empty($exportArgs)){
    $exportTypes = str_repeat('s', count($exportArgs));
    $exportStmt = $conn->prepare($exportSql);
    $exportStmt->bind_param($exportTypes, ...$exportArgs);
    $exportStmt->execute();
    $exportEvents = $exportStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  } else {
    $exportEvents = $conn->query($exportSql)->fetch_all(MYSQLI_ASSOC);
  }

  // Get links for export events
  $exportLinksByEvent = [];
  if(!empty($exportEvents)){
    $exportIds = array_column($exportEvents, 'event_id');
    $exportIn = implode(',', array_fill(0, count($exportIds), '?'));
    $exportTypes = str_repeat('i', count($exportIds));
    $exportStmt = $conn->prepare("SELECT * FROM links WHERE event_id IN ($exportIn) ORDER BY created_at DESC");
    $exportStmt->bind_param($exportTypes, ...$exportIds);
    $exportStmt->execute();
    $exportRes = $exportStmt->get_result();
    while($exportRow = $exportRes->fetch_assoc()){
      $exportLinksByEvent[$exportRow['event_id']][] = $exportRow;
    }
  }

  foreach($exportEvents as $ev) {
    $evLinks = $exportLinksByEvent[$ev['event_id']] ?? [];
    if($platF !== '') { 
      $evLinks = array_filter($evLinks, fn($l) => $l['platform'] === $platF); 
    }
    
    if(empty($evLinks)) {
      fputcsv($out, [
        $ev['event_name'],
        $ev['event_date'],
        $ev['department'] ?? '',
        $ev['description'] ?? '',
        $ev['tags'] ?? '',
        '',
        '',
        $ev['event_id']
      ]);
    } else {
      foreach($evLinks as $l) {
        fputcsv($out, [
          $ev['event_name'],
          $ev['event_date'],
          $ev['department'] ?? '',
          $ev['description'] ?? '',
          $ev['tags'] ?? '',
          $l['platform'],
          $l['url'],
          $ev['event_id']
        ]);
      }
    }
  }
  fclose($out);
  exit;
}
?>

<!-- Toast Notification -->
<div id="toast" class="toast-modern">
  <span id="toast-message">Copied to clipboard!</span>
</div>

<!-- Event Details Modal
<div id="eventModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="modalEventName" class="modal-title"></h2>
      <button class="modal-close" onclick="closeEventModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="event-details-grid">
        <div class="detail-section">
          <h3 class="detail-label">Event Information</h3>
          <div class="detail-content">
            <div class="detail-item">
              <span class="detail-key">Event Name:</span>
              <span id="modalEventNameContent" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <span class="detail-key">Date:</span>
              <span id="modalEventDate" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <span class="detail-key">Department:</span>
              <span id="modalEventDept" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <span class="detail-key">Event ID:</span>
              <span id="modalEventId" class="detail-value"></span>
            </div>
          </div>
        </div>
        
        <div class="detail-section">
          <h3 class="detail-label">Description</h3>
          <div id="modalEventDesc" class="detail-description"></div>
        </div>
        
        <div class="detail-section">
          <h3 class="detail-label">Tags</h3>
          <div id="modalEventTags" class="detail-tags"></div>
        </div>
        
        <div class="detail-section">
          <h3 class="detail-label">Social Media Links</h3>
          <div id="modalEventLinks" class="detail-links"></div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-primary" onclick="closeEventModal()">
        <span>Close</span>
      </button>
    </div>
  </div>
</div> -->

<script>
function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(() => {
    showToast('Link copied to clipboard!');
  }).catch(() => {
    // Fallback for older browsers
    const textArea = document.createElement('textarea');
    textArea.value = text;
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);
    showToast('Link copied to clipboard!');
  });
}

function showToast(message) {
  const toast = document.getElementById('toast');
  const messageEl = document.getElementById('toast-message');
  
  messageEl.textContent = message;
  toast.classList.add('show');
  
  setTimeout(() => {
    toast.classList.remove('show');
  }, 3000);
}

// Event details modal functions
function showEventDetails(eventId) {
  console.log('showEventDetails called with eventId:', eventId);
  // Find the event data from the current page
  const eventData = getEventDataFromTable(eventId);
  console.log('eventData found:', eventData);
  if (!eventData) {
    showToast('Event data not found');
    return;
  }
  
  // Populate modal with event data
  document.getElementById('modalEventName').textContent = eventData.name;
  document.getElementById('modalEventNameContent').textContent = eventData.name;
  document.getElementById('modalEventDate').textContent = eventData.date;
  document.getElementById('modalEventDept').textContent = eventData.department || 'Not specified';
  document.getElementById('modalEventId').textContent = '#' + eventData.id;
  document.getElementById('modalEventDesc').textContent = eventData.description || 'No description provided';
  
  // Populate tags
  const tagsContainer = document.getElementById('modalEventTags');
  if (eventData.tags && eventData.tags.length > 0) {
    tagsContainer.innerHTML = eventData.tags.map(tag => 
      `<span class="pill-modern">${tag}</span>`
    ).join('');
  } else {
    tagsContainer.innerHTML = '<span class="text-slate-400">No tags</span>';
  }
  
  // Populate links
  const linksContainer = document.getElementById('modalEventLinks');
  if (eventData.links && eventData.links.length > 0) {
    linksContainer.innerHTML = eventData.links.map(link => {
      const chipClass = getChipClass(link.platform);
      return `<a class="chip-modern ${chipClass}" href="${link.url}" target="_blank" rel="noopener">${link.platform}</a>`;
    }).join('');
  } else {
    linksContainer.innerHTML = '<span class="text-slate-400">No links available</span>';
  }
  
  // Show modal
  document.getElementById('eventModal').classList.add('show');
}

function closeEventModal() {
  document.getElementById('eventModal').classList.remove('show');
}

function getEventDataFromTable(eventId) {
  // Extract event data from the card layout
  const cards = document.querySelectorAll('.recent-event-item');
  console.log('Found cards:', cards.length);
  for (let card of cards) {
    const eventIdText = card.querySelector('.text-xs.text-slate-500');
    console.log('Checking card with text:', eventIdText ? eventIdText.textContent : 'No text found');
    if (eventIdText && eventIdText.textContent.includes('#' + eventId)) {
      const nameCell = card.querySelector('.font-semibold.text-slate-800');
      const dateText = eventIdText.textContent.split(' • ')[1];
      
      // Find department pill (it might be in the header section)
      const headerSection = card.querySelector('.flex.items-start.justify-between');
      const deptCell = headerSection ? headerSection.querySelector('.pill-modern') : null;
      
      const descCell = card.querySelector('.text-sm.text-slate-600');
      
      // Get tags from the bottom section (exclude department pill)
      const bottomSection = card.querySelector('.flex.items-center.justify-between');
      const tagsCells = bottomSection ? bottomSection.querySelectorAll('.pill-modern') : [];
      const linkCells = card.querySelectorAll('.chip-modern');
      
      const tags = Array.from(tagsCells).map(cell => cell.textContent.trim()).filter(tag => tag);
      const links = Array.from(linkCells).map(cell => ({
        platform: cell.textContent.trim(),
        url: cell.href
      }));
      
      return {
        id: eventId,
        name: nameCell ? nameCell.textContent.trim() : '',
        date: dateText ? dateText.trim() : '',
        department: deptCell ? deptCell.textContent.trim() : '',
        description: descCell ? descCell.textContent.trim() : '',
        tags: tags,
        links: links
      };
    }
  }
  return null;
}

function getChipClass(platform) {
  const platformMap = {
    'Facebook': 'chip-facebook',
    'Instagram': 'chip-instagram',
    'TikTok': 'chip-tiktok',
    'YouTube': 'chip-youtube',
    'Website': 'chip-website'
  };
  return platformMap[platform] || 'chip-other';
}

// Add smooth scroll behavior for anchor links
document.addEventListener('DOMContentLoaded', function() {
  // Smooth scrolling for internal links
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
      e.preventDefault();
      const target = document.querySelector(this.getAttribute('href'));
      if(target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  // Close modal when clicking outside
  document.getElementById('eventModal').addEventListener('click', function(e) {
    if (e.target === this) {
      closeEventModal();
    }
  });

  // Close modal with Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeEventModal();
    }
  });
});
</script>
</body>
</html