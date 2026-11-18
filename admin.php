<?php
/**
 * 下载站后台管理（admin.php）
 * 功能：登录、浏览文件、上传文件、建文件夹、删除、重命名、
 *       编辑网址收藏（links.json）、编辑目录说明（desc.txt）、
 *       改站名、改管理员密码、给文件夹设密码/取消加密。
 * 安全：必须登录（session）；所有路径限制在 files/ 内；禁止上传可执行脚本与隐藏文件；
 *       配置与文件都在网站根目录之外的数据目录里，网址无法直接访问。
 */

// ---------- 安全设置 ----------
ini_set('display_errors', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

// ---------- 数据目录：网站根目录的同级 download-site-data/（不在 web 根目录内） ----------
$DATA_DIR = dirname(__DIR__) . '/download-site-data';
$CONFIG_FILE = $DATA_DIR . '/config.php';
$BASE_DIR = $DATA_DIR . '/files';
$CFG = is_file($CONFIG_FILE) ? require $CONFIG_FILE : [];
$CONFIG_OK = is_file($CONFIG_FILE) && !empty($CFG['admin_pass_hash']);
if (!isset($CFG['folder_locks']) || !is_array($CFG['folder_locks'])) $CFG['folder_locks'] = [];
if (!isset($CFG['max_upload_bytes'])) $CFG['max_upload_bytes'] = 209715200;

// ---------- 工具 ----------
function safe_rel($rel) {
    $rel = trim(str_replace('\\', '/', (string)$rel), '/');
    $parts = [];
    foreach (explode('/', $rel) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { array_pop($parts); continue; }
        $parts[] = $p;
    }
    return implode('/', $parts);
}
function human_size($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024 * 1024) return round($b / 1024, 1) . ' KB';
    if ($b < 1024 * 1024 * 1024) return round($b / 1024 / 1024, 1) . ' MB';
    return round($b / 1024 / 1024 / 1024, 2) . ' GB';
}
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok() {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
}
function redirect($url) { header('Location: ' . $url); exit; }
function flash($msg, $ok = true) { $_SESSION['flash'] = ['msg' => $msg, 'ok' => $ok]; }
function get_flash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function save_config($cfg) {
    global $CONFIG_FILE, $DATA_DIR;
    if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0755, true);
    file_put_contents($CONFIG_FILE, "<?php\nreturn " . var_export($cfg, true) . ";\n");
    if (function_exists('opcache_invalidate')) opcache_invalidate($CONFIG_FILE, true);
}
// 文件名是否安全：禁止隐藏文件（.开头）与系统保留名
function bad_filename($name) {
    $n = strtolower(basename($name));
    if ($n === '' || $n === '.' || $n === '..') return true;
    if (strpos($n, '.') === 0) return true; // .htaccess、.env 等
    if (in_array($n, ['htaccess', 'htpasswd', 'web.config'], true)) return true;
    return false;
}

// 禁止上传的扩展名（可被服务器执行的脚本）
$BLOCKED_EXT = ['php','phtml','phar','php3','php4','php5','cgi','pl','py','sh','bash','htaccess','ini','conf','sql','exe','com','bat','cmd'];

// ---------- 登录 / 登出 ----------
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'logout') {
    session_destroy();
    redirect('admin.php');
}
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    if ($CONFIG_OK && password_verify($pw, $CFG['admin_pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        redirect('admin.php');
    }
    sleep(1); // 拖慢暴力破解
    flash('密码不对，请再试一次。', false);
    redirect('admin.php');
}
$logged_in = !empty($_SESSION['admin']);
if (!$logged_in) {
    $flash = get_flash();
    ?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>后台登录 - <?= htmlspecialchars($CFG['site_name'] ?? '我的下载站', ENT_QUOTES) ?></title>
<link rel="stylesheet" href="style.css"></head>
<body>
<div class="topbar"><div class="topbar-inner"><div class="brand">🔐 后台登录</div></div></div>
<main class="main" style="max-width:420px;margin:40px auto;padding:24px;">
    <h3 style="margin-top:0;">请输入管理员密码</h3>
    <?php if (!$CONFIG_OK): ?>
        <p style="color:#a50e0e;">还没有配置文件。请把 <code>config.example.php</code> 复制为数据目录下的 <code>config.php</code>（默认在网站目录同级的 <code>download-site-data/</code>），并在里面设置管理员密码（用 <code>password_hash()</code> 生成哈希）。</p>
    <?php endif; ?>
    <?php if ($flash): ?><p style="color:<?= $flash['ok'] ? 'green' : 'red' ?>;"><?= htmlspecialchars($flash['msg'], ENT_QUOTES) ?></p><?php endif; ?>
    <form method="post" action="admin.php">
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" placeholder="管理员密码" required autofocus
               style="width:100%;padding:10px;font-size:15px;border:1px solid #ccc;border-radius:6px;">
        <button type="submit" style="width:100%;margin-top:12px;padding:10px;font-size:15px;background:#8e44ad;color:#fff;border:none;border-radius:6px;cursor:pointer;">登录</button>
    </form>
    <p style="color:#888;font-size:13px;margin-top:16px;"><a href="./">← 回到前台</a></p>
</main>
</body></html>
    <?php
    exit;
}

// ---------- 已登录：处理操作 ----------
$dir_rel = safe_rel($_GET['dir'] ?? $_POST['dir'] ?? '');
$dir_abs = $BASE_DIR . ($dir_rel === '' ? '' : '/' . $dir_rel);
if (!is_dir($dir_abs)) { $dir_rel = ''; $dir_abs = $BASE_DIR; }
$self = 'admin.php';
$here = $self . '?dir=' . urlencode($dir_rel);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('表单已过期，请重新操作。', false); redirect($here); }

    // 上传文件
    if ($action === 'upload' && !empty($_FILES['files'])) {
        $ok_n = 0; $fail = [];
        $total = count($_FILES['files']['name']);
        for ($i = 0; $i < $total; $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) { $fail[] = $_FILES['files']['name'][$i] . '（上传失败）'; continue; }
            $name = basename($_FILES['files']['name'][$i]);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (bad_filename($name)) { $fail[] = $name . '（不允许的文件名）'; continue; }
            if (in_array($ext, $BLOCKED_EXT, true)) { $fail[] = $name . '（不允许的类型）'; continue; }
            if ($_FILES['files']['size'][$i] > $CFG['max_upload_bytes']) { $fail[] = $name . '（超过 ' . human_size($CFG['max_upload_bytes']) . '）'; continue; }
            $dest = $dir_abs . '/' . $name;
            if (file_exists($dest)) { $fail[] = $name . '（已存在同名文件）'; continue; }
            if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) $ok_n++; else $fail[] = $name . '（保存失败）';
        }
        $msg = "上传完成：成功 {$ok_n} 个";
        if ($fail) $msg .= '；失败：' . implode('、', $fail);
        flash($msg, empty($fail));
        redirect($here);
    }

    // 新建文件夹
    if ($action === 'mkdir') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || strpbrk($name, "/\\") !== false || bad_filename($name)) { flash('文件夹名不合法。', false); }
        elseif (file_exists($dir_abs . '/' . $name)) { flash('已存在同名文件或文件夹。', false); }
        elseif (mkdir($dir_abs . '/' . $name, 0755)) { flash("已新建文件夹：{$name}"); }
        else { flash('新建失败。', false); }
        redirect($here);
    }

    // 删除
    if ($action === 'delete') {
        $target = safe_rel((string)($_POST['target'] ?? ''));
        $path = $BASE_DIR . '/' . $target;
        if ($target === '' || !file_exists($path)) { flash('目标不存在。', false); }
        elseif (is_dir($path)) {
            // 只删空文件夹，防止误删
            if (count(scandir($path)) > 2) { flash('文件夹不为空，请先清空再删。', false); }
            elseif (rmdir($path)) { flash("已删除文件夹：{$target}"); }
            else { flash('删除失败。', false); }
        } else {
            if (unlink($path)) { flash("已删除：{$target}"); } else { flash('删除失败。', false); }
        }
        redirect($here);
    }

    // 重命名
    if ($action === 'rename') {
        $old = safe_rel((string)($_POST['old'] ?? ''));
        $new = trim((string)($_POST['new'] ?? ''));
        $old_path = $BASE_DIR . '/' . $old;
        if ($old === '' || !file_exists($old_path)) { flash('原文件不存在。', false); }
        elseif ($new === '' || strpbrk($new, "/\\") !== false || bad_filename($new)) { flash('新名字不合法。', false); }
        else {
            $new_path = dirname($old_path) . '/' . $new;
            $ext = strtolower(pathinfo($new, PATHINFO_EXTENSION));
            if (!is_dir($old_path) && in_array($ext, $BLOCKED_EXT, true)) { flash('新名字用了不允许的扩展名。', false); }
            elseif (file_exists($new_path)) { flash('已存在同名文件。', false); }
            elseif (rename($old_path, $new_path)) { flash("已重命名：{$old} → {$new}"); }
            else { flash('重命名失败。', false); }
        }
        redirect($here);
    }

    // 保存网址收藏 links.json
    if ($action === 'save_links') {
        $raw = (string)($_POST['links_json'] ?? '[]');
        $data = json_decode($raw, true);
        if (!is_array($data)) { flash('JSON 格式不对，没保存。', false); }
        else {
            file_put_contents($dir_abs . '/links.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            flash('网址收藏已保存。');
        }
        redirect($here);
    }

    // 保存目录说明 desc.txt
    if ($action === 'save_desc') {
        file_put_contents($dir_abs . '/desc.txt', (string)($_POST['desc'] ?? ''));
        flash('目录说明已保存。');
        redirect($here);
    }

    // 改站名
    if ($action === 'save_site') {
        $name = trim((string)($_POST['site_name'] ?? ''));
        $desc = trim((string)($_POST['site_desc'] ?? ''));
        if ($name === '') { flash('站名不能为空。', false); }
        else {
            $cfg = $CFG; $cfg['site_name'] = $name; $cfg['site_desc'] = $desc;
            save_config($cfg);
            flash('站点设置已保存。');
        }
        redirect($here);
    }

    // 改密码
    if ($action === 'save_pass') {
        $old = (string)($_POST['old_pass'] ?? '');
        $new = (string)($_POST['new_pass'] ?? '');
        $new2 = (string)($_POST['new_pass2'] ?? '');
        if (!password_verify($old, $CFG['admin_pass_hash'])) { flash('旧密码不对。', false); }
        elseif (strlen($new) < 6) { flash('新密码至少 6 位。', false); }
        elseif ($new !== $new2) { flash('两次输入的新密码不一致。', false); }
        else {
            $cfg = $CFG; $cfg['admin_pass_hash'] = password_hash($new, PASSWORD_DEFAULT);
            save_config($cfg);
            flash('密码已修改，下次用新密码登录。');
        }
        redirect($here);
    }

    // 给文件夹设密码
    if ($action === 'set_lock') {
        $target = safe_rel((string)($_POST['target'] ?? ''));
        $pw = (string)($_POST['lock_pass'] ?? '');
        $path = $BASE_DIR . '/' . $target;
        if ($target === '' || !is_dir($path)) { flash('目标文件夹不存在。', false); }
        elseif (strlen($pw) < 1) { flash('密码不能为空。', false); }
        else {
            $cfg = $CFG;
            if (!isset($cfg['folder_locks']) || !is_array($cfg['folder_locks'])) $cfg['folder_locks'] = [];
            $cfg['folder_locks'][$target] = password_hash($pw, PASSWORD_DEFAULT);
            save_config($cfg);
            flash("已给文件夹加密：{$target}");
        }
        redirect($here);
    }

    // 移除文件夹密码
    if ($action === 'remove_lock') {
        $target = safe_rel((string)($_POST['target'] ?? ''));
        $cfg = $CFG;
        if (isset($cfg['folder_locks'][$target])) {
            unset($cfg['folder_locks'][$target]);
            save_config($cfg);
            flash("已取消文件夹加密：{$target}");
        } else {
            flash('该文件夹本来就没加密。', false);
        }
        redirect($here);
    }
}

// ---------- 列表 ----------
$entries = [];
if (is_dir($dir_abs)) {
    foreach (scandir($dir_abs) as $item) {
        if ($item === '.' || $item === '..' || $item === 'desc.txt' || $item === 'links.json') continue;
        if (strpos($item, '.') === 0) continue; // 隐藏文件不显示
        $full = $dir_abs . '/' . $item;
        $entries[] = ['name' => $item, 'is_dir' => is_dir($full), 'size' => is_dir($full) ? null : filesize($full), 'mtime' => filemtime($full), 'rel' => ($dir_rel === '' ? $item : $dir_rel . '/' . $item)];
    }
}
usort($entries, function ($a, $b) {
    if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});
$crumbs = [['name' => '根目录', 'rel' => '']];
if ($dir_rel !== '') {
    $acc = '';
    foreach (explode('/', $dir_rel) as $part) { $acc = $acc === '' ? $part : $acc . '/' . $part; $crumbs[] = ['name' => $part, 'rel' => $acc]; }
}
$links_json = is_file($dir_abs . '/links.json') ? file_get_contents($dir_abs . '/links.json') : "[]";
$desc_txt = is_file($dir_abs . '/desc.txt') ? file_get_contents($dir_abs . '/desc.txt') : '';
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>后台管理 - <?= htmlspecialchars($CFG['site_name'] ?? '我的下载站', ENT_QUOTES) ?></title>
<link rel="stylesheet" href="style.css">
<style>
/* 后台样式：与前台同款紫色系，电脑端宽屏两栏、手机端单栏 */
.admin-wrap { width:96%; max-width:1600px; margin:20px auto; padding:0 14px 40px; }
.card { background:#fff; border:1px solid #e6d9f2; border-radius:8px; padding:18px; margin-bottom:16px; }
.card h3 { margin:0 0 12px; font-size:17px; color:#6c3483; }
.card label { display:block; font-size:13px; color:#666; margin:10px 0 4px; }
.card input[type=text], .card input[type=password], .card textarea { width:100%; padding:10px; font-size:14px; border:1px solid #d5c6e8; border-radius:6px; box-sizing:border-box; }
.card input[type=text]:focus, .card input[type=password]:focus, .card textarea:focus { outline:none; border-color:#8e44ad; box-shadow:0 0 0 2px rgba(142,68,173,.15); }
.card textarea { min-height:90px; font-family:monospace; }
.btn { display:inline-block; padding:9px 18px; background:#8e44ad; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; }
.btn:hover { background:#7d3c98; }
.btn.danger { background:#d93025; } .btn.danger:hover { background:#b3271e; }
.btn.ghost { background:#f0e6f7; color:#6c3483; } .btn.ghost:hover { background:#e2d0f0; }
.flash { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; }
.flash.ok { background:#e6f4ea; color:#137333; border:1px solid #a8dab5; } .flash.err { background:#fce8e6; color:#a50e0e; border:1px solid #f5a9a2; }
/* 文件列表：深紫通栏标题 + 表格式行 */
.file-card { padding:0; overflow:hidden; }
.file-card-head { background:#6c3483; color:#fff; font-weight:700; padding:12px 18px; font-size:16px; letter-spacing:1px; }
.file-card-head a { color:#e8d5f5; }
.file-card-body { padding:6px 18px 12px; }
.arow { display:flex; align-items:center; gap:10px; padding:11px 4px; border-bottom:1px solid #f3eef7; font-size:14px; flex-wrap:wrap; }
.arow:last-child { border-bottom:none; }
.arow:hover { background:#faf5fd; }
.arow .nm { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.arow .sz { color:#888; font-size:13px; width:80px; text-align:right; }
.arow form { display:inline; }
.arow input[type=text] { width:130px; padding:6px 8px; font-size:13px; border:1px solid #d5c6e8; border-radius:5px; }
.top-actions { display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
.top-actions a { color:#fff; font-size:14px; }
/* 操作卡片：电脑端两栏、手机端单栏 */
.admin-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start; }
.admin-grid .card { margin-bottom:0; }
@media (max-width: 960px) {
    .admin-wrap { width:100%; padding:0 8px 32px; margin:12px auto; }
    .admin-grid { grid-template-columns:1fr; }
    .card { padding:14px; }
    .arow .sz { display:none; }
    .arow input[type=text] { width:110px; }
    .top-actions { gap:10px; }
}
@media (min-width: 1600px) {
    .card input[type=text], .card input[type=password], .card textarea { font-size:15px; }
}
</style>
</head>
<body>
<div class="topbar"><div class="topbar-inner">
    <div class="brand">🛠️ 后台管理</div>
    <div class="meta"><?= htmlspecialchars($CFG['site_name'] ?? '我的下载站', ENT_QUOTES) ?></div>
    <div class="top-actions" style="margin-left:auto;">
        <a href="./" target="_blank">查看前台 ↗</a>
        <a href="admin.php?action=logout">退出登录</a>
    </div>
</div></div>

<div class="admin-wrap">
    <?php if ($flash): ?><div class="flash <?= $flash['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES) ?></div><?php endif; ?>

    <div class="card file-card">
        <div class="file-card-head">📂 当前目录：
            <?php foreach ($crumbs as $i => $c): ?>
                <?php if ($i > 0): ?>/<?php endif; ?>
                <a href="<?= $self ?>?dir=<?= urlencode($c['rel']) ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="file-card-body">
        <?php if (empty($entries)): ?><p style="color:#999;">这个目录是空的，先上传文件或新建文件夹。</p><?php endif; ?>
        <?php foreach ($entries as $e): ?>
            <div class="arow">
                <span><?= $e['is_dir'] ? '📁' : '📄' ?></span>
                <?php if ($e['is_dir']): ?>
                    <a class="nm" href="<?= $self ?>?dir=<?= urlencode($e['rel']) ?>"><?= htmlspecialchars($e['name'], ENT_QUOTES) ?></a>
                <?php else: ?>
                    <span class="nm"><?= htmlspecialchars($e['name'], ENT_QUOTES) ?></span>
                <?php endif; ?>
                <span class="sz"><?= $e['size'] === null ? '' : human_size($e['size']) ?></span>
                <span style="color:#aaa;font-size:12px;"><?= date('m-d H:i', $e['mtime']) ?></span>
                <form method="post" action="<?= $here ?>" onsubmit="return confirm('重命名 <?= htmlspecialchars($e['name'], ENT_QUOTES) ?>？');">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
                    <input type="hidden" name="old" value="<?= htmlspecialchars($e['rel'], ENT_QUOTES) ?>">
                    <input type="text" name="new" value="<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>" required>
                    <button class="btn ghost" type="submit">改名</button>
                </form>
                <form method="post" action="<?= $here ?>" onsubmit="return confirm('确定删除 <?= htmlspecialchars($e['name'], ENT_QUOTES) ?>？<?= $e['is_dir'] ? '（只删空文件夹）' : '' ?>');">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
                    <input type="hidden" name="target" value="<?= htmlspecialchars($e['rel'], ENT_QUOTES) ?>">
                    <button class="btn danger" type="submit">删除</button>
                </form>
                <?php if ($e['is_dir']): ?>
                    <?php $is_locked = isset($CFG['folder_locks'][$e['rel']]); ?>
                    <?php if ($is_locked): ?>
                        <form method="post" action="<?= $here ?>" onsubmit="return confirm('取消“<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>”的加密？');">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="remove_lock">
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
                            <input type="hidden" name="target" value="<?= htmlspecialchars($e['rel'], ENT_QUOTES) ?>">
                            <button class="btn ghost" type="submit" title="已加密，点击取消">🔒 已加密</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= $here ?>">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="set_lock">
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
                            <input type="hidden" name="target" value="<?= htmlspecialchars($e['rel'], ENT_QUOTES) ?>">
                            <input type="text" name="lock_pass" placeholder="设文件夹密码" required style="width:110px;">
                            <button class="btn ghost" type="submit">🔒 加密</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="admin-grid">
    <div class="card">
        <h3>⬆️ 上传文件到当前目录</h3>
        <form method="post" action="<?= $here ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
            <input type="file" name="files[]" multiple required>
            <button class="btn" type="submit" style="margin-left:8px;">上传</button>
        </form>
        <p style="color:#888;font-size:13px;">可一次选多个文件；单个不超过 <?= human_size($CFG['max_upload_bytes']) ?>；同名文件不会覆盖；PHP 等脚本类文件与隐藏文件禁止上传。</p>
    </div>

    <div class="card">
        <h3>📁 新建文件夹</h3>
        <form method="post" action="<?= $here ?>">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="mkdir">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
            <input type="text" name="name" placeholder="文件夹名，例如：软件" required style="max-width:300px;">
            <button class="btn" type="submit">新建</button>
        </form>
    </div>

    <div class="card">
        <h3>🔗 网址收藏（当前目录的 links.json）</h3>
        <form method="post" action="<?= $here ?>">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_links">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
            <textarea name="links_json"><?= htmlspecialchars($links_json, ENT_QUOTES) ?></textarea>
            <button class="btn" type="submit" style="margin-top:8px;">保存网址收藏</button>
        </form>
        <p style="color:#888;font-size:13px;">格式：[{"title":"百度一下","url":"https://www.baidu.com"}]，不懂就照这个样子改。</p>
    </div>

    <div class="card">
        <h3>📝 目录说明（当前目录的 desc.txt）</h3>
        <form method="post" action="<?= $here ?>">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_desc">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
            <textarea name="desc"><?= htmlspecialchars($desc_txt, ENT_QUOTES) ?></textarea>
            <button class="btn" type="submit" style="margin-top:8px;">保存说明</button>
        </form>
    </div>

    <div class="card">
        <h3>⚙️ 站点设置</h3>
        <form method="post" action="<?= $here ?>">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_site">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
            <label>站名</label>
            <input type="text" name="site_name" value="<?= htmlspecialchars($CFG['site_name'] ?? '', ENT_QUOTES) ?>" required>
            <label>副标题</label>
            <input type="text" name="site_desc" value="<?= htmlspecialchars($CFG['site_desc'] ?? '', ENT_QUOTES) ?>">
            <button class="btn" type="submit" style="margin-top:10px;">保存设置</button>
        </form>
    </div>

    <div class="card">
        <h3>🔑 修改管理员密码</h3>
        <form method="post" action="<?= $here ?>">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_pass">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir_rel, ENT_QUOTES) ?>">
            <label>旧密码</label>
            <input type="password" name="old_pass" required>
            <label>新密码（至少 6 位）</label>
            <input type="password" name="new_pass" required>
            <label>再输一次新密码</label>
            <input type="password" name="new_pass2" required>
            <button class="btn" type="submit" style="margin-top:10px;">修改密码</button>
        </form>
    </div>
    </div>
</div>
</body>
</html>
