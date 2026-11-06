<?php
session_start();
if (!isset($_SESSION['comment_delete_token'])) {
    $_SESSION['comment_delete_token'] = bin2hex(random_bytes(32));
}
/* =============================================================
   文件：player_detail.php
   项目：VALORANT 选手详情页（期末网页作业）
   说明：接收 URL 参数 id（GET），联表查询 player + team 展示
         选手大头照、游戏ID、真名、国籍、位置、战队；
         再查询 player_tournament_stats（JOIN tournament），
         用表格列出该选手参加过的所有比赛数据（赛事、ACS、KD、Rating）
   ============================================================= */

// ---------------- 1. 数据库配置 ----------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'valorant_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// 默认占位图（头像为空或加载失败时显示）
define('DEFAULT_AVATAR',
    "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='400'%20height='240'%20viewBox='0%200%20400%20240'%3E%3Crect%20width='400'%20height='240'%20fill='%23131f2a'/%3E%3Cpath%20d='M170%2082%20L200%20166%20L230%2082'%20fill='none'%20stroke='%23ff4655'%20stroke-width='16'%20stroke-linecap='round'%20stroke-linejoin='round'/%3E%3Ctext%20x='200'%20y='208'%20text-anchor='middle'%20font-family='Arial,%20sans-serif'%20font-size='18'%20letter-spacing='6'%20fill='%235a6a7a'%3ENO%20PHOTO%3C/text%3E%3C/svg%3E");

// ---------------- 2. PDO 连接数据库 ----------------
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ));
} catch (PDOException $e) {
    die('数据库连接失败，请确认 XAMPP 的 MySQL 已启动：' . htmlspecialchars($e->getMessage()));
}

// ---------------- 3. 接收并校验 URL 参数 id ----------------
// 只允许纯数字 id，非数字一律视为无效（同时也防 SQL 注入）
$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$id = ctype_digit($id) ? (int)$id : 0;

$player = null;
$statRows = array();
$comments = array();
$commentError = '';
$formContent = '';
$formRating = 5;

if ($id > 0) {
    // ---- 3.1 联表查询选手信息（player + team） ----
    $sql = "SELECT p.player_id, p.ign, p.real_name, p.nationality,
                   p.player_role, p.photo_url, p.bio, p.team_id, p.birth_date,
                   t.team_tag, t.team_name
            FROM player AS p
            LEFT JOIN team AS t ON p.team_id = t.team_id
            WHERE p.player_id = :id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);   // 参数绑定：id 只当作整数数据
    $stmt->execute();
    $player = $stmt->fetch();

    if ($player) {
        // ---- 3.2 查询该选手参加过的所有比赛数据 ----
        // 通过 tournament_id 关联 tournament 表拿到赛事名称，按年份新→旧、ACS 高→低排序
        $ssql = "SELECT s.tournament_id, s.acs, s.kd, s.rating,
                        tn.tournament_name, tn.tournament_year
                 FROM player_tournament_stats AS s
                 LEFT JOIN tournament AS tn ON s.tournament_id = tn.tournament_id
                 WHERE s.player_id = :id
                 ORDER BY tn.tournament_year DESC, s.acs DESC";
        $sstmt = $pdo->prepare($ssql);
        $sstmt->bindValue(':id', $id, PDO::PARAM_INT);
        $sstmt->execute();
        $statRows = $sstmt->fetchAll();

        // ---- 3.3 查询该选手最常用的特工（按比赛使用次数聚合，最多 4 个） ----
        // player_agent 表若有招牌特工记录，会以 ★ 标出
        $asql = "SELECT a.agent_id, a.agent_name, COUNT(*) AS cnt,
                        MAX(pa.is_signature) AS sig
                 FROM player_tournament_stats AS s
                 JOIN agent AS a ON s.agent_id = a.agent_id
                 LEFT JOIN player_agent AS pa
                        ON pa.player_id = s.player_id AND pa.agent_id = s.agent_id
                 WHERE s.player_id = :id AND s.agent_id IS NOT NULL
                 GROUP BY a.agent_id, a.agent_name
                 ORDER BY cnt DESC
                 LIMIT 4";
        $astmt = $pdo->prepare($asql);
        $astmt->bindValue(':id', $id, PDO::PARAM_INT);
        $astmt->execute();
        $agentRows = $astmt->fetchAll();

        // 提交评论：必须在输出 HTML 前执行，成功后重定向避免刷新重复提交
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
                header('Location: login.php?return_to=' . rawurlencode('player_detail.php?id=' . $id . '#comments'), true, 302);
                exit;
            }
            $account = $pdo->prepare('SELECT 1 FROM `user` WHERE user_id = :user_id AND username = :username');
            $account->execute([
                ':user_id' => $_SESSION['user_id'],
                ':username' => $_SESSION['username']
            ]);
            if (!$account->fetchColumn()) {
                $_SESSION = array();
                session_destroy();
                header('Location: login.php?return_to=' . rawurlencode('player_detail.php?id=' . $id . '#comments'), true, 302);
                exit;
            }

            if (isset($_POST['delete_comment'])) {
                if (!hash_equals($_SESSION['comment_delete_token'], (string)($_POST['csrf_token'] ?? ''))) {
                    http_response_code(403);
                    exit('请求无效，请刷新页面后重试。');
                }
                $commentId = filter_var($_POST['comment_id'] ?? null, FILTER_VALIDATE_INT);
                if ($commentId !== false && $commentId > 0) {
                    $delete = $pdo->prepare("DELETE FROM `comment`
                        WHERE comment_id = :comment_id AND target_type = 'player'
                        AND target_id = :target_id AND user_id = :user_id");
                    $delete->bindValue(':comment_id', $commentId, PDO::PARAM_INT);
                    $delete->bindValue(':target_id', $id, PDO::PARAM_INT);
                    $delete->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                    $delete->execute();
                }
                header('Location: player_detail.php?id=' . $id . '#comments', true, 303);
                exit;
            }
            $formContent = trim((string)($_POST['content'] ?? ''));
            $formRating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);

            if ($formContent === '' || $formRating === false ||
                $formRating < 1 || $formRating > 5) {
                $commentError = '请填写评论内容，并选择1至5星。';
            } else {
                $insert = $pdo->prepare("INSERT INTO `comment`
                    (target_type, target_id, user_id, user_name, content, rating)
                    VALUES ('player', :target_id, :user_id, :user_name, :content, :rating)");
                $insert->bindValue(':target_id', $id, PDO::PARAM_INT);
                $insert->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                $insert->bindValue(':user_name', $_SESSION['username']);
                $insert->bindValue(':content', $formContent);
                $insert->bindValue(':rating', $formRating, PDO::PARAM_INT);
                $insert->execute();

                header('Location: player_detail.php?id=' . $id . '#comments', true, 303);
                exit;
            }
        }

        $cstmt = $pdo->prepare("SELECT comment_id, user_id, user_name, content, rating, created_at
            FROM `comment`
            WHERE target_type = 'player' AND target_id = :id
            ORDER BY created_at DESC, comment_id DESC");
        $cstmt->bindValue(':id', $id, PDO::PARAM_INT);
        $cstmt->execute();
        $comments = $cstmt->fetchAll();
    }
}

// ---------------- 4. 输出转义函数（防 XSS） ----------------
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ---------------- 5. 导航高亮辅助函数 ----------------
$current = basename($_SERVER['SCRIPT_NAME']);

function nav_link($file, $label) {
    global $current;
    // 详情页没有独立导航项：战队详情页归属"战队库"，选手详情页归属"选手库"
    if ($current === 'team_detail.php')   { $current = 'teams.php'; }
    if ($current === 'player_detail.php') { $current = 'index.php'; }
    $class = ($current === $file) ? ' class="active"' : '';
    return '<a href="' . $file . '"' . $class . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $player ? e($player['ign']) : '选手未找到'; ?> - VALORANT 选手数据库</title>
<style>
/* ===================== 全局 ===================== */
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    background: #0f1923;
    color: #ece8e1;
    font-family: "Segoe UI", "Microsoft YaHei", "PingFang SC", sans-serif;
    min-height: 100vh;
    background-image: radial-gradient(1100px 480px at 50% -60px, rgba(255, 70, 85, .10), transparent 60%);
    background-repeat: no-repeat;
}

/* ===================== 头部标题 ===================== */
.site-header {
    text-align: center;
    padding: 44px 20px 22px;
    border-bottom: 1px solid #2b3b4a;
}
.logo {
    font-size: 40px;
    font-weight: 900;
    letter-spacing: 6px;
    text-transform: uppercase;
    color: #fff;
}
.logo .slash { color: #ff4655; margin: 0 12px; }
.logo .logo-sub {
    font-size: 24px;
    letter-spacing: 10px;
    font-weight: 700;
    color: #768079;
}
.tagline {
    margin-top: 12px;
    color: #5a6a7a;
    letter-spacing: 3px;
    font-size: 13px;
}

.container { max-width: 1280px; margin: 0 auto; padding: 36px 20px 60px; }

/* ===================== 面包屑导航 ===================== */
.breadcrumb {
    max-width: 1280px;
    margin: 22px auto 0;
    padding: 0 20px;
    color: #5a6a7a;
    font-size: 13px;
}
.breadcrumb a { color: #768079; text-decoration: none; }
.breadcrumb a:hover { color: #ff4655; }
.breadcrumb span { margin: 0 8px; color: #3a4756; }

/* ===================== 区块标题 ===================== */
.section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 22px;
    color: #fff;
    letter-spacing: 3px;
    margin: 40px 0 18px;
}
.section-title::before {
    content: "";
    width: 5px;
    height: 22px;
    background: #ff4655;
    border-radius: 2px;
}
.section-title .count { font-size: 13px; color: #5a6a7a; letter-spacing: 1px; }

/* ===================== 空状态提示 ===================== */
.empty { text-align: center; padding: 60px 20px; color: #768079; }
.empty .empty-code {
    font-size: 34px;
    font-weight: 900;
    letter-spacing: 6px;
    color: #ff4655;
}
.empty p { margin-top: 12px; font-size: 15px; }
.empty a { color: #ff4655; }

/* ===================== 页脚 ===================== */
.site-footer {
    text-align: center;
    padding: 24px;
    color: #5a6a7a;
    font-size: 13px;
    border-top: 1px solid #222f3a;
}

/* ===================== 移动端适配 ===================== */
@media (max-width: 640px) {
    .logo { font-size: 28px; letter-spacing: 3px; }
    .logo .logo-sub { font-size: 17px; letter-spacing: 4px; }
}
/* ===================== 全局导航栏 ===================== */
.top-nav {
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(15, 25, 35, .92);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid #2b3b4a;
}
.nav-inner {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 20px;
    height: 58px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.nav-brand {
    color: #fff;
    font-size: 20px;
    font-weight: 900;
    letter-spacing: 3px;
    text-decoration: none;
}
.nav-brand span { color: #ff4655; }
.nav-links { display: flex; gap: 6px; }
.nav-links a {
    color: #c7d1da;
    text-decoration: none;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: 2px;
    padding: 8px 16px;
    border-radius: 6px;
    transition: background .2s, color .2s;
}
.nav-links a:hover { color: #fff; background: #1a2530; }
.nav-links a.active { color: #fff; background: #ff4655; }
@media (max-width: 640px) {
    .nav-inner { height: auto; flex-direction: column; gap: 10px; padding: 12px 16px; }
    .nav-links { flex-wrap: wrap; justify-content: center; }
    .nav-links a { padding: 6px 12px; font-size: 14px; }
}


/* ===================== 选手大图（Hero） ===================== */
.hero {
    display: flex;
    gap: 28px;
    background: linear-gradient(135deg, #1b2733, #141f29);
    border: 1px solid #2b3b4a;
    border-radius: 12px;
    padding: 28px;
    align-items: center;
}
.hero-avatar {
    width: 190px;
    height: 190px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #ff4655;
    background: #0b1117;
    flex-shrink: 0;
}
.hero-info { flex: 1; min-width: 0; }
.hero-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.badge-role {
    background: #ff4655;
    color: #fff;
    font-weight: 800;
    letter-spacing: 2px;
    padding: 4px 12px;
    border-radius: 4px;
}
.badge-team {
    border: 1px solid rgba(255, 70, 85, .5);
    color: #ff9aa3;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 13px;
    text-decoration: none;
}
.badge-team:hover { background: rgba(255, 70, 85, .1); }
.hero-name { font-size: 36px; font-weight: 900; color: #fff; letter-spacing: 2px; }
.hero-real { margin-top: 8px; color: #768079; font-size: 16px; }
.hero-meta {
    margin-top: 16px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.meta-item {
    font-size: 13px;
    color: #c7d1da;
    padding: 4px 12px;
    border: 1px solid #2b3b4a;
    border-radius: 999px;
}
/* 常用特工标签行 */
.hero-agents {
    margin-top: 14px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.agents-label {
    font-size: 13px;
    color: #768079;
    letter-spacing: 1px;
    margin-right: 2px;
}
.agents-empty { font-size: 13px; color: #5a6a7a; }
.agent-chip {
    display: inline-block;
    font-size: 13px;
    font-weight: 700;
    color: #ff9aa3;
    text-decoration: none;
    padding: 4px 12px;
    border: 1px solid rgba(255, 70, 85, .5);
    border-radius: 999px;
    transition: background .2s, color .2s, border-color .2s;
}
.agent-chip:hover {
    background: #ff4655;
    border-color: #ff4655;
    color: #fff;
}

/* ===================== 选手简介 ===================== */
.player-bio {
    color: #c7d1da;
    font-size: 15px;
    line-height: 1.9;
    background: #16222c;
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    padding: 18px 20px;
}

/* ===================== 比赛数据表格 ===================== */
.table-wrap { overflow-x: auto; }   /* 小屏时横向滚动 */
.stats-table {
    width: 100%;
    border-collapse: collapse;
    background: #16222c;
    border-radius: 10px;
    overflow: hidden;
}
.stats-table thead th {
    background: #1a2530;
    color: #ff4655;
    text-align: left;
    padding: 12px 16px;
    font-size: 13px;
    letter-spacing: 2px;
    border-bottom: 1px solid #2b3b4a;
}
.stats-table tbody td {
    padding: 12px 16px;
    border-top: 1px solid #222f3a;
    color: #ece8e1;
    font-size: 14px;
}
.stats-table tbody tr:hover { background: #1b2733; }
.td-acs { color: #ff4655; font-weight: 700; }
.td-na { color: #5a6a7a; }
.event-year {
    display: inline-block;
    background: #ff4655;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 4px;
    margin-right: 8px;
    letter-spacing: 1px;
}

/* ===================== 评论区与评价弹窗 ===================== */
.comment-item {
    background: #16222c;
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    padding: 20px;
}
.review-action { margin: 0 0 22px; }
.review-shortcut {
    display: inline-block;
    margin-top: 18px;
    padding: 9px 14px;
    border: 1px solid rgba(255, 70, 85, .55);
    border-radius: 6px;
    color: #ff9aa3;
    font-weight: 700;
    text-decoration: none;
}
.review-shortcut:hover, .review-shortcut:focus-visible { background: rgba(255, 70, 85, .14); color: #fff; }
#comments { scroll-margin-top: 90px; }
.review-button, .review-submit {
    display: inline-block;
    background: #ff4655;
    color: #fff;
    border: 0;
    border-radius: 6px;
    padding: 11px 25px;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}
.review-button:hover, .review-submit:hover { background: #e63848; }
.comment-error { color: #ff9aa3; }
.comment-list { display: grid; gap: 12px; }
.comment-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.comment-name { color: #fff; font-weight: 700; }
.comment-time { color: #768079; font-size: 13px; }
.comment-rating { color: #ffd166; letter-spacing: 2px; }
.comment-delete-form { margin-left: auto; }
.comment-delete {
    color: #ff9aa3;
    background: transparent;
    border: 1px solid #8b4650;
    border-radius: 5px;
    padding: 5px 10px;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}
.comment-delete:hover { color: #fff; background: #b93644; border-color: #b93644; }
.comment-content { margin-top: 12px; color: #c7d1da; line-height: 1.7; white-space: pre-wrap; overflow-wrap: anywhere; }
.review-modal {
    width: min(520px, calc(100% - 32px));
    max-height: calc(100vh - 32px);
    margin: auto;
    padding: 0;
    color: #ece8e1;
    background: #1a2632;
    border: 1px solid #384757;
    border-radius: 14px;
    box-shadow: 0 26px 80px rgba(0, 0, 0, .65), 0 0 40px rgba(255, 70, 85, .12);
}
.review-modal::backdrop { background: rgba(3, 9, 15, .78); backdrop-filter: blur(5px); }
.review-modal-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 24px 26px 18px; border-bottom: 1px solid #2b3b4a; }
.review-modal-header h3 { margin: 0; color: #fff; font-size: 22px; }
.review-modal-header p { margin: 7px 0 0; color: #8c9aa8; font-size: 13px; }
.review-close { border: 0; background: transparent; color: #a9b6c2; font-size: 27px; line-height: 1; cursor: pointer; }
.review-close:hover { color: #fff; }
.review-form { display: grid; gap: 18px; padding: 24px 26px 28px; }
.review-label { color: #d4dce3; font-size: 14px; font-weight: 700; }
.star-picker { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; margin-top: 10px; }
.star-picker input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.star-picker label { color: #52606d; font-size: 40px; line-height: 1; cursor: pointer; transition: color .15s, transform .15s; }
.star-picker label:hover { transform: scale(1.12); }
.star-picker input:checked ~ label, .star-picker label:hover, .star-picker label:hover ~ label { color: #ffc857; }
.star-picker input:focus-visible + label { outline: 2px solid #ff4655; border-radius: 4px; }
.review-form textarea { width: 100%; min-height: 130px; margin-top: 10px; padding: 13px; resize: vertical; background: #101a24; color: #ece8e1; border: 1px solid #405061; border-radius: 8px; font: inherit; line-height: 1.6; }
.review-form textarea:focus { outline: 2px solid #ff4655; outline-offset: 1px; }
.review-submit { width: 100%; }

@media (max-width: 700px) {
    .hero { flex-direction: column; align-items: center; text-align: center; }
    .hero-avatar { width: 150px; height: 150px; }
    .hero-name { font-size: 28px; }
}
@media (max-width: 640px) {
    #comments { scroll-margin-top: 150px; }
    .table-wrap { overflow-x: visible; }
    .stats-table { display: block; background: transparent; overflow: visible; }
    .stats-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }
    .stats-table tbody { display: grid; gap: 12px; }
    .stats-table tbody tr {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        background: #16222c;
        border: 1px solid #2b3b4a;
        border-radius: 10px;
        overflow: hidden;
    }
    .stats-table tbody td { border: 0; min-width: 0; padding: 10px 12px; }
    .stats-table tbody td:first-child {
        grid-column: 1 / -1;
        border-bottom: 1px solid #2b3b4a;
        line-height: 1.6;
        overflow-wrap: anywhere;
    }
    .stats-table tbody td:not(:first-child)::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 4px;
        color: #9ba8b6;
        font-size: 11px;
        font-weight: 600;
    }
    .stats-table tbody .td-acs { color: #ff6774; font-weight: 700; }
}
</style>
</head>
<body>

<!-- ===================== 全局导航栏（四个页面共用，当前页自动高亮） ===================== -->
<nav class="top-nav">
    <div class="nav-inner">
        <a class="nav-brand" href="index.php">VALORANT<span>//</span>DB</a>
        <div class="nav-links">
            <?php echo nav_link('index.php', '选手库'); ?>
            <?php echo nav_link('teams.php', '战队库'); ?>
            <?php echo nav_link('tournaments.php', '赛事库'); ?>
            <?php echo nav_link('stats.php', '数据榜'); ?>
            <?php echo nav_link('agents.php', '特工'); ?>
            <?php if (isset($_SESSION['user_id'])): ?>
            <span style="color:#c7d1da;padding:8px">欢迎，<?php echo e($_SESSION['username']); ?></span>
            <form method="post" action="login.php" style="margin:0">
                <button type="submit" name="logout" value="1" style="background:none;border:0;color:#ff9aa3;font:inherit;cursor:pointer;padding:8px 10px">退出</button>
            </form>
            <?php else: ?>
                <a href="login.php">登录</a>
                <a href="register.php">注册</a>
            <?php endif; ?>
        </div>
    </div>
</nav>


<?php if (!$player): ?>
    <!-- ===================== 选手不存在 ===================== -->
    <div class="container">
        <div class="empty">
            <div class="empty-code">404 PLAYER</div>
            <p>没有找到 id 为 “<?php echo e((string)$id); ?>” 的选手，可能链接有误或选手已被删除</p>
            <p><a href="index.php">← 返回选手库</a></p>
        </div>
    </div>
<?php else: ?>
    <!-- ===================== 面包屑 ===================== -->
    <div class="breadcrumb">
        <a href="index.php">选手库</a><span>/</span><?php echo e($player['ign']); ?>
    </div>

    <div class="container">
        <!-- ===================== 选手大图 ===================== -->
        <div class="hero">
            <?php
                $avatar = !empty($player['photo_url']) ? e($player['photo_url']) : DEFAULT_AVATAR;
            ?>
            <img class="hero-avatar" src="<?php echo $avatar; ?>"
                 data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                 onerror="this.onerror=null;this.src=this.dataset.fallback;"
                 alt="<?php echo e($player['ign']); ?> 大头照">
            <div class="hero-info">
                <div class="hero-badges">
                    <span class="badge-role"><?php echo e(!empty($player['player_role']) ? $player['player_role'] : '位置未知'); ?></span>
                    <?php if (!empty($player['team_id'])): ?>
                        <!-- 战队徽章可点击跳转到战队详情页 -->
                        <a class="badge-team" href="team_detail.php?id=<?php echo (int)$player['team_id']; ?>">
                            <?php echo e($player['team_tag']); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <h1 class="hero-name"><?php echo e($player['ign']); ?></h1>
                <p class="hero-real"><?php echo e(!empty($player['real_name']) ? $player['real_name'] : '真实姓名未知'); ?></p>
                <div class="hero-meta">
                    <span class="meta-item">国籍：<?php echo e(!empty($player['nationality']) ? $player['nationality'] : '未知'); ?></span>
                    <span class="meta-item">战队：<?php echo e(!empty($player['team_name']) ? $player['team_name'] : '自由人'); ?></span>
                    <span class="meta-item">出生日期：<?php echo e(!empty($player['birth_date']) ? date('Y-m-d', strtotime($player['birth_date'])) : '未知'); ?></span>
                </div>
                <!-- 常用特工：点击可跳转到特工详情页 -->
                <div class="hero-agents">
                    <span class="agents-label">常用特工</span>
                    <?php if (empty($agentRows)): ?>
                        <span class="agents-empty">暂无特工数据</span>
                    <?php else: ?>
                        <?php foreach ($agentRows as $a): ?>
                            <a class="agent-chip" href="agent_detail.php?id=<?php echo (int)$a['agent_id']; ?>">
                                <?php echo e($a['agent_name']); ?><?php if (!empty($a['sig'])) { echo ' ★'; } ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <a class="review-shortcut" href="#comments">查看评价（共 <?php echo count($comments); ?> 条）↓</a>
            </div>
        </div>

        <!-- ===================== 选手简介（完整显示，不限制字数） ===================== -->
        <h2 class="section-title">选手简介</h2>
        <p class="player-bio"><?php echo e(!empty($player['bio']) ? $player['bio'] : '暂无简介'); ?></p>

        <!-- ===================== 比赛数据表格 ===================== -->
        <h2 class="section-title">比赛数据 <span class="count">共 <?php echo count($statRows); ?> 场</span></h2>
        <?php if (count($statRows) === 0): ?>
            <div class="empty">
                <div class="empty-code">0 MATCH</div>
                <p>该选手暂时没有比赛数据</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>赛事</th>
                            <th>ACS</th>
                            <th>K/D</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statRows as $row): ?>
                            <?php
                                // 赛事名称：通过 tournament_id 关联 tournament 表得到；
                                // 若关联不到（名称为空）则回退显示赛事ID
                                $event = !empty($row['tournament_name'])
                                       ? e($row['tournament_name'])
                                       : '未知赛事 #' . e($row['tournament_id']);
                                $acs  = !empty($row['acs'])    ? e(number_format((float)$row['acs'], 2))    : '<span class="td-na">-</span>';
                                $kd   = !empty($row['kd'])     ? e(number_format((float)$row['kd'], 2))     : '<span class="td-na">-</span>';
                                $rate = !empty($row['rating']) ? e(number_format((float)$row['rating'], 2)) : '<span class="td-na">-</span>';
                            ?>
                            <tr>
                                <td>
                                    <?php if (!empty($row['tournament_year'])): ?>
                                        <span class="event-year"><?php echo e($row['tournament_year']); ?></span>
                                    <?php endif; ?>
                                    <?php echo $event; ?>
                                </td>
                                <td class="td-acs" data-label="ACS"><?php echo $acs; ?></td>
                                <td data-label="K/D"><?php echo $kd; ?></td>
                                <td data-label="Rating"><?php echo $rate; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- ===================== 选手评论 ===================== -->
        <section id="comments">
            <h2 class="section-title">评论区 <span class="count">共 <?php echo count($comments); ?> 条</span></h2>
            <div class="review-action">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="review-button" type="button" id="open-review">发表评价</button>
                <?php else: ?>
                    <a class="review-button" href="login.php?return_to=<?php echo rawurlencode('player_detail.php?id=' . $id . '#comments'); ?>">发表评价</a>
                <?php endif; ?>
            </div>

            <?php if (empty($comments)): ?>
                <p class="empty">暂无评论，来发表第一条吧。</p>
            <?php else: ?>
                <div class="comment-list">
                    <?php foreach ($comments as $comment): ?>
                        <article class="comment-item">
                            <div class="comment-meta">
                                <span class="comment-name"><?php echo e($comment['user_name']); ?></span>
                                <time class="comment-time"><?php echo e($comment['created_at']); ?></time>
                                <span class="comment-rating" aria-label="<?php echo (int)$comment['rating']; ?> 分"><?php echo str_repeat('⭐', (int)$comment['rating']); ?></span>
                                <?php if (isset($_SESSION['user_id'], $_SESSION['username']) && $comment['user_id'] !== null &&
                                    (int)$comment['user_id'] === (int)$_SESSION['user_id'] && $comment['user_name'] === $_SESSION['username']): ?>
                                    <form class="comment-delete-form" method="post" action="player_detail.php?id=<?php echo $id; ?>#comments">
                                        <input type="hidden" name="delete_comment" value="1">
                                        <input type="hidden" name="comment_id" value="<?php echo (int)$comment['comment_id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['comment_delete_token']); ?>">
                                        <button class="comment-delete" type="submit" onclick="return confirm('确定删除这条评论吗？')">删除</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <p class="comment-content"><?php echo e($comment['content']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (isset($_SESSION['user_id'])): ?>
            <dialog class="review-modal" id="review-modal" aria-labelledby="review-title">
                <div class="review-modal-header">
                    <div>
                        <h3 id="review-title">评价 <?php echo e($player['ign']); ?></h3>
                        <p>以 <?php echo e($_SESSION['username']); ?> 的身份发表</p>
                    </div>
                    <button class="review-close" type="button" id="close-review" aria-label="关闭弹窗">&times;</button>
                </div>
                <form class="review-form" method="post" action="player_detail.php?id=<?php echo $id; ?>#comments">
                    <?php if ($commentError !== ''): ?>
                        <p class="comment-error" role="alert"><?php echo e($commentError); ?></p>
                    <?php endif; ?>
                    <div>
                        <span class="review-label">给选手打分</span>
                        <div class="star-picker" role="radiogroup" aria-label="评分，1至5星">
                            <?php for ($score = 5; $score >= 1; $score--): ?>
                                <input type="radio" name="rating" id="rating-<?php echo $score; ?>" value="<?php echo $score; ?>"<?php echo $formRating === $score ? ' checked' : ''; ?> required>
                                <label for="rating-<?php echo $score; ?>" aria-label="<?php echo $score; ?>星">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <label class="review-label" for="review-content">说说你的看法
                        <textarea id="review-content" name="content" placeholder="分享你对这位选手的评价…" required><?php echo e($formContent); ?></textarea>
                    </label>
                    <button class="review-submit" type="submit">发布评价</button>
                </form>
            </dialog>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

<?php if ($player && isset($_SESSION['user_id'])): ?>
<script>
const reviewModal = document.getElementById('review-modal');
document.getElementById('open-review').addEventListener('click', () => reviewModal.showModal());
document.getElementById('close-review').addEventListener('click', () => reviewModal.close());
reviewModal.addEventListener('click', (event) => {
    if (event.target === reviewModal) reviewModal.close();
});
<?php if ($commentError !== ''): ?>reviewModal.showModal();<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>
