<?php
session_start();
/* =============================================================
   文件：stats.php
   项目：VALORANT 数据榜（期末网页作业）
   说明：按赛事查看排行榜。顶部搜索框检索赛事，点击赛事后
         用标签页切换五个榜单：ACS、击杀、助攻、K/D、Rating。
         所有榜单均按选手聚合（取个人最高值）并过滤小样本。
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

// ---------------- 3. 参数与赛事检索 ----------------
$q   = isset($_GET['q']) ? trim($_GET['q']) : '';
$tid = isset($_GET['tid']) ? trim($_GET['tid']) : '';
$tid = ctype_digit($tid) ? (int)$tid : 0;

// 赛事列表（搜索框关键词过滤）
if ($q !== '') {
    // 中文关键词映射英文（赛事名在数据库中是英文）：
    // "冠军赛"→Champions、"大师赛"→Masters、"启点赛"→Kickoff
    $variants = array($q);
    if (mb_strpos($q, '冠军') !== false) { $variants[] = 'Champions'; }
    if (mb_strpos($q, '大师') !== false) { $variants[] = 'Masters'; }
    if (mb_strpos($q, '启点') !== false) { $variants[] = 'Kickoff'; }
    $variants = array_unique($variants);

    $conds = array();
    $params = array();
    $i = 1;
    foreach ($variants as $v) {
        $conds[] = "tournament_name LIKE :kw" . $i;
        $params[':kw' . $i] = '%' . $v . '%';
        $i++;
    }
    // 纯数字关键词：直接按年份匹配
    if (ctype_digit($q)) {
        $conds[] = "tournament_year = :yr";
        $params[':yr'] = (int)$q;
    }

    $lsql = "SELECT tournament_id, tournament_name, tournament_year, tournament_level
             FROM tournament
             WHERE " . implode(' OR ', $conds) . "
             ORDER BY tournament_year DESC, tournament_id DESC";
    $lstmt = $pdo->prepare($lsql);
    foreach ($params as $key => $val) {
        $lstmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
} else {
    $lsql = "SELECT tournament_id, tournament_name, tournament_year, tournament_level
             FROM tournament
             ORDER BY tournament_year DESC, tournament_id DESC
             LIMIT 24";
    $lstmt = $pdo->prepare($lsql);
}
$lstmt->execute();
$tourList = $lstmt->fetchAll();

// ---------------- 4. 选中赛事后的五个榜单 ----------------
$tour = null;
$boards = array();   // key => 榜单数据行
if ($tid > 0) {
    $tsql = "SELECT tournament_id, tournament_name, tournament_year, tournament_level
             FROM tournament WHERE tournament_id = :id LIMIT 1";
    $tstmt = $pdo->prepare($tsql);
    $tstmt->bindValue(':id', $tid, PDO::PARAM_INT);
    $tstmt->execute();
    $tour = $tstmt->fetch();

    if ($tour) {
        // 五个榜单：聚合列 => 显示名
        $metrics = array(
            'acs'     => 'ACS',
            'kills'   => '击杀',
            'assists' => '助攻',
            'kd'      => 'K/D',
            'rating'  => 'Rating'
        );
        foreach ($metrics as $col => $label) {
            $bsql = "SELECT p.player_id, p.ign, p.photo_url, t.team_tag,
                            MAX(s.$col) AS val, MAX(s.kd) AS kd_sub
                     FROM player_tournament_stats AS s
                     JOIN player AS p ON s.player_id = p.player_id
                     LEFT JOIN team AS t ON s.team_id = t.team_id   -- 显示选手参赛时的战队
                     WHERE s.tournament_id = :id
                     GROUP BY s.player_id, p.player_id, p.ign, p.photo_url, t.team_tag
                     HAVING SUM(s.matches_played) >= 3
                     ORDER BY val DESC
                     LIMIT 10";
            $bstmt = $pdo->prepare($bsql);
            $bstmt->bindValue(':id', $tid, PDO::PARAM_INT);
            $bstmt->execute();
            $boards[$col] = $bstmt->fetchAll();
        }
    }
}

// ---------------- 5. 输出转义函数（防 XSS） ----------------
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ---------------- 6. 导航高亮辅助函数 ----------------
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

// 榜单数值格式：acs/kills/assists 整数，kd/rating 两位小数
function fmtVal($col, $v) {
    $n = (float)$v;
    if ($col === 'kd' || $col === 'rating') { return number_format($n, 2); }
    return number_format($n, 0);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>数据榜 - VALORANT 选手数据库</title>
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

.container { max-width: 1100px; margin: 0 auto; padding: 32px 20px 60px; }

/* ===================== 赛事搜索框 ===================== */
.search-box {
    display: flex;
    max-width: 680px;
    margin: 0 auto 22px;
}
.search-box input {
    flex: 1;
    min-width: 0;
    padding: 13px 20px;
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
    padding: 13px 30px;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 3px;
    color: #fff;
    background: #ff4655;
    border: 2px solid #ff4655;
    border-radius: 0 6px 6px 0;
    cursor: pointer;
    transition: background .2s;
}
.search-box button:hover { background: #e03a48; }
.search-hint { text-align: center; color: #5a6a7a; font-size: 13px; margin-bottom: 20px; }
.search-hint a { color: #ff4655; text-decoration: none; }

/* ===================== 赛事列表 ===================== */
.tour-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
}
a.tour-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 16px;
    background: #16222c;
    border: 1px solid #2b3b4a;
    border-radius: 8px;
    text-decoration: none;
    transition: border-color .2s, transform .2s;
}
a.tour-item:hover { border-color: #ff4655; transform: translateX(4px); }
.tour-year {
    flex-shrink: 0;
    font-size: 22px;
    font-weight: 900;
    color: #ff4655;
    letter-spacing: 1px;
}
.tour-name { flex: 1; min-width: 0; color: #ece8e1; font-size: 14px; font-weight: 700; line-height: 1.4; }
.tier-mini {
    flex-shrink: 0;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 3px 8px;
    border-radius: 4px;
}
.tier-mini.intl { background: #ff4655; color: #fff; }
.tier-mini.league { background: #1a2530; border: 1px solid #2b3b4a; color: #c7d1da; }

/* ===================== 榜单区 ===================== */
.board-header {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}
.board-title { font-size: 24px; font-weight: 900; color: #fff; letter-spacing: 1px; }
.board-back { font-size: 13px; color: #768079; text-decoration: none; }
.board-back:hover { color: #ff4655; }

/* 标签页按钮 */
.tab-bar { display: flex; gap: 8px; flex-wrap: wrap; margin: 18px 0 20px; }
.tab-btn {
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 2px;
    padding: 9px 22px;
    border-radius: 6px;
    border: 1px solid #2b3b4a;
    background: #1a2530;
    color: #c7d1da;
    cursor: pointer;
    transition: all .2s;
}
.tab-btn:hover { color: #fff; border-color: #ff4655; }
.tab-btn.active { background: #ff4655; border-color: #ff4655; color: #fff; }

/* 榜单列表 */
.rank-list { display: flex; flex-direction: column; gap: 12px; }
.rank-panel { display: none; }
.rank-panel.active { display: block; }
.rank-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 13px 18px;
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
.rank-stat { min-width: 110px; text-align: right; }
.stat-label { display: block; font-size: 11px; color: #5a6a7a; letter-spacing: 2px; }
.stat-value { font-size: 20px; font-weight: 800; color: #ff4655; }
.stat-sub { font-size: 12px; color: #768079; margin-top: 2px; }

/* ===================== 空状态 ===================== */
.empty { text-align: center; padding: 60px 20px; color: #768079; }
.empty .empty-code { font-size: 34px; font-weight: 900; letter-spacing: 6px; color: #ff4655; }
.empty p { margin-top: 12px; font-size: 15px; }

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
    .rank-row { flex-wrap: wrap; gap: 10px; }
    .rank-num { width: 32px; font-size: 22px; }
    .rank-avatar { width: 44px; height: 44px; }
    .rank-info { min-width: 110px; }
    .rank-stat { flex: 1; min-width: 80px; }
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

<!-- ===================== 头部标题 ===================== -->
<header class="site-header">
    <h1 class="logo">VALORANT<span class="slash">//</span><span class="logo-sub">数据榜</span></h1>
    <p class="tagline">STATS CENTER · 按赛事查看选手排行榜</p>
</header>

<div class="container">

    <!-- ===================== 赛事搜索框 ===================== -->
    <form class="search-box" method="get" action="stats.php">
        <input type="text" name="q" value="<?php echo e($q); ?>"
               placeholder="搜索赛事，如 冠军赛 / 大师赛 / 启点赛 / 2026" autocomplete="off">
        <button type="submit">搜索</button>
    </form>

    <?php if (!$tour): ?>
        <!-- ===================== 赛事列表（点击进入该赛事榜单） ===================== -->
        <p class="search-hint">
            <?php if ($q !== ''): ?>
                搜索 “<?php echo e($q); ?>” 找到 <strong style="color:#ff4655;"><?php echo count($tourList); ?></strong> 个赛事，点击查看排行榜
                &nbsp;<a href="stats.php">清除搜索</a>
            <?php else: ?>
                最近 <?php echo count($tourList); ?> 个赛事，点击查看排行榜
            <?php endif; ?>
        </p>
        <?php if (count($tourList) === 0): ?>
            <div class="empty">
                <div class="empty-code">0 TOURNAMENT</div>
                <p>没有找到相关赛事，换个关键词试试</p>
            </div>
        <?php else: ?>
            <div class="tour-grid">
                <?php foreach ($tourList as $t): ?>
                    <?php $mini = ($t['tournament_level'] === '国际赛') ? 'intl' : 'league'; ?>
                    <a class="tour-item" href="stats.php?tid=<?php echo (int)$t['tournament_id']; ?>">
                        <span class="tour-year"><?php echo e($t['tournament_year']); ?></span>
                        <span class="tour-name"><?php echo e($t['tournament_name']); ?></span>
                        <span class="tier-mini <?php echo $mini; ?>"><?php echo e($t['tournament_level'] ?: '未知'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <!-- ===================== 选中赛事：五榜单 ===================== -->
        <div class="board-header">
            <span class="board-title"><?php echo e($tour['tournament_name']); ?></span>
            <a class="board-back" href="stats.php?q=<?php echo e($q); ?>">← 返回赛事列表</a>
        </div>

        <!-- 标签页按钮 -->
        <div class="tab-bar">
            <button class="tab-btn active" id="tab-btn-acs" onclick="showBoard('acs')">ACS</button>
            <button class="tab-btn" id="tab-btn-kills" onclick="showBoard('kills')">击杀</button>
            <button class="tab-btn" id="tab-btn-assists" onclick="showBoard('assists')">助攻</button>
            <button class="tab-btn" id="tab-btn-kd" onclick="showBoard('kd')">K/D</button>
            <button class="tab-btn" id="tab-btn-rating" onclick="showBoard('rating')">Rating</button>
        </div>

        <?php
            $labels = array('acs' => 'ACS', 'kills' => '击杀', 'assists' => '助攻', 'kd' => 'K/D', 'rating' => 'Rating');
            $first = true;
        ?>
        <?php foreach ($labels as $col => $label): ?>
            <div class="rank-panel<?php echo $first ? ' active' : ''; ?>" id="panel-<?php echo $col; ?>">
                <?php if (count($boards[$col]) === 0): ?>
                    <div class="empty">
                        <div class="empty-code">NO DATA</div>
                        <p>该赛事暂无<?php echo e($label); ?>榜数据</p>
                    </div>
                <?php else: ?>
                    <div class="rank-list">
                        <?php $rank = 0; ?>
                        <?php foreach ($boards[$col] as $row): $rank++; ?>
                            <?php
                                $avatar = !empty($row['photo_url']) ? e($row['photo_url']) : DEFAULT_AVATAR;
                                $team   = !empty($row['team_tag']) ? e($row['team_tag']) : 'TBD';
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
                                <div class="rank-stat">
                                    <span class="stat-label"><?php echo e($label); ?></span>
                                    <span class="stat-value"><?php echo fmtVal($col, $row['val']); ?></span>
                                    <?php if ($col !== 'kd'): ?>
                                        <span class="stat-sub">K/D <?php echo fmtVal('kd', $row['kd_sub']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php $first = false; ?>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<!-- ===================== 标签页切换脚本 ===================== -->
<script>
function showBoard(col) {
    // 隐藏所有榜单面板
    var panels = document.querySelectorAll('.rank-panel');
    for (var i = 0; i < panels.length; i++) { panels[i].classList.remove('active'); }
    // 取消所有按钮高亮
    var btns = document.querySelectorAll('.tab-btn');
    for (var j = 0; j < btns.length; j++) { btns[j].classList.remove('active'); }
    // 显示选中面板 + 高亮按钮
    document.getElementById('panel-' + col).classList.add('active');
    document.getElementById('tab-btn-' + col).classList.add('active');
}
</script>

<!-- ===================== 页脚 ===================== -->
<footer class="site-footer">© 2026 VALORANT 选手数据库 · 期末作业演示</footer>

</body>
</html>
