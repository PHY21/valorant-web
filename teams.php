<?php
session_start();
/* =============================================================
   文件：teams.php
   项目：VALORANT 战队库（期末网页作业）
   说明：JOIN 查询 team 表 + region 表，按四大赛区分组展示战队。
         卡片显示 Logo、名字、缩写、两行截断的简介，
         点击整个卡片跳转到 team_detail.php?id=xxx
   ============================================================= */

// ---------------- 1. 数据库配置 ----------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'valorant_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// 默认占位图（Logo 为空或加载失败时显示）
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

// ---------------- 3. JOIN 查询全部战队 + 赛区 ----------------
// 按 region_id 排序，方便下面 PHP 按赛区分组展示
$sql = "SELECT t.team_id, t.team_name, t.team_tag, t.logo_url, t.founded_year, t.description,
               r.region_id, r.region_name, r.region_code
        FROM team AS t
        LEFT JOIN region AS r ON t.region_id = r.region_id
        ORDER BY r.region_id ASC, t.team_tag ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$teams = $stmt->fetchAll();

// ---------------- 4. 按赛区分组 ----------------
// $groups 结构：region_id => array('name'=>赛区名, 'code'=>缩写, 'teams'=>战队数组)
$groups = array();
foreach ($teams as $t) {
    $rid = $t['region_id'];
    if (!isset($groups[$rid])) {
        $groups[$rid] = array(
            'name'  => !empty($t['region_name']) ? $t['region_name'] : '未知赛区',
            'code'  => !empty($t['region_code']) ? $t['region_code'] : '??',
            'teams' => array()
        );
    }
    $groups[$rid]['teams'][] = $t;
}

// ---------------- 5. 输出转义函数（防 XSS） ----------------
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ---------------- 6. 导航高亮辅助函数 ----------------
$current = basename($_SERVER['SCRIPT_NAME']);

function nav_link($file, $label) {
    global $current;
    $class = ($current === $file) ? ' class="active"' : '';
    return '<a href="' . $file . '"' . $class . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>战队库 - VALORANT 选手数据库</title>
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


/* ===================== 赛区分组 ===================== */
.region-section { margin-bottom: 44px; }
.region-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 24px;
    color: #fff;
    letter-spacing: 2px;
    padding-bottom: 12px;
    border-bottom: 1px solid #2b3b4a;
    margin-bottom: 20px;
}
.region-code {
    background: #ff4655;
    color: #fff;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 2px;
    padding: 4px 10px;
    border-radius: 4px;
}
.region-count { margin-left: auto; font-size: 13px; color: #5a6a7a; letter-spacing: 1px; }

/* ===================== 战队卡片网格 ===================== */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 24px;
}
/* 整张卡片就是一个 <a> 链接，点击跳转战队详情页 */
a.card {
    display: block;
    text-decoration: none;
    color: inherit;
    background: linear-gradient(180deg, #1b2733 0%, #141f29 100%);
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    overflow: hidden;
    transition: transform .25s, border-color .25s, box-shadow .25s;
}
a.card:hover {
    transform: translateY(-6px);
    border-color: #ff4655;
    box-shadow: 0 10px 28px rgba(255, 70, 85, .18);
}
.team-logo {
    height: 140px;
    background: #ece8e1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.team-logo img {
    max-width: 76%;
    max-height: 100%;
    object-fit: contain;
    transition: transform .35s;
}
a.card:hover .team-logo img { transform: scale(1.08); }
.card-body { padding: 14px 16px 16px; }
.team-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.team-name { font-size: 17px; font-weight: 800; color: #fff; letter-spacing: 1px; }
.meta-tag {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2px;
    color: #fff;
    background: #ff4655;
    padding: 3px 8px;
    border-radius: 4px;
    flex-shrink: 0;
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

<!-- ===================== 头部标题 ===================== -->
<header class="site-header">
    <h1 class="logo">VALORANT<span class="slash">//</span><span class="logo-sub">战队库</span></h1>
    <p class="tagline">TEAM DATABASE · 共 <?php echo count($teams); ?> 支战队 · <?php echo count($groups); ?> 大赛区</p>
</header>

<div class="container">
    <?php foreach ($groups as $g): ?>
        <!-- ===================== 一个赛区 ===================== -->
        <section class="region-section">
            <h2 class="region-title">
                <span class="region-code"><?php echo e($g['code']); ?></span>
                <?php echo e($g['name']); ?>
                <span class="region-count"><?php echo count($g['teams']); ?> 支战队</span>
            </h2>
            <div class="grid">
                <?php foreach ($g['teams'] as $t): ?>
                    <?php
                        // Logo 为空时使用默认占位图
                        // 注意：简介不显示在列表卡片上，点进战队详情页才能看到
                        $logo = !empty($t['logo_url']) ? e($t['logo_url']) : DEFAULT_AVATAR;
                    ?>
                    <!-- 整张卡片可点击：跳转到战队详情页（id 用 (int) 强转，防止注入） -->
                    <a class="card" href="team_detail.php?id=<?php echo (int)$t['team_id']; ?>">
                        <div class="team-logo">
                            <img src="<?php echo $logo; ?>"
                                 data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                                 onerror="this.onerror=null;this.src=this.dataset.fallback;"
                                 alt="<?php echo e($t['team_tag']); ?> 队标">
                        </div>
                        <div class="card-body">
                            <div class="team-head">
                                <h3 class="team-name"><?php echo e($t['team_name']); ?></h3>
                                <span class="meta-tag"><?php echo e($t['team_tag']); ?></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

</body>
</html>
