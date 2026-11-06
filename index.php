<?php
session_start();
/* =============================================================
   文件：index.php
   项目：VALORANT 选手数据库（期末网页作业）
   环境：XAMPP + MySQL（localhost / root / 空密码 / valorant_db）
   功能：
     1. PDO 预处理语句连接数据库，防 SQL 注入
     2. 搜索框支持按选手ID(ign)或战队缩写(team_tag)模糊查询（JOIN + LIKE）
     3. 无搜索时默认展示 30 名明星选手（LIMIT 30，防止照片太多卡死）
     4. 深色电竞风（无畏契约红黑配色）响应式 CSS Grid 卡片布局
   ============================================================= */

// ---------------- 1. 数据库配置（请按自己环境修改） ----------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'valorant_db');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP 默认 root 密码为空

// 默认占位图：内嵌 SVG 数据，不依赖任何外部图片文件。
// 当 photo_url 为空、或图片加载失败时显示（一个红色 V 形标记 + NO IMAGE）
define('DEFAULT_AVATAR',
    "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='400'%20height='240'%20viewBox='0%200%20400%20240'%3E%3Crect%20width='400'%20height='240'%20fill='%23131f2a'/%3E%3Cpath%20d='M170%2082%20L200%20166%20L230%2082'%20fill='none'%20stroke='%23ff4655'%20stroke-width='16'%20stroke-linecap='round'%20stroke-linejoin='round'/%3E%3Ctext%20x='200'%20y='208'%20text-anchor='middle'%20font-family='Arial,%20sans-serif'%20font-size='18'%20letter-spacing='6'%20fill='%235a6a7a'%3ENO%20IMAGE%3C/text%3E%3C/svg%3E");

// ---------------- 2. 使用 PDO 连接数据库 ----------------
try {
    // DSN 中指定 charset=utf8mb4，保证中文不乱码
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // 出错时抛出异常
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // 结果集以关联数组返回
        PDO::ATTR_EMULATE_PREPARES   => false                   // 使用真正的预处理语句
    ));
} catch (PDOException $e) {
    // 连接失败时给出友好提示（正式上线时可隐藏错误细节）
    die('数据库连接失败，请确认 XAMPP 的 MySQL 已启动：' . htmlspecialchars($e->getMessage()));
}

// ---------------- 3. 获取搜索关键词 ----------------
// 从 GET 参数 q 读取用户输入（搜索框以 GET 方式提交到本页）
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';

// ---------------- 4. 组装 SQL 并执行（预处理 + 参数绑定，防 SQL 注入） ----------------
// 使用 LEFT JOIN：即使选手没有战队也能正常显示
// 注意：team 表的主键字段是 team_id（已按实际表结构确认），JOIN 用它关联
$sql = "SELECT p.player_id, p.ign, p.real_name, p.nationality, p.player_role, p.photo_url,
               t.team_tag
        FROM player  AS p
        LEFT JOIN team AS t ON p.team_id = t.team_id";

if ($keyword !== '') {
    // JOIN 关联查询 + LIKE 模糊匹配：选手ID 或 战队缩写
    // （MySQL 默认排序规则下 LIKE 不区分大小写，搜 edg 也能匹配 EDG）
    $sql .= " WHERE p.ign LIKE :kw1 OR t.team_tag LIKE :kw2";   // 原生预处理下同一占位符不能重复，拆成两个
    // 搜索时显示全部结果；相关性排序：ID 以关键词开头的排最前，其次按字母序
    $sql .= " ORDER BY (p.ign LIKE :kw3) DESC, p.ign ASC";
} else {
    // 默认展示 30 名明星选手（按国际知名度排序），防止一次加载 200 多张照片卡死
    // FIELD：名单内选手按此顺序排最前，其余排后；LIMIT 30 只取前 30 名明星
    $starList = "'ZmjjKK','aspas','zekken','Demon1','Derke','Boaster','f0rsakeN','Jinggg','CHICHOO','Smoggy','nobody','Ethan','jawgemo','Less','saadhak','whzy','FengF','Life','stew','OXY','Chronicle','Alfajer','Wo0t','d4v41','BuZz','Meteor','kiNgg','mada','leaf','johnqt'";
    // (FIELD=0) 让非名单选手排最后；FIELD 正序让名单按知名度顺序排列
    $sql .= " ORDER BY (FIELD(p.ign, " . $starList . ") = 0) ASC, FIELD(p.ign, " . $starList . ") ASC, p.ign ASC LIMIT 30";
}

$stmt = $pdo->prepare($sql);                 // 预处理语句：先占位再绑定，杜绝 SQL 注入
if ($keyword !== '') {
    $like = '%' . $keyword . '%';            // 前后加 % 实现模糊匹配
    $stmt->bindValue(':kw1', $like, PDO::PARAM_STR); // 参数绑定，用户输入只当作“数据”处理
    $stmt->bindValue(':kw2', $like, PDO::PARAM_STR);
    $prefix = $keyword . '%';                    // 前缀匹配参数：用于相关性排序（匹配在开头的排最前）
    $stmt->bindValue(':kw3', $prefix, PDO::PARAM_STR);
}
$stmt->execute();
$players = $stmt->fetchAll();                // 取出结果（搜索时为全部匹配，默认 30 名明星）

// 搜索时再单独统计符合条件的总条数（用于提示“共找到 X 条”）
$total = 0;
if ($keyword !== '') {
    $countSql = "SELECT COUNT(*)
                 FROM player AS p
                 LEFT JOIN team AS t ON p.team_id = t.team_id
                 WHERE p.ign LIKE :kw1 OR t.team_tag LIKE :kw2";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->bindValue(':kw1', $like, PDO::PARAM_STR);
    $countStmt->bindValue(':kw2', $like, PDO::PARAM_STR);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
}

// ---------------- 5. 输出转义函数（防止 XSS） ----------------
// 所有从数据库读出的内容输出到 HTML 前都要经过这个函数转义
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
// ---------------- 导航高亮辅助函数 ----------------
// 根据当前脚本文件名判断哪个导航链接处于激活状态
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
<title>VALORANT 选手数据库</title>
<style>
/* ===================== 全局 ===================== */
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    background: #0f1923;                 /* 无畏契约主背景色 */
    color: #ece8e1;
    font-family: "Segoe UI", "Microsoft YaHei", "PingFang SC", sans-serif;
    min-height: 100vh;
    /* 页面顶部淡淡的红色氛围光 */
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
    padding: 54px 20px 26px;
    border-bottom: 1px solid #2b3b4a;
}
.logo {
    font-size: 44px;
    font-weight: 900;
    letter-spacing: 6px;
    text-transform: uppercase;
    color: #fff;
}
.logo .slash { color: #ff4655; margin: 0 12px; }      /* 强调色 #ff4655 */
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

/* ===================== 搜索框 ===================== */
.search-box {
    display: flex;
    max-width: 680px;
    margin: 36px auto 10px;
    padding: 0 16px;
}
.search-box input {
    flex: 1;
    min-width: 0;
    padding: 14px 20px;
    font-size: 16px;
    color: #ece8e1;
    background: #1a2530;
    border: 2px solid #2b3b4a;
    border-right: none;
    border-radius: 6px 0 0 6px;
    outline: none;
    transition: border-color .2s;
}
.search-box input:focus { border-color: #ff4655; }
.search-box input::placeholder { color: #5a6a7a; }
.search-box button {
    padding: 14px 34px;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 4px;
    color: #fff;
    background: #ff4655;
    border: 2px solid #ff4655;
    border-radius: 0 6px 6px 0;
    cursor: pointer;
    transition: background .2s, box-shadow .2s;
}
.search-box button:hover {
    background: #e03a48;
    box-shadow: 0 0 18px rgba(255, 70, 85, .4);
}

/* 结果统计提示行 */
.info-bar { text-align: center; color: #768079; font-size: 14px; }
.info-bar strong { color: #ff4655; }
.info-bar .clear { color: #ff4655; text-decoration: none; margin-left: 10px; }
.info-bar .clear:hover { text-decoration: underline; }

/* ===================== 卡片网格 ===================== */
.container { max-width: 1280px; margin: 0 auto; padding: 36px 20px 60px; }

/* 响应式 Grid：每列最小 250px，窗口变窄时自动换行 */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 24px;
}

/* 卡片整体是一个 <a> 链接，点击跳转选手详情页 */
a.card {
    display: block;
    text-decoration: none;   /* 去掉链接下划线 */
    color: inherit;          /* 继承文字颜色，防止链接默认变色 */
    background: linear-gradient(180deg, #1b2733 0%, #141f29 100%);
    border: 1px solid #2b3b4a;
    border-radius: 10px;
    overflow: hidden;
    transition: transform .25s, border-color .25s, box-shadow .25s;
}
a.card:hover {
    transform: translateY(-6px);                 /* 悬停上浮 */
    border-color: #ff4655;
    box-shadow: 0 10px 28px rgba(255, 70, 85, .18);
}

.card-img {
    position: relative;
    height: 190px;
    background: #0b1117;
    overflow: hidden;
}
.card-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;      /* 照片按比例裁剪铺满，不变形 */
    display: block;
    transition: transform .35s;
}
.card:hover .card-img img { transform: scale(1.06); }   /* 悬停照片轻微放大 */

/* 战队缩写角标（贴在照片右上角） */
.team-tag {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 12px;
    border-radius: 4px;
    background: #ff4655;
    color: #fff;
    font-weight: 800;
    letter-spacing: 2px;
    font-size: 14px;
}
.team-tag.none { background: #3a4756; }   /* 无战队时角标变灰色 */

.card-body { padding: 16px 16px 18px; }
.ign { font-size: 22px; font-weight: 800; letter-spacing: 1px; color: #fff; }
.real-name { color: #768079; font-size: 14px; margin-top: 4px; }
.meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.meta-item {
    font-size: 13px;
    color: #c7d1da;
    padding: 4px 12px;
    border: 1px solid #2b3b4a;
    border-radius: 999px;
}
.meta-item.role {
    color: #ff4655;
    border-color: rgba(255, 70, 85, .5);
    background: rgba(255, 70, 85, .08);
}

/* ===================== 空结果提示 ===================== */
.empty { text-align: center; padding: 80px 20px; }
.empty-code {
    font-size: 40px;
    font-weight: 900;
    letter-spacing: 6px;
    color: #ff4655;
}
.empty p { color: #768079; margin-top: 14px; font-size: 16px; }
.empty .empty-tip { color: #5a6a7a; font-size: 14px; }

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
    .logo { font-size: 30px; letter-spacing: 3px; }
    .logo .logo-sub { font-size: 17px; letter-spacing: 4px; }
    .search-box button { padding: 14px 18px; letter-spacing: 1px; }
    .grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 14px;
    }
    .card-img { height: 150px; }
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
    <h1 class="logo">VALORANT<span class="slash">//</span><span class="logo-sub">选手数据库</span></h1>
    <p class="tagline">PLAYER DATABASE · PDO 预处理查询 · JOIN 关联战队</p>
</header>

<div class="container">

    <!-- ===================== 搜索框（GET 提交到本页 index.php） ===================== -->
    <form class="search-box" method="get" action="index.php">
        <!-- 把上次搜索的关键词回填到输入框 -->
        <input type="text" name="q" value="<?php echo e($keyword); ?>"
               placeholder="搜索选手ID或战队缩写，如 ZmjjKK / EDG" autocomplete="off">
        <button type="submit">搜索</button>
    </form>

    <!-- ===================== 结果统计提示 ===================== -->
    <p class="info-bar">
        <?php if ($keyword === ''): ?>
            默认展示 <strong>30</strong> 名明星选手
        <?php else: ?>
            搜索 “<?php echo e($keyword); ?>” 共找到 <strong><?php echo $total; ?></strong> 条结果
            <?php if ($total > count($players)): ?>
                （当前只显示前 <?php echo count($players); ?> 条）
            <?php endif; ?>
            <a class="clear" href="index.php">清除搜索</a>
        <?php endif; ?>
    </p>

    <?php if (count($players) === 0): ?>
        <!-- ===================== 无结果提示 ===================== -->
        <div class="empty">
            <div class="empty-code">0 RESULT</div>
            <p>没有找到与 “<?php echo e($keyword); ?>” 相关的选手或战队</p>
            <p class="empty-tip">试试搜索选手ID（如 ZmjjKK）或战队缩写（如 EDG）</p>
        </div>
    <?php else: ?>
        <!-- ===================== 选手卡片网格 ===================== -->
        <div class="grid">
            <?php foreach ($players as $p): ?>
                <?php
                    // 照片为空时使用默认占位图
                    // （若 photo_url 存的是相对路径如 images/1.jpg，浏览器会按站点根目录加载）
                    $photo = !empty($p['photo_url']) ? e($p['photo_url']) : DEFAULT_AVATAR;
                    // 没有战队时显示 TBD
                    $team  = !empty($p['team_tag']) ? e($p['team_tag']) : 'TBD';
                ?>
                <!-- 卡片整体是链接，点击跳转选手详情页 -->
                <a class="card" href="player_detail.php?id=<?php echo (int)$p['player_id']; ?>">
                    <div class="card-img">
                        <!-- onerror：图片加载失败时也自动换成占位图 -->
                        <img src="<?php echo $photo; ?>"
                             data-fallback="<?php echo DEFAULT_AVATAR; ?>"
                             onerror="this.onerror=null;this.src=this.dataset.fallback;"
                             alt="<?php echo e($p['ign']); ?>">
                        <span class="team-tag<?php echo ($team === 'TBD') ? ' none' : ''; ?>">
                            <?php echo $team; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <h2 class="ign"><?php echo e($p['ign']); ?></h2>
                        <p class="real-name"><?php echo e(!empty($p['real_name']) ? $p['real_name'] : '真实姓名未知'); ?></p>
                        <div class="meta">
                            <span class="meta-item">国籍：<?php echo e(!empty($p['nationality']) ? $p['nationality'] : '未知'); ?></span>
                            <span class="meta-item role"><?php echo e(!empty($p['player_role']) ? $p['player_role'] : '位置未知'); ?></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

</body>
</html>
