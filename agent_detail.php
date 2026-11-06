<?php
session_start();
/* =============================================================
   文件：agent_detail.php
   项目：VALORANT 特工详情页（期末网页作业）
   说明：接收 URL 参数 id（GET），查询 agent 表展示特工大图、
         名称、定位、出身、简介，并展示全部技能（skills 字段）
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

// ---------------- 3. 接收并校验 URL 参数 id ----------------
// 只允许纯数字 id，非数字一律视为无效（同时也防 SQL 注入）
$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$id = ctype_digit($id) ? (int)$id : 0;

$agent = null;
if ($id > 0) {
    $sql = "SELECT agent_id, agent_name, agent_role, origin, description, icon_url, skills, video_url
            FROM agent
            WHERE agent_id = :id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);   // 参数绑定：id 只当作整数数据
    $stmt->execute();
    $agent = $stmt->fetch();
}

// ---------------- 4. 输出转义函数（防 XSS） ----------------
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ---------------- 5. 导航高亮辅助函数 ----------------
$current = basename($_SERVER['SCRIPT_NAME']);

function nav_link($file, $label) {
    global $current;
    if ($current === 'team_detail.php')   { $current = 'teams.php'; }
    if ($current === 'player_detail.php') { $current = 'index.php'; }
    if ($current === 'agent_detail.php')  { $current = 'agents.php'; }
    $class = ($current === $file) ? ' class="active"' : '';
    return '<a href="' . $file . '"' . $class . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $agent ? e($agent['agent_name']) : '特工未找到'; ?> - VALORANT 选手数据库</title>
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
.container { max-width: 960px; margin: 0 auto; padding: 36px 20px 60px; }
.breadcrumb {
    max-width: 960px;
    margin: 22px auto 0;
    padding: 0 20px;
    color: #5a6a7a;
    font-size: 13px;
}
.breadcrumb a { color: #768079; text-decoration: none; }
.breadcrumb a:hover { color: #ff4655; }
.breadcrumb span { margin: 0 8px; color: #3a4756; }

/* ===================== 特工大图（Hero） ===================== */
.hero {
    display: flex;
    gap: 28px;
    background: linear-gradient(135deg, #1b2733, #141f29);
    border: 1px solid #2b3b4a;
    border-radius: 12px;
    padding: 28px;
    align-items: center;
}
.hero-img {
    width: 190px;
    height: 253px;   /* 3:4 比例，与官方立绘一致，完整显示脸部 */
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid #2b3b4a;
    background: #0b1117;
    flex-shrink: 0;
}
.hero-img img { width: 100%; height: 100%; object-fit: cover; }
.hero-info { flex: 1; min-width: 0; }
.hero-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.role-badge {
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2px;
    color: #fff;
}
.role-badge.duelist { background: #ff4655; }
.role-badge.initiator { background: #ff9a3d; }
.role-badge.controller { background: #3d8bff; }
.role-badge.sentinel { background: #3ddc84; }
.origin-badge {
    border: 1px solid rgba(255, 70, 85, .5);
    color: #ff9aa3;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 13px;
}
.hero-name { font-size: 36px; font-weight: 900; color: #fff; letter-spacing: 2px; }
.hero-desc { margin-top: 14px; color: #c7d1da; font-size: 15px; line-height: 1.8; }

/* ===================== 技能介绍视频 ===================== */
.video-box {
    width: 100%;
    aspect-ratio: 16 / 9;
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    overflow: hidden;
    background: #0b1117;
}
.video-box iframe { width: 100%; height: 100%; border: 0; }

/* ===================== 技能列表 ===================== */
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

.skills-list { display: flex; flex-direction: column; gap: 10px; }
.skill-item {
    display: flex;
    align-items: baseline;
    gap: 14px;
    background: #16222c;
    border: 1px solid #2b3b4a;
    border-radius: 8px;
    padding: 12px 16px;
    transition: border-color .2s, transform .2s;
}
.skill-item:hover { border-color: #ff4655; transform: translateX(4px); }
.skill-item.ult {
    border-color: rgba(255, 70, 85, .5);
    background: rgba(255, 70, 85, .06);
}
.skill-name {
    flex-shrink: 0;
    min-width: 110px;
    color: #ff4655;
    font-weight: 800;
    letter-spacing: 1px;
    font-size: 15px;
}
.skill-item.ult .skill-name { color: #ffd700; }   /* 大招技能名金色 */
.skill-item.ult .skill-name::before { content: "大招 "; }
.skill-desc { color: #c7d1da; font-size: 14px; line-height: 1.7; }

/* ===================== 空状态 ===================== */
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
@media (max-width: 700px) {
    .hero { flex-direction: column; align-items: center; text-align: center; }
    .hero-img { width: 150px; height: 200px; }
    .hero-name { font-size: 28px; }
    .skill-item { flex-direction: column; gap: 6px; }
    .skill-name { min-width: 0; }
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

<?php if (!$agent): ?>
    <!-- ===================== 特工不存在 ===================== -->
    <div class="container">
        <div class="empty">
            <div class="empty-code">404 AGENT</div>
            <p>没有找到 id 为 “<?php echo e((string)$id); ?>” 的特工，可能链接有误</p>
            <p><a href="agents.php">← 返回特工库</a></p>
        </div>
    </div>
<?php else: ?>
    <?php
        $roleBadgeMap = array('决斗者' => 'duelist', '先锋' => 'initiator', '控场' => 'controller', '哨卫' => 'sentinel');
        $role = !empty($agent['agent_role']) ? $agent['agent_role'] : '其他';
        $badge = isset($roleBadgeMap[$role]) ? $roleBadgeMap[$role] : 'sentinel';
        $icon = !empty($agent['icon_url']) ? e($agent['icon_url']) : DEFAULT_AVATAR;
        $skillsText = !empty($agent['skills']) ? $agent['skills'] : '';
        $hasSkills = ($skillsText !== '' && mb_strpos($skillsText, '暂无') !== 0);
    ?>
    <!-- ===================== 面包屑 ===================== -->
    <div class="breadcrumb">
        <a href="agents.php">特工库</a><span>/</span><?php echo e($agent['agent_name']); ?>
    </div>

    <div class="container">
        <!-- ===================== 特工大图 ===================== -->
        <div class="hero">
            <div class="hero-img">
                <img src="<?php echo $icon; ?>"
                     data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                     onerror="this.onerror=null;this.src=this.dataset.fallback;"
                     alt="<?php echo e($agent['agent_name']); ?> 图标">
            </div>
            <div class="hero-info">
                <div class="hero-badges">
                    <span class="role-badge <?php echo $badge; ?>"><?php echo e($role); ?></span>
                    <span class="origin-badge">出身：<?php echo e(!empty($agent['origin']) ? $agent['origin'] : '未知'); ?></span>
                </div>
                <h1 class="hero-name"><?php echo e($agent['agent_name']); ?></h1>
                <p class="hero-desc"><?php echo e(!empty($agent['description']) ? $agent['description'] : '暂无简介'); ?></p>
            </div>
        </div>

        <!-- ===================== 技能介绍视频（B站/YouTube 嵌入） ===================== -->
        <?php if (!empty($agent['video_url'])): ?>
            <?php
                // B站：提取 BV 号嵌入播放器；YouTube：提取视频 ID 嵌入播放器
                $bvid = '';
                $ytid = '';
                if (preg_match('/BV([0-9A-Za-z]+)/', $agent['video_url'], $m)) {
                    $bvid = 'BV' . $m[1];
                } elseif (preg_match('/youtube\.com\/watch\?v=([0-9A-Za-z_-]+)/', $agent['video_url'], $m2)) {
                    $ytid = $m2[1];
                }
            ?>
            <?php if ($bvid !== '' || $ytid !== ''): ?>
                <h2 class="section-title">技能介绍视频</h2>
                <div class="video-box">
                    <?php if ($bvid !== ''): ?>
                        <iframe src="//player.bilibili.com/player.html?bvid=<?php echo e($bvid); ?>&page=1&high_quality=1&danmaku=0"
                                scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true"></iframe>
                    <?php else: ?>
                        <iframe src="https://www.youtube.com/embed/<?php echo e($ytid); ?>"
                                title="技能介绍视频" frameborder="0"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen></iframe>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ===================== 技能列表 ===================== -->
        <?php if ($hasSkills): ?>
            <h2 class="section-title">技能 <span class="count">共 <?php echo count(explode('；', $skillsText)); ?> 项</span></h2>
            <div class="skills-list">
                <?php foreach (explode('；', $skillsText) as $skill): ?>
                    <?php
                        $skill = trim($skill);
                        if ($skill === '') { continue; }
                        $colonPos = mb_strpos($skill, '：');
                        $isUlt = (mb_strpos($skill, '大招-') === 0);
                        $rawName = $isUlt ? mb_substr($skill, 3, $colonPos - 3) : mb_substr($skill, 0, $colonPos);
                        $desc = ($colonPos !== false) ? mb_substr($skill, $colonPos + 1) : $skill;
                    ?>
                    <div class="skill-item<?php echo $isUlt ? ' ult' : ''; ?>">
                        <span class="skill-name"><?php echo e($rawName); ?></span>
                        <span class="skill-desc"><?php echo e($desc); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <h2 class="section-title">技能</h2>
            <div class="empty">
                <div class="empty-code">NO DATA</div>
                <p>该特工暂无技能数据（数据库原创设定）</p>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

</body>
</html>
