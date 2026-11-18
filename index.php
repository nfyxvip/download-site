<?php
/**
 * 简易下载站（仿永硕E盘样式，带文件夹加密）
 * 用法：把文件/文件夹放进数据目录的 files/ 里，页面会自动列出来。
 * - 文件夹：点击进入子目录；带 🔒 的文件夹需要输入密码才能打开
 * - 图片：点击在线预览
 * - 其他文件：点击下载
 * - 网址：在某个目录里放一个 links.json（[{"title":"百度","url":"https://www.baidu.com"}]），会显示为链接行
 * - 目录说明：在某个目录里放一个 desc.txt，会显示在列表上方
 * - 文件夹密码：在后台 admin.php 里给某个文件夹设密码（只存哈希）；管理员登录后可直接打开所有文件夹
 *
 * 安全设计：
 * - 配置（config.php）和文件（files/）都放在网站根目录之外的数据目录里，
 *   网址无法直接访问到它们，文件夹加密不会被直接猜链接绕过。
 *   默认数据目录是网站目录同级的 download-site-data/。
 * - 所有路径都被限制在 files/ 目录内，禁止 ../ 跳出；下载与图片预览同样受文件夹密码保护。
 * - 上传禁止脚本类文件和隐藏文件（.htaccess 等）。
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

// ---------- 配置 ----------
$CFG = is_file($CONFIG_FILE) ? require $CONFIG_FILE : [];
$SITE_NAME = $CFG['site_name'] ?? '我的下载站';
$SITE_DESC  = $CFG['site_desc'] ?? '文件分享 · 点击下载';
$LOCKS = $CFG['folder_locks'] ?? [];

// ---------- 工具函数 ----------
function human_size($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 1) . ' MB';
    return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

// 清理用户传入的相对路径，只允许在 files/ 内
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

function file_icon($name, $is_dir) {
    if ($is_dir) return '📁';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'png' => '🖼️', 'jpg' => '🖼️', 'jpeg' => '🖼️', 'gif' => '🖼️', 'webp' => '🖼️', 'bmp' => '🖼️',
        'zip' => '📦', 'rar' => '📦', '7z' => '📦', 'tar' => '📦', 'gz' => '📦',
        'exe' => '💻', 'msi' => '💻', 'apk' => '📱',
        'mp3' => '🎵', 'wav' => '🎵', 'flac' => '🎵',
        'mp4' => '🎬', 'mkv' => '🎬', 'avi' => '🎬', 'mov' => '🎬',
        'pdf' => '📄', 'doc' => '📄', 'docx' => '📄', 'xls' => '📄', 'xlsx' => '📄', 'ppt' => '📄', 'pptx' => '📄',
        'txt' => '📝', 'md' => '📝',
    ];
    return $map[$ext] ?? '📄';
}

function is_image($name) {
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['png','jpg','jpeg','gif','webp','bmp']);
}

// 只允许 http/https 链接（防 javascript: 等）
function safe_url($url) {
    $url = trim((string)$url);
    if (preg_match('#^https?://#i', $url)) return $url;
    return '';
}

// ---------- 文件夹加密 ----------
// 管理员（已在 admin.php 登录）可直接打开所有文件夹
function is_admin() { return !empty($_SESSION['admin']); }

// 返回从根目录到 $rel 的链路上，第一个（最外层）已设密码且本次会话还没解锁的文件夹；没有则返回 null
function locked_ancestor($rel) {
    global $LOCKS;
    if (is_admin()) return null;
    $rel = safe_rel($rel);
    if ($rel === '') return null;
    $unlocked = $_SESSION['unlocked'] ?? [];
    $acc = '';
    foreach (explode('/', $rel) as $part) {
        $acc = $acc === '' ? $part : $acc . '/' . $part;
        if (isset($LOCKS[$acc]) && !in_array($acc, $unlocked, true)) return $acc;
    }
    return null;
}

// 某个文件夹本身是否设了密码
function folder_is_locked($rel) {
    global $LOCKS;
    return isset($LOCKS[safe_rel($rel)]);
}

// ---------- 解锁提交：POST action=unlock ----------
$unlock_error = '';
if (($_POST['action'] ?? '') === 'unlock') {
    $target = safe_rel($_POST['dir'] ?? '');
    $pw = (string)($_POST['password'] ?? '');
    if ($target !== '' && isset($LOCKS[$target]) && password_verify($pw, $LOCKS[$target])) {
        $_SESSION['unlocked'] = array_values(array_unique(array_merge($_SESSION['unlocked'] ?? [], [$target])));
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($target));
        exit;
    }
    $unlock_error = '密码不对，请再试一次。';
    $_GET['dir'] = $target;
}

// ---------- 下载处理：?dl=相对路径 ----------
if (isset($_GET['dl'])) {
    $rel = safe_rel($_GET['dl']);
    $path = $BASE_DIR . '/' . $rel;
    if ($rel === '' || !is_file($path)) { http_response_code(404); exit('文件不存在'); }
    // 文件所在文件夹（或其上级）若已加密且未解锁，跳到密码页
    $parent = dirname($rel);
    if ($parent === '.') $parent = '';
    $locked = locked_ancestor($parent);
    if ($locked !== null) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($locked));
        exit;
    }
    $name = basename($path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private');
    readfile($path);
    exit;
}

// ---------- 图片直出（用于预览）：?img=相对路径 ----------
if (isset($_GET['img'])) {
    $rel = safe_rel($_GET['img']);
    $path = $BASE_DIR . '/' . $rel;
    if ($rel === '' || !is_file($path) || !is_image($rel)) { http_response_code(404); exit('图片不存在'); }
    $parent = dirname($rel);
    if ($parent === '.') $parent = '';
    if (locked_ancestor($parent) !== null) { http_response_code(403); exit('该文件夹已加密'); }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---------- 当前目录 ----------
$dir_rel = safe_rel($_GET['dir'] ?? '');
$dir_abs = $BASE_DIR . ($dir_rel === '' ? '' : '/' . $dir_rel);
if (!is_dir($dir_abs)) { $dir_rel = ''; $dir_abs = $BASE_DIR; }

// 当前目录（或其上级）是否被锁住、需要先输密码
$locked_folder = locked_ancestor($dir_rel);

// 读目录说明
$desc = '';
if (is_file($dir_abs . '/desc.txt')) {
    $desc = trim(file_get_contents($dir_abs . '/desc.txt'));
}

// 读网址收藏
$links = [];
if (is_file($dir_abs . '/links.json')) {
    $links = json_decode(file_get_contents($dir_abs . '/links.json'), true) ?: [];
}

// 列出条目：文件夹在前，文件在后，各按名字排序
$entries = [];
if (is_dir($dir_abs)) {
    foreach (scandir($dir_abs) as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item === 'desc.txt' || $item === 'links.json') continue; // 配置文件不显示
        if (strpos($item, '.') === 0) continue; // 隐藏文件不显示
        $full = $dir_abs . '/' . $item;
        $entries[] = [
            'name'  => $item,
            'is_dir'=> is_dir($full),
            'size'  => is_dir($full) ? null : filesize($full),
            'mtime' => filemtime($full),
            'rel'   => ($dir_rel === '' ? $item : $dir_rel . '/' . $item),
        ];
    }
}
usort($entries, function ($a, $b) {
    if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

// 面包屑
$crumbs = [['name' => '根目录', 'rel' => '']];
if ($dir_rel !== '') {
    $acc = '';
    foreach (explode('/', $dir_rel) as $part) {
        $acc = $acc === '' ? $part : $acc . '/' . $part;
        $crumbs[] = ['name' => $part, 'rel' => $acc];
    }
}

// 统计：根目录文件总数与总大小（递归）
function dir_stats($dir) {
    $count = 0; $bytes = 0;
    if (!is_dir($dir)) return [0, 0];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getFilename() !== 'desc.txt' && $f->getFilename() !== 'links.json' && strpos($f->getFilename(), '.') !== 0) {
            $count++; $bytes += $f->getSize();
        }
    }
    return [$count, $bytes];
}
list($total_count, $total_bytes) = dir_stats($BASE_DIR);

$self = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);
$view = $_GET['view'] ?? '';
$view_rel = ($view !== '' && is_image($view)) ? safe_rel($view) : '';
// 图片预览也要过锁
if ($view_rel !== '') {
    $vp = dirname($view_rel);
    if ($vp === '.') $vp = '';
    if (locked_ancestor($vp) !== null) $view_rel = '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($SITE_NAME, ENT_QUOTES) ?><?= $dir_rel !== '' ? ' - ' . htmlspecialchars($dir_rel, ENT_QUOTES) : '' ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="topbar">
    <div class="topbar-inner">
        <div class="brand">📥 <?= htmlspecialchars($SITE_NAME, ENT_QUOTES) ?></div>
        <div class="meta"><?= htmlspecialchars($SITE_DESC, ENT_QUOTES) ?> &nbsp;·&nbsp; 共 <?= $total_count ?> 个文件 &nbsp;·&nbsp; <?= human_size($total_bytes) ?></div>
    </div>
</div>

<main class="main">
    <div class="list-head">目录列表</div>
    <div class="crumbs">
        <?php foreach ($crumbs as $i => $c): ?>
            <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
            <a href="<?= $self ?>?dir=<?= urlencode($c['rel']) ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
        <span class="count">（<?= count($entries) ?> 项）</span>
        <?php if ($dir_rel !== ''): ?>
            <a class="root-link" href="<?= $self ?>">查看全部根目录</a>
        <?php endif; ?>
    </div>

    <?php if ($locked_folder !== null): ?>
        <?php /* 该文件夹已加密：只显示密码框，不显示里面的文件 */ ?>
        <div class="lockbox">
            <div class="lock-icon">🔒</div>
            <h3>该文件夹已加密</h3>
            <p>文件夹“<?= htmlspecialchars($locked_folder, ENT_QUOTES) ?>”需要密码才能打开。</p>
            <?php if ($unlock_error !== ''): ?><p class="lock-err"><?= htmlspecialchars($unlock_error, ENT_QUOTES) ?></p><?php endif; ?>
            <form method="post" action="<?= $self ?>">
                <input type="hidden" name="action" value="unlock">
                <input type="hidden" name="dir" value="<?= htmlspecialchars($locked_folder, ENT_QUOTES) ?>">
                <input type="password" name="password" placeholder="请输入文件夹密码" required autofocus>
                <button type="submit">打开文件夹</button>
            </form>
            <p class="lock-tip"><a href="<?= $self ?>">← 回到根目录</a></p>
        </div>
    <?php else: ?>
        <?php if ($desc !== ''): ?>
            <div class="desc"><?= nl2br(htmlspecialchars($desc, ENT_QUOTES)) ?></div>
        <?php endif; ?>

        <div class="list">
            <?php if (empty($entries) && empty($links)): ?>
                <div class="empty">这个目录是空的。</div>
            <?php endif; ?>

            <?php foreach ($entries as $e): ?>
                <?php if ($e['is_dir']): ?>
                    <a class="row dir" href="<?= $self ?>?dir=<?= urlencode($e['rel']) ?>">
                        <span class="icon"><?= file_icon($e['name'], true) ?></span>
                        <?php if (folder_is_locked($e['rel'])): ?><span class="lock-badge" title="已加密">🔒</span><?php endif; ?>
                        <span class="name"><?= htmlspecialchars($e['name'], ENT_QUOTES) ?></span>
                        <span class="time"><?= date('m-d H:i', $e['mtime']) ?></span>
                        <span class="arrow">›</span>
                    </a>
                <?php elseif (is_image($e['name'])): ?>
                    <div class="row file">
                        <span class="icon"><?= file_icon($e['name'], false) ?></span>
                        <a class="name" href="<?= $self ?>?dir=<?= urlencode($dir_rel) ?>&amp;view=<?= urlencode($e['rel']) ?>" title="<?= date('m-d H:i', $e['mtime']) ?>，点击查看"><?= htmlspecialchars($e['name'], ENT_QUOTES) ?></a>
                        <span class="size"><?= human_size($e['size']) ?></span>
                        <span class="time"><?= date('m-d H:i', $e['mtime']) ?></span>
                        <a class="dl" href="<?= $self ?>?dl=<?= urlencode($e['rel']) ?>" title="点击下载">⬇</a>
                    </div>
                <?php else: ?>
                    <div class="row file">
                        <span class="icon"><?= file_icon($e['name'], false) ?></span>
                        <a class="name" href="<?= $self ?>?dl=<?= urlencode($e['rel']) ?>" title="<?= date('m-d H:i', $e['mtime']) ?>，点击下载"><?= htmlspecialchars($e['name'], ENT_QUOTES) ?></a>
                        <span class="size"><?= human_size($e['size']) ?></span>
                        <span class="time"><?= date('m-d H:i', $e['mtime']) ?></span>
                        <a class="dl" href="<?= $self ?>?dl=<?= urlencode($e['rel']) ?>" title="点击下载">⬇</a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php foreach ($links as $l): ?>
                <?php $u = safe_url($l['url'] ?? ''); if ($u === '') continue; ?>
                <div class="row link">
                    <span class="icon">🔗</span>
                    <a class="name" href="<?= htmlspecialchars($u, ENT_QUOTES) ?>" target="_blank" rel="noopener" title="点击打开"><?= htmlspecialchars($l['title'] ?? $u, ENT_QUOTES) ?></a>
                    <span class="size">网址</span>
                    <span class="time"></span>
                    <a class="dl" href="<?= htmlspecialchars($u, ENT_QUOTES) ?>" target="_blank" rel="noopener" title="点击打开">↗</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="foot">由简易下载站提供技术支持 · <a href="admin.php">后台管理</a></div>
</main>

<?php if ($view_rel !== ''): ?>
<div class="lightbox" onclick="location.href='<?= $self ?>?dir=<?= urlencode($dir_rel) ?>'">
    <img src="<?= $self ?>?img=<?= urlencode($view_rel) ?>" alt="<?= htmlspecialchars(basename($view_rel), ENT_QUOTES) ?>">
    <div class="lb-bar">
        <span><?= htmlspecialchars(basename($view_rel), ENT_QUOTES) ?></span>
        <a href="<?= $self ?>?dl=<?= urlencode($view_rel) ?>" onclick="event.stopPropagation()">⬇ 下载原图</a>
        <span class="lb-close">✕ 点击任意处关闭</span>
    </div>
</div>
<?php endif; ?>
</body>
</html>
