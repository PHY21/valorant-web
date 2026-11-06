<?php
session_start();
/* =============================================================
   文件：agents.php
   项目：VALORANT 特工库（期末网页作业）
   说明：读取 agent 表，按定位（决斗者/先锋/控场/哨卫）分组，
         卡片展示全部特工的图标、名称、出身和简介
   ============================================================= */

// ---------------- 1. 数据库配置 ----------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'valorant_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// 默认占位图（图标为空或加载失败时显示）
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

// ---------------- 3. 查询全部特工 ----------------
$sql = "SELECT agent_id, agent_name, agent_role, origin, description, icon_url
        FROM agent
        ORDER BY agent_id ASC";

$stmt = $pdo->prepare($sql);          // 预处理语句
$stmt->execute();
$agents = $stmt->fetchAll();

// ---------------- 4. 按定位分组 ----------------
$groups = array();
foreach ($agents as $a) {
    $role = !empty($a['agent_role']) ? $a['agent_role'] : '其他';
    if (!isset($groups[$role])) { $groups[$role] = array(); }
    $groups[$role][] = $a;
}
// 固定分组顺序：决斗者 → 先锋 → 控场 → 哨卫 → 其他
$roleOrder = array('决斗者', '先锋', '控场', '哨卫');

// ---------------- 5. 输出转义函数（防 XSS） ----------------
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ---------------- 6. 导航高亮辅助函数 ----------------
$current = basename($_SERVER['SCRIPT_NAME']);

function nav_link($file, $label) {
    global $current;
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
<title>特工库 - VALORANT 选手数据库</title>
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

/* ===================== 定位分组 ===================== */
.role-section { margin-bottom: 44px; }
.role-title {
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
.role-count { margin-left: auto; font-size: 13px; color: #5a6a7a; letter-spacing: 1px; }

/* ===================== 特工卡片网格 ===================== */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 20px;
}
/* 特工卡片整体是链接，点击跳转特工详情页 */
a.agent-card {
    display: block;
    text-decoration: none;
    color: inherit;
    background: linear-gradient(180deg, #1b2733 0%, #141f29 100%);
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    overflow: hidden;
    transition: transform .25s, border-color .25s, box-shadow .25s;
}
a.agent-card:hover {
    transform: translateY(-6px);
    border-color: #ff4655;
    box-shadow: 0 10px 28px rgba(255, 70, 85, .18);
}
.agent-img {
    position: relative;
    height: 150px;
    background: #0b1117;
    overflow: hidden;
}
.agent-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top center;   /* 立绘顶部对齐，确保显示脸部 */
    display: block;
    transition: transform .35s;
}
.agent-card:hover .agent-img img { transform: scale(1.06); }
/* 定位徽章（贴在图标左上角） */
.role-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2px;
    color: #fff;
}
.role-badge.duelist { background: #ff4655; }        /* 决斗者：红 */
.role-badge.initiator { background: #ff9a3d; }      /* 先锋：橙 */
.role-badge.controller { background: #3d8bff; }     /* 控场：蓝 */
.role-badge.sentinel { background: #3ddc84; }       /* 哨卫：绿 */

.agent-body { padding: 14px 16px 16px; }
.agent-name { font-size: 19px; font-weight: 800; color: #fff; letter-spacing: 1px; }
.agent-origin { font-size: 12px; color: #768079; margin-top: 3px; }
.agent-desc {
    margin-top: 10px;
    font-size: 13px;
    color: #c7d1da;
    line-height: 1.6;
}

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
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 14px;
    }
    .agent-img { height: 110px; }
}
</style>
</head>
<body>

<!-- ===================== 全局导航栏（五个页面共用，当前页自动高亮） ===================== -->
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
    <h1 class="logo">VALORANT<span class="slash">//</span><span class="logo-sub">特工库</span></h1>
    <p class="tagline">AGENT DATABASE · 共 <?php echo count($agents); ?> 名特工 · 4 大定位</p>
</header>

<div class="container">
    <?php
        // 按固定顺序输出分组：决斗者 → 先锋 → 控场 → 哨卫 → 其他
        $roleBadgeMap = array(
            '决斗者' => 'duelist',
            '先锋'   => 'initiator',
            '控场'   => 'controller',
            '哨卫'   => 'sentinel'
        );
        $outputRoles = $roleOrder;
        foreach ($groups as $role => $list) {
            if (!in_array($role, $outputRoles)) { $outputRoles[] = $role; }
        }
    ?>
    <?php foreach ($outputRoles as $role): ?>
        <?php if (!isset($groups[$role])) { continue; } ?>
        <section class="role-section">
            <h2 class="role-title">
                <?php echo e($role); ?>
                <span class="role-count"><?php echo count($groups[$role]); ?> 名</span>
            </h2>
            <div class="grid">
                <?php foreach ($groups[$role] as $a): ?>
                    <?php
                        $icon = !empty($a['icon_url']) ? e($a['icon_url']) : DEFAULT_AVATAR;
                        $badge = isset($roleBadgeMap[$role]) ? $roleBadgeMap[$role] : 'sentinel';
                    ?>
                    <a class="agent-card" href="agent_detail.php?id=<?php echo (int)$a['agent_id']; ?>">
                        <div class="agent-img">
                            <img src="<?php echo $icon; ?>"
                                 data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                                 onerror="this.onerror=null;this.src=this.dataset.fallback;"
                                 alt="<?php echo e($a['agent_name']); ?> 图标">
                            <span class="role-badge <?php echo $badge; ?>"><?php echo e($role); ?></span>
                        </div>
                        <div class="agent-body">
                            <h3 class="agent-name"><?php echo e($a['agent_name']); ?></h3>
                            <p class="agent-origin">出身：<?php echo e(!empty($a['origin']) ? $a['origin'] : '未知'); ?></p>
                            <p class="agent-desc"><?php echo e(!empty($a['description']) ? $a['description'] : '暂无简介'); ?></p>
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
