<?php
session_start();
/* =============================================================
   文件：tournaments.php
   项目：VALORANT 赛事库（期末网页作业）
   说明：读取 tournament 表，卡片展示赛事的年份、名称、
         级别（国际赛/联赛）和奖金池
   ============================================================= */

// ---------------- 1. 数据库配置 ----------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'valorant_db');
define('DB_USER', 'root');
define('DB_PASS', '');

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

// ---------------- 3. 按名称、年份和级别筛选赛事 ----------------
$years = $pdo->query('SELECT DISTINCT tournament_year FROM tournament ORDER BY tournament_year DESC')->fetchAll(PDO::FETCH_COLUMN);
$levels = $pdo->query("SELECT DISTINCT tournament_level FROM tournament WHERE tournament_level IS NOT NULL AND tournament_level <> '' ORDER BY tournament_level")->fetchAll(PDO::FETCH_COLUMN);
$totalCount = (int)$pdo->query('SELECT COUNT(*) FROM tournament')->fetchColumn();

$search = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8') : '';
$year = isset($_GET['year']) && is_string($_GET['year']) && in_array($_GET['year'], $years, true) ? $_GET['year'] : '';
$level = isset($_GET['level']) && is_string($_GET['level']) && in_array($_GET['level'], $levels, true) ? $_GET['level'] : '';

$where = array();
$params = array();
if ($search !== '') {
    $where[] = 'tournament_name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}
if ($year !== '') {
    $where[] = 'tournament_year = :year';
    $params[':year'] = $year;
}
if ($level !== '') {
    $where[] = 'tournament_level = :level';
    $params[':level'] = $level;
}

$sql = 'SELECT tournament_id, tournament_name, tournament_year, tournament_level, prize_pool FROM tournament';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY tournament_year DESC, tournament_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tournaments = $stmt->fetchAll();

// ---------------- 4. 输出转义函数（防 XSS） ----------------
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ---------------- 5. 导航高亮辅助函数 ----------------
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
<title>赛事库 - VALORANT 选手数据库</title>
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
    position: sticky;            /* 吸顶导航，滚动时始终可见 */
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
.nav-links a.active { color: #fff; background: #ff4655; }   /* 当前页面高亮 */
@media (max-width: 640px) {
    .nav-inner { height: auto; flex-direction: column; gap: 10px; padding: 12px 16px; }
    .nav-links { flex-wrap: wrap; justify-content: center; }
    .nav-links a { padding: 6px 12px; font-size: 14px; }
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

/* ===================== 赛事卡片网格 ===================== */
.container { max-width: 1280px; margin: 0 auto; padding: 36px 20px 60px; }
.filters { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; margin-bottom: 18px; }
.filters label { display: flex; flex: 1; min-width: 150px; flex-direction: column; gap: 7px; color: #c7d1da; font-size: 13px; }
.filters .search-field { flex: 2; min-width: 220px; }
.filters input, .filters select { width: 100%; min-height: 42px; padding: 9px 11px; border: 1px solid #3a4756; border-radius: 6px; background: #16222c; color: #ece8e1; font: inherit; font-size: 15px; }
.filters input:focus, .filters select:focus { outline: 2px solid #ff4655; outline-offset: 1px; }
.filters button, .clear-filter { min-height: 42px; padding: 9px 18px; border-radius: 6px; font: inherit; font-weight: 700; cursor: pointer; }
.filters button { border: 0; background: #ff4655; color: #fff; }
.filters button:hover { background: #e63848; }
.clear-filter { display: inline-flex; align-items: center; border: 1px solid #3a4756; color: #c7d1da; text-decoration: none; }
.clear-filter:hover { border-color: #ff4655; color: #fff; }
.result-count { margin-bottom: 18px; color: #aebbc6; font-size: 14px; }
.empty-results { padding: 50px 20px; border: 1px solid #2b3b4a; border-radius: 10px; background: #16222c; color: #aebbc6; text-align: center; }
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}
/* 赛事卡片整体是链接，点击跳转赛事详情页 */
a.card {
    display: block;
    text-decoration: none;
    color: inherit;
    background: #16222c;
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    padding: 18px;
    transition: transform .25s, border-color .25s, box-shadow .25s;
}
a.card:hover {
    transform: translateY(-4px);
    border-color: #ff4655;
    box-shadow: 0 10px 24px rgba(255, 70, 85, .15);
}
.tour-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.year { font-size: 28px; font-weight: 900; letter-spacing: 2px; color: #ff4655; }
.tier {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 2px;
    padding: 4px 12px;
    border-radius: 4px;
}
.tier-intl { background: #ff4655; color: #fff; }                  /* 国际赛：红色实底 */
.tier-league { background: #1a2530; border: 1px solid #2b3b4a; color: #c7d1da; }  /* 联赛：深灰描边 */
.tour-name {
    font-size: 17px;
    font-weight: 700;
    color: #ece8e1;
    margin-top: 14px;
    line-height: 1.5;
}
.prize { margin-top: 12px; color: #768079; font-size: 14px; }
.prize strong { color: #fff; font-size: 18px; margin-left: 4px; }
.prize .prize-na { color: #5a6a7a; }

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
    .grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }
    .year { font-size: 22px; }
    .prize { color: #aebbc6; }
    .prize .prize-na { color: #9ba8b6; }
}
@media (max-width: 360px) {
    .logo { font-size: 23px; letter-spacing: 2px; }
    .logo .slash { margin: 0 4px; }
    .logo .logo-sub { font-size: 15px; letter-spacing: 1px; }
    .tagline { font-size: 11px; letter-spacing: 1px; }
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
    <h1 class="logo">VALORANT<span class="slash">//</span><span class="logo-sub">赛事库</span></h1>
    <p class="tagline">TOURNAMENT DATABASE · 共 <?php echo $totalCount; ?> 项赛事</p>
</header>

<div class="container">
    <form class="filters" method="get" action="tournaments.php">
        <label class="search-field">赛事名称
            <input type="search" name="q" maxlength="100" value="<?php echo e($search); ?>" placeholder="例如 Pacific、VCT CN">
        </label>
        <label>年份
            <select name="year">
                <option value="">全部年份</option>
                <?php foreach ($years as $option): ?>
                    <option value="<?php echo e($option); ?>" <?php echo $year === (string)$option ? 'selected' : ''; ?>><?php echo e($option); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>级别
            <select name="level">
                <option value="">全部级别</option>
                <?php foreach ($levels as $option): ?>
                    <option value="<?php echo e($option); ?>" <?php echo $level === (string)$option ? 'selected' : ''; ?>><?php echo e($option); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">查找</button>
        <?php if ($search !== '' || $year !== '' || $level !== ''): ?>
            <a class="clear-filter" href="tournaments.php">清除</a>
        <?php endif; ?>
    </form>
    <p class="result-count">显示 <?php echo count($tournaments); ?> / <?php echo $totalCount; ?> 项赛事</p>
    <?php if (empty($tournaments)): ?>
        <p class="empty-results">未找到赛事，请换个名称或筛选条件。</p>
    <?php else: ?>
    <div class="grid">
        <?php foreach ($tournaments as $t): ?>
            <?php
                // 级别徽章样式：国际赛红色实底，联赛深灰描边
                $tierClass = ($t['tournament_level'] === '国际赛') ? 'tier-intl' : 'tier-league';
                // 奖金池：空值显示"待公布"，有值则格式化为千分位
                $prize = !empty($t['prize_pool'])
                       ? '<strong>$' . e(number_format((float)$t['prize_pool'])) . '</strong>'
                       : '<span class="prize-na">无现金奖金</span>';
            ?>
            <a class="card" href="tournament_detail.php?id=<?php echo (int)$t['tournament_id']; ?>">
                <div class="tour-head">
                    <span class="year"><?php echo e($t['tournament_year']); ?></span>
                    <span class="tier <?php echo $tierClass; ?>"><?php echo e($t['tournament_level'] ?: '未知级别'); ?></span>
                </div>
                <h2 class="tour-name"><?php echo e($t['tournament_name']); ?></h2>
                <p class="prize">奖金池：<?php echo $prize; ?></p>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

</body>
</html>
