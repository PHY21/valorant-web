<?php
session_start();
if (!isset($_SESSION['comment_delete_token'])) {
    $_SESSION['comment_delete_token'] = bin2hex(random_bytes(32));
}
/* =============================================================
   文件：team_detail.php
   项目：VALORANT 战队详情页（期末网页作业）
   说明：接收 URL 参数 id（GET），联表查询 team + region 展示
         战队 Logo、全名、赛区、成立年份、完整简介；
         下半部分查询 player 表列出该战队所有选手，
         点击选手卡片跳转 player_detail.php?id=xxx
   ============================================================= */

// ---------------- 1. 数据库配置 ----------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'valorant_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// 默认占位图（Logo / 头像为空或加载失败时显示）
define('DEFAULT_AVATAR',
    "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='400'%20height='240'%20viewBox='0%200%20400%20240'%3E%3Crect%20width='400'%20height='240'%20fill='%23131f2a'/%3E%3Cpath%20d='M170%2082%20L200%20166%20L230%2082'%20fill='none'%20stroke='%23ff4655'%20stroke-width='16'%20stroke-linecap='round'%20stroke-linejoin='round'/%3E%3Ctext%20x='200'%20y='208'%20text-anchor='middle'%20font-family='Arial,%20sans-serif'%20font-size='18'%20letter-spacing='6'%20fill='%235a6a7a'%3ENO%20LOGO%3C/text%3E%3C/svg%3E");

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

$team = null;
$players = array();
$comments = array();
$commentError = '';
$formContent = '';
$formRating = 5;

if ($id > 0) {
    // ---- 3.1 联表查询战队信息（team + region） ----
    $sql = "SELECT t.team_id, t.team_name, t.team_tag, t.founded_year,
                   t.logo_url, t.description, t.honors,
                   r.region_name, r.region_code
            FROM team AS t
            LEFT JOIN region AS r ON t.region_id = r.region_id
            WHERE t.team_id = :id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);   // 参数绑定：id 只当作整数数据
    $stmt->execute();
    $team = $stmt->fetch();

    if ($team) {
        // ---- 3.2 查询属于该战队的全部选手 ----
        $psql = "SELECT player_id, ign, real_name, player_role, photo_url
                 FROM player
                 WHERE team_id = :id
                 ORDER BY ign ASC";
        $pstmt = $pdo->prepare($psql);
        $pstmt->bindValue(':id', $id, PDO::PARAM_INT);
        $pstmt->execute();
        $players = $pstmt->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
                header('Location: login.php?return_to=' . rawurlencode('team_detail.php?id=' . $id . '#comments'), true, 302);
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
                header('Location: login.php?return_to=' . rawurlencode('team_detail.php?id=' . $id . '#comments'), true, 302);
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
                        WHERE comment_id = :comment_id AND target_type = 'team'
                        AND target_id = :target_id AND user_id = :user_id");
                    $delete->bindValue(':comment_id', $commentId, PDO::PARAM_INT);
                    $delete->bindValue(':target_id', $id, PDO::PARAM_INT);
                    $delete->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                    $delete->execute();
                }
                header('Location: team_detail.php?id=' . $id . '#comments', true, 303);
                exit;
            }
            $formContent = trim((string)($_POST['content'] ?? ''));
            $formRating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
            if ($formContent === '' || $formRating === false || $formRating < 1 || $formRating > 5) {
                $commentError = '请填写评论内容，并选择1至5星。';
            } else {
                $insert = $pdo->prepare("INSERT INTO `comment`
                    (target_type, target_id, user_id, user_name, content, rating)
                    VALUES ('team', :target_id, :user_id, :user_name, :content, :rating)");
                $insert->bindValue(':target_id', $id, PDO::PARAM_INT);
                $insert->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                $insert->bindValue(':user_name', $_SESSION['username']);
                $insert->bindValue(':content', $formContent);
                $insert->bindValue(':rating', $formRating, PDO::PARAM_INT);
                $insert->execute();
                header('Location: team_detail.php?id=' . $id . '#comments', true, 303);
                exit;
            }
        }

        $cstmt = $pdo->prepare("SELECT comment_id, user_id, user_name, content, rating, created_at
            FROM `comment`
            WHERE target_type = 'team' AND target_id = :id
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
    // 详情页没有独立导航项：战队详情页归属"战队库"
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
<title><?php echo $team ? e($team['team_name']) : '战队未找到'; ?> - VALORANT 选手数据库</title>
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


/* ===================== 战队大图（Hero） ===================== */
.hero {
    display: flex;
    gap: 28px;
    background: linear-gradient(135deg, #1b2733, #141f29);
    border: 1px solid #2b3b4a;
    border-radius: 12px;
    padding: 28px;
    align-items: center;
}
.hero-logo {
    width: 180px;
    height: 180px;
    background: #ece8e1;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 22px;
    flex-shrink: 0;
}
.hero-logo img { max-width: 82%; max-height: 100%; object-fit: contain; }
.hero-info { flex: 1; min-width: 0; }
.hero-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.badge-tag {
    background: #ff4655;
    color: #fff;
    font-weight: 800;
    letter-spacing: 2px;
    padding: 4px 12px;
    border-radius: 4px;
}
.badge-region {
    border: 1px solid rgba(255, 70, 85, .5);
    color: #ff9aa3;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 13px;
}
.hero-name { font-size: 34px; font-weight: 900; color: #fff; letter-spacing: 2px; }
.hero-meta { margin-top: 10px; color: #768079; font-size: 14px; letter-spacing: 1px; }
.hero-desc { margin-top: 14px; color: #c7d1da; font-size: 15px; line-height: 1.8; }
/* 战队荣誉：独立一行显示 */
.hero-honors {
    margin-top: 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: rgba(255, 70, 85, .06);
    border: 1px solid rgba(255, 70, 85, .35);
    border-radius: 8px;
    padding: 10px 14px;
}
.honors-label {
    flex-shrink: 0;
    background: #ff4655;
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2px;
    padding: 3px 10px;
    border-radius: 4px;
}
.honors-text { color: #ffd9dc; font-size: 14px; line-height: 1.7; margin: 0; }

/* ===================== 选手卡片 ===================== */
.players-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}
/* 选手卡片本身是链接，点击跳转选手详情页 */
a.player-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: linear-gradient(90deg, #1b2733, #141f29);
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    text-decoration: none;
    transition: transform .25s, border-color .25s, box-shadow .25s;
}
a.player-card:hover {
    border-color: #ff4655;
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(255, 70, 85, .15);
}
.player-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #2b3b4a;
    background: #0b1117;
    flex-shrink: 0;
}
.player-info { display: flex; flex-direction: column; min-width: 0; }
.player-ign { font-size: 17px; font-weight: 800; color: #fff; }
.player-real {
    font-size: 12px;
    color: #768079;
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.player-role { font-size: 12px; color: #ff4655; margin-top: 2px; font-weight: 700; }

@media (max-width: 700px) {
    .hero { flex-direction: column; align-items: center; text-align: center; }
    .hero-logo { width: 140px; height: 140px; }
    .hero-name { font-size: 26px; }
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
@media (max-width: 640px) {
    #comments { scroll-margin-top: 150px; }
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
                <a href="login.php">登录/注册</a>
            <?php endif; ?>
        </div>
    </div>
</nav>


<?php if (!$team): ?>
    <!-- ===================== 战队不存在 ===================== -->
    <div class="container">
        <div class="empty">
            <div class="empty-code">404 TEAM</div>
            <p>没有找到 id 为 “<?php echo e((string)$id); ?>” 的战队，可能链接有误或战队已被删除</p>
            <p><a href="teams.php">← 返回战队库</a></p>
        </div>
    </div>
<?php else: ?>
    <!-- ===================== 面包屑 ===================== -->
    <div class="breadcrumb">
        <a href="teams.php">战队库</a><span>/</span><?php echo e($team['team_name']); ?>
    </div>

    <div class="container">
        <!-- ===================== 战队大图 ===================== -->
        <div class="hero">
            <div class="hero-logo">
                <?php
                    $logo = !empty($team['logo_url']) ? e($team['logo_url']) : DEFAULT_AVATAR;
                    $region = !empty($team['region_name'])
                            ? e($team['region_name']) . ' · ' . e($team['region_code'])
                            : '未知赛区';
                    $year = !empty($team['founded_year']) ? e($team['founded_year']) . ' 年成立' : '成立年份未知';
                ?>
                <img src="<?php echo $logo; ?>"
                     data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                     onerror="this.onerror=null;this.src=this.dataset.fallback;"
                     alt="<?php echo e($team['team_tag']); ?> 队标">
            </div>
            <div class="hero-info">
                <div class="hero-badges">
                    <span class="badge-tag"><?php echo e($team['team_tag']); ?></span>
                    <span class="badge-region"><?php echo $region; ?></span>
                </div>
                <h1 class="hero-name"><?php echo e($team['team_name']); ?></h1>
                <p class="hero-meta">成立年份：<?php echo $year; ?></p>
                <p class="hero-desc"><?php echo e(!empty($team['description']) ? $team['description'] : '暂无简介'); ?></p>
                <!-- 战队荣誉：从介绍中独立出来，单独一行显示 -->
                <div class="hero-honors">
                    <span class="honors-label">荣誉</span>
                    <p class="honors-text"><?php echo e(!empty($team['honors']) ? $team['honors'] : '暂无荣誉记录'); ?></p>
                </div>
                <a class="review-shortcut" href="#comments">查看评价（共 <?php echo count($comments); ?> 条）↓</a>
            </div>
        </div>

        <!-- ===================== 战队选手列表 ===================== -->
        <h2 class="section-title">战队选手 <span class="count">共 <?php echo count($players); ?> 人</span></h2>
        <?php if (count($players) === 0): ?>
            <div class="empty">
                <div class="empty-code">0 PLAYER</div>
                <p>该战队暂时没有选手数据</p>
            </div>
        <?php else: ?>
            <div class="players-grid">
                <?php foreach ($players as $p): ?>
                    <?php
                        $avatar = !empty($p['photo_url']) ? e($p['photo_url']) : DEFAULT_AVATAR;
                    ?>
                    <!-- 点击选手卡片跳转到选手详情页 -->
                    <a class="player-card" href="player_detail.php?id=<?php echo (int)$p['player_id']; ?>">
                        <img class="player-avatar" src="<?php echo $avatar; ?>"
                             data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                             onerror="this.onerror=null;this.src=this.dataset.fallback;"
                             alt="<?php echo e($p['ign']); ?> 头像">
                        <div class="player-info">
                            <span class="player-ign"><?php echo e($p['ign']); ?></span>
                            <span class="player-real"><?php echo e(!empty($p['real_name']) ? $p['real_name'] : '真实姓名未知'); ?></span>
                            <span class="player-role"><?php echo e(!empty($p['player_role']) ? $p['player_role'] : '位置未知'); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <!-- ===================== 战队评论 ===================== -->
        <section id="comments">
            <h2 class="section-title">评论区 <span class="count">共 <?php echo count($comments); ?> 条</span></h2>
            <div class="review-action">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="review-button" type="button" id="open-review">发表评价</button>
                <?php else: ?>
                    <a class="review-button" href="login.php?return_to=<?php echo rawurlencode('team_detail.php?id=' . $id . '#comments'); ?>">发表评价</a>
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
                                    <form class="comment-delete-form" method="post" action="team_detail.php?id=<?php echo $id; ?>#comments">
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
                        <h3 id="review-title">评价 <?php echo e($team['team_name']); ?></h3>
                        <p>以 <?php echo e($_SESSION['username']); ?> 的身份发表</p>
                    </div>
                    <button class="review-close" type="button" id="close-review" aria-label="关闭弹窗">&times;</button>
                </div>
                <form class="review-form" method="post" action="team_detail.php?id=<?php echo $id; ?>#comments">
                    <?php if ($commentError !== ''): ?>
                        <p class="comment-error" role="alert"><?php echo e($commentError); ?></p>
                    <?php endif; ?>
                    <div>
                        <span class="review-label">给战队打分</span>
                        <div class="star-picker" role="radiogroup" aria-label="评分，1至5星">
                            <?php for ($score = 5; $score >= 1; $score--): ?>
                                <input type="radio" name="rating" id="rating-<?php echo $score; ?>" value="<?php echo $score; ?>"<?php echo $formRating === $score ? ' checked' : ''; ?> required>
                                <label for="rating-<?php echo $score; ?>" aria-label="<?php echo $score; ?>星">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <label class="review-label" for="review-content">说说你的看法
                        <textarea id="review-content" name="content" placeholder="分享你对这支战队的评价…" required><?php echo e($formContent); ?></textarea>
                    </label>
                    <button class="review-submit" type="submit">发布评价</button>
                </form>
            </dialog>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

<?php if ($team && isset($_SESSION['user_id'])): ?>
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
