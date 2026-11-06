<?php
session_start();
if (!isset($_SESSION['comment_delete_token'])) {
    $_SESSION['comment_delete_token'] = bin2hex(random_bytes(32));
}
/* =============================================================
   文件：tournament_detail.php
   项目：VALORANT 赛事详情页（期末网页作业）
   说明：接收 URL 参数 id（GET），展示赛事信息、
         参加战队的排名（team_tournament 表）、
         该赛事 ACS 前 10 名选手排行榜
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
$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$id = ctype_digit($id) ? (int)$id : 0;

$tour = null;
$standings = array();
$topAcs = array();
$comments = array();
$commentError = '';
$formContent = '';
$formRating = 5;

if ($id > 0) {
    // ---- 3.1 赛事信息 ----
    $sql = "SELECT tournament_id, tournament_name, tournament_year, tournament_level,
                   prize_pool, start_date, end_date
            FROM tournament
            WHERE tournament_id = :id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $tour = $stmt->fetch();

    if ($tour) {
        // ---- 3.2 参加战队排名（按名次排序，冠军最前） ----
        // FIELD：名次字符串映射为固定顺序；不在映射内的排最后
        $tsql = "SELECT t.team_id, t.team_tag, t.team_name, t.logo_url,
                        tt.placement, tt.wins, tt.losses
                 FROM team_tournament AS tt
                 JOIN team AS t ON tt.team_id = t.team_id
                 WHERE tt.tournament_id = :id
                 ORDER BY (FIELD(tt.placement,
                     '1st','2nd','3rd','3rd-4th','4th',
                     '5th','6th','5th-6th','5th-8th','7th-8th',
                     '5th-11th','7th-11th','7th-10th','9th-10th',
                     '9th-12th','11th-12th','13th-16th','17th-32nd',
                     '决赛进行中') = 0) ASC,
                     FIELD(tt.placement,
                     '1st','2nd','3rd','3rd-4th','4th',
                     '5th','6th','5th-6th','5th-8th','7th-8th',
                     '5th-11th','7th-11th','7th-10th','9th-10th',
                     '9th-12th','11th-12th','13th-16th','17th-32nd',
                     '决赛进行中') ASC,
                     tt.wins DESC";
        $tstmt = $pdo->prepare($tsql);
        $tstmt->bindValue(':id', $id, PDO::PARAM_INT);
        $tstmt->execute();
        $standings = $tstmt->fetchAll();

        // ---- 3.3 ACS 前 10 名选手（同一选手多条特工记录取最高 ACS） ----
        $asql = "SELECT p.player_id, p.ign, p.photo_url, t.team_tag,
                        MAX(s.acs) AS acs, MAX(s.kd) AS kd
                 FROM player_tournament_stats AS s
                 JOIN player AS p ON s.player_id = p.player_id
                 LEFT JOIN team AS t ON s.team_id = t.team_id   -- 显示选手参赛时的战队
                 WHERE s.tournament_id = :id
                 GROUP BY s.player_id, p.player_id, p.ign, p.photo_url, t.team_tag
                 ORDER BY acs DESC
                 LIMIT 10";
        $astmt = $pdo->prepare($asql);
        $astmt->bindValue(':id', $id, PDO::PARAM_INT);
        $astmt->execute();
        $topAcs = $astmt->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
                header('Location: login.php?return_to=' . rawurlencode('tournament_detail.php?id=' . $id . '#comments'), true, 302);
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
                header('Location: login.php?return_to=' . rawurlencode('tournament_detail.php?id=' . $id . '#comments'), true, 302);
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
                        WHERE comment_id = :comment_id AND target_type = 'tournament'
                        AND target_id = :target_id AND user_id = :user_id");
                    $delete->bindValue(':comment_id', $commentId, PDO::PARAM_INT);
                    $delete->bindValue(':target_id', $id, PDO::PARAM_INT);
                    $delete->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                    $delete->execute();
                }
                header('Location: tournament_detail.php?id=' . $id . '#comments', true, 303);
                exit;
            }
            $formContent = trim((string)($_POST['content'] ?? ''));
            $formRating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
            if ($formContent === '' || $formRating === false || $formRating < 1 || $formRating > 5) {
                $commentError = '请填写评论内容，并选择1至5星。';
            } else {
                $insert = $pdo->prepare("INSERT INTO `comment`
                    (target_type, target_id, user_id, user_name, content, rating)
                    VALUES ('tournament', :target_id, :user_id, :user_name, :content, :rating)");
                $insert->bindValue(':target_id', $id, PDO::PARAM_INT);
                $insert->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                $insert->bindValue(':user_name', $_SESSION['username']);
                $insert->bindValue(':content', $formContent);
                $insert->bindValue(':rating', $formRating, PDO::PARAM_INT);
                $insert->execute();
                header('Location: tournament_detail.php?id=' . $id . '#comments', true, 303);
                exit;
            }
        }

        $cstmt = $pdo->prepare("SELECT comment_id, user_id, user_name, content, rating, created_at
            FROM `comment`
            WHERE target_type = 'tournament' AND target_id = :id
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
    if ($current === 'team_detail.php')       { $current = 'teams.php'; }
    if ($current === 'player_detail.php')     { $current = 'index.php'; }
    if ($current === 'agent_detail.php')      { $current = 'agents.php'; }
    if ($current === 'tournament_detail.php') { $current = 'tournaments.php'; }
    $class = ($current === $file) ? ' class="active"' : '';
    return '<a href="' . $file . '"' . $class . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $tour ? e($tour['tournament_name']) : '赛事未找到'; ?> - VALORANT 选手数据库</title>
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

/* ===================== 通用容器 ===================== */
.container { max-width: 1000px; margin: 0 auto; padding: 36px 20px 60px; }
.breadcrumb {
    max-width: 1000px;
    margin: 22px auto 0;
    padding: 0 20px;
    color: #5a6a7a;
    font-size: 13px;
}
.breadcrumb a { color: #768079; text-decoration: none; }
.breadcrumb a:hover { color: #ff4655; }
.breadcrumb span { margin: 0 8px; color: #3a4756; }

/* ===================== 赛事大图（Hero） ===================== */
.hero {
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: linear-gradient(135deg, #1b2733, #141f29);
    border: 1px solid #2b3b4a;
    border-radius: 12px;
    padding: 26px 28px;
}
.hero-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.hero-year {
    font-size: 30px;
    font-weight: 900;
    letter-spacing: 2px;
    color: #ff4655;
}
.tier {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 2px;
    padding: 4px 12px;
    border-radius: 4px;
}
.tier-intl { background: #ff4655; color: #fff; }
.tier-league { background: #1a2530; border: 1px solid #2b3b4a; color: #c7d1da; }
.hero-name { font-size: 26px; font-weight: 900; color: #fff; letter-spacing: 1px; }
.hero-meta { display: flex; gap: 10px; flex-wrap: wrap; }
.meta-chip {
    font-size: 13px;
    color: #c7d1da;
    padding: 4px 12px;
    border: 1px solid #2b3b4a;
    border-radius: 999px;
}
.meta-chip strong { color: #fff; }

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

/* ===================== 战队排名榜 ===================== */
.standings { display: flex; flex-direction: column; gap: 8px; }
.stand-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 16px;
    background: #16222c;
    border: 1px solid #2b3b4a;
    border-radius: 8px;
    transition: border-color .2s, transform .2s;
}
.stand-row:hover { border-color: #ff4655; transform: translateX(4px); }
.stand-row.champion { border-color: #ffd700; background: rgba(255, 215, 0, .05); }
.stand-place {
    min-width: 74px;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 1px;
    color: #768079;
    text-align: center;
}
.stand-row.champion .stand-place { color: #ffd700; }
.stand-logo {
    width: 34px;
    height: 34px;
    border-radius: 6px;
    background: #ece8e1;
    object-fit: contain;
    padding: 4px;
    flex-shrink: 0;
}
.stand-name { flex: 1; min-width: 0; font-size: 15px; font-weight: 700; color: #ece8e1; }
.stand-name a { color: inherit; text-decoration: none; }
.stand-name a:hover { color: #ff4655; }
.stand-tag { font-size: 12px; color: #ff9aa3; letter-spacing: 1px; margin-left: 6px; }
.stand-wl { font-size: 13px; color: #768079; }

/* ===================== ACS 排行榜 ===================== */
.rank-list { display: flex; flex-direction: column; gap: 12px; }
.rank-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 18px;
    background: linear-gradient(90deg, #1b2733, #141f29);
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    transition: transform .25s, border-color .25s;
}
.rank-row:hover { border-color: #ff4655; transform: translateX(4px); }
.rank-1 { border-color: #ff4655; box-shadow: 0 6px 20px rgba(255, 70, 85, .25); }
.rank-num { width: 44px; text-align: center; font-size: 26px; font-weight: 900; color: #5a6a7a; }
.rank-1 .rank-num { color: #ff4655; }
.rank-2 .rank-num, .rank-3 .rank-num { color: #ff7b87; }
.rank-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #2b3b4a;
    background: #0b1117;
    flex-shrink: 0;
}
.rank-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.rank-ign { font-size: 19px; font-weight: 800; color: #fff; }
.rank-ign a { color: inherit; text-decoration: none; }
.rank-ign a:hover { color: #ff4655; }
.rank-team { font-size: 13px; color: #ff4655; font-weight: 700; letter-spacing: 2px; margin-top: 2px; }
.rank-stat { min-width: 90px; text-align: right; }
.stat-label { display: block; font-size: 11px; color: #5a6a7a; letter-spacing: 2px; }
.stat-value { font-size: 20px; font-weight: 800; color: #ece8e1; }
.rank-stat.acs .stat-value { color: #ff4655; }

/* ===================== 空状态 ===================== */
.empty { text-align: center; padding: 60px 20px; color: #768079; }
.empty .empty-code { font-size: 34px; font-weight: 900; letter-spacing: 6px; color: #ff4655; }
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
    .hero-name { font-size: 20px; }
    .rank-row { flex-wrap: wrap; gap: 10px; }
    .rank-num { width: 32px; font-size: 22px; }
    .rank-avatar { width: 44px; height: 44px; }
    .rank-info { min-width: 110px; }
    .rank-stat { flex: 1; min-width: 70px; }
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
    align-self: flex-start;
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
@media (max-width: 640px) {
    #comments { scroll-margin-top: 150px; }
}
</style>
</head>
<body>

<!-- ===================== 全局导航栏（六个页面共用，当前页自动高亮） ===================== -->
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
                <a href="login.php">登录/注册</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php if (!$tour): ?>
    <!-- ===================== 赛事不存在 ===================== -->
    <div class="container">
        <div class="empty">
            <div class="empty-code">404 TOURNAMENT</div>
            <p>没有找到 id 为 “<?php echo e((string)$id); ?>” 的赛事，可能链接有误</p>
            <p><a href="tournaments.php">← 返回赛事库</a></p>
        </div>
    </div>
<?php else: ?>
    <?php
        $tierClass = ($tour['tournament_level'] === '国际赛') ? 'tier-intl' : 'tier-league';
        $prize = !empty($tour['prize_pool'])
               ? '$' . e(number_format((float)$tour['prize_pool']))
               : '无现金奖金';
        $dates = '';
        if (!empty($tour['start_date']) && !empty($tour['end_date'])) {
            $dates = e(date('Y-m-d', strtotime($tour['start_date']))) . ' 至 ' . e(date('Y-m-d', strtotime($tour['end_date'])));
        }
    ?>
    <!-- ===================== 面包屑 ===================== -->
    <div class="breadcrumb">
        <a href="tournaments.php">赛事库</a><span>/</span><?php echo e($tour['tournament_name']); ?>
    </div>

    <div class="container">
        <!-- ===================== 赛事大图 ===================== -->
        <div class="hero">
            <div class="hero-top">
                <span class="hero-year"><?php echo e($tour['tournament_year']); ?></span>
                <span class="tier <?php echo $tierClass; ?>"><?php echo e($tour['tournament_level'] ?: '未知级别'); ?></span>
            </div>
            <h1 class="hero-name"><?php echo e($tour['tournament_name']); ?></h1>
            <div class="hero-meta">
                <span class="meta-chip">奖金池：<strong><?php echo $prize; ?></strong></span>
                <?php if ($dates !== ''): ?>
                    <span class="meta-chip">赛程：<strong><?php echo $dates; ?></strong></span>
                <?php endif; ?>
                <span class="meta-chip">参赛战队：<strong><?php echo count($standings); ?></strong> 支</span>
            </div>
            <a class="review-shortcut" href="#comments">查看评价（共 <?php echo count($comments); ?> 条）↓</a>
        </div>

        <!-- ===================== 战队排名 ===================== -->
        <h2 class="section-title">战队排名 <span class="count"><?php echo count($standings); ?> 支参赛队伍</span></h2>
        <?php if (count($standings) === 0): ?>
            <div class="empty">
                <div class="empty-code">NO TEAMS</div>
                <p>该赛事暂无战队排名数据</p>
            </div>
        <?php else: ?>
            <div class="standings">
                <?php foreach ($standings as $row): ?>
                    <?php
                        $champion = ($row['placement'] === '1st');
                        $logo = !empty($row['logo_url']) ? e($row['logo_url']) : DEFAULT_AVATAR;
                        $placeText = ($row['placement'] === '决赛进行中') ? '决赛中' : e($row['placement']);
                    ?>
                    <div class="stand-row<?php echo $champion ? ' champion' : ''; ?>">
                        <span class="stand-place"><?php echo $placeText; ?></span>
                        <img class="stand-logo" src="<?php echo $logo; ?>"
                             data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                             onerror="this.onerror=null;this.src=this.dataset.fallback;"
                             alt="<?php echo e($row['team_tag']); ?> 队标">
                        <span class="stand-name">
                            <a href="team_detail.php?id=<?php echo (int)$row['team_id']; ?>"><?php echo e($row['team_name']); ?></a>
                            <span class="stand-tag"><?php echo e($row['team_tag']); ?></span>
                        </span>
                        <span class="stand-wl"><?php echo (int)$row['wins']; ?> 胜 / <?php echo (int)$row['losses']; ?> 负</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ===================== ACS 前 10 名 ===================== -->
        <h2 class="section-title">ACS 前 10 名 <span class="count">本赛事最高场均战斗评分</span></h2>
        <?php if (count($topAcs) === 0): ?>
            <div class="empty">
                <div class="empty-code">NO DATA</div>
                <p>该赛事暂无选手数据</p>
            </div>
        <?php else: ?>
            <div class="rank-list">
                <?php $rank = 0; ?>
                <?php foreach ($topAcs as $row): $rank++; ?>
                    <?php
                        $avatar = !empty($row['photo_url']) ? e($row['photo_url']) : DEFAULT_AVATAR;
                        $team = !empty($row['team_tag']) ? e($row['team_tag']) : 'TBD';
                    ?>
                    <div class="rank-row rank-<?php echo $rank; ?>">
                        <div class="rank-num"><?php echo $rank; ?></div>
                        <img class="rank-avatar" src="<?php echo $avatar; ?>"
                             data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                             onerror="this.onerror=null;this.src=this.dataset.fallback;"
                             alt="<?php echo e($row['ign']); ?> 头像">
                        <div class="rank-info">
                            <span class="rank-ign"><a href="player_detail.php?id=<?php echo (int)$row['player_id']; ?>"><?php echo e($row['ign']); ?></a></span>
                            <span class="rank-team"><?php echo $team; ?></span>
                        </div>
                        <div class="rank-stat acs">
                            <span class="stat-label">ACS</span>
                            <span class="stat-value"><?php echo e(number_format((float)$row['acs'], 2)); ?></span>
                        </div>
                        <div class="rank-stat">
                            <span class="stat-label">K/D</span>
                            <span class="stat-value"><?php echo e(number_format((float)$row['kd'], 2)); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <!-- ===================== 赛事评论 ===================== -->
        <section id="comments">
            <h2 class="section-title">评论区 <span class="count">共 <?php echo count($comments); ?> 条</span></h2>
            <div class="review-action">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="review-button" type="button" id="open-review">发表评价</button>
                <?php else: ?>
                    <a class="review-button" href="login.php?return_to=<?php echo rawurlencode('tournament_detail.php?id=' . $id . '#comments'); ?>">发表评价</a>
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
                                    <form class="comment-delete-form" method="post" action="tournament_detail.php?id=<?php echo $id; ?>#comments">
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
                        <h3 id="review-title">评价 <?php echo e($tour['tournament_name']); ?></h3>
                        <p>以 <?php echo e($_SESSION['username']); ?> 的身份发表</p>
                    </div>
                    <button class="review-close" type="button" id="close-review" aria-label="关闭弹窗">&times;</button>
                </div>
                <form class="review-form" method="post" action="tournament_detail.php?id=<?php echo $id; ?>#comments">
                    <?php if ($commentError !== ''): ?>
                        <p class="comment-error" role="alert"><?php echo e($commentError); ?></p>
                    <?php endif; ?>
                    <div>
                        <span class="review-label">给赛事打分</span>
                        <div class="star-picker" role="radiogroup" aria-label="评分，1至5星">
                            <?php for ($score = 5; $score >= 1; $score--): ?>
                                <input type="radio" name="rating" id="rating-<?php echo $score; ?>" value="<?php echo $score; ?>"<?php echo $formRating === $score ? ' checked' : ''; ?> required>
                                <label for="rating-<?php echo $score; ?>" aria-label="<?php echo $score; ?>星">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <label class="review-label" for="review-content">说说你的看法
                        <textarea id="review-content" name="content" placeholder="分享你对这场赛事的评价…" required><?php echo e($formContent); ?></textarea>
                    </label>
                    <button class="review-submit" type="submit">发布评价</button>
                </form>
            </dialog>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

<?php if ($tour && isset($_SESSION['user_id'])): ?>
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
