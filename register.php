<?php
session_start();
require_once __DIR__ . '/auth_return.php';

$error = '';
$username = '';
$returnTo = validated_return_to($_POST['return_to'] ?? $_GET['return_to'] ?? null);
$loginUrl = 'login.php' . ($returnTo !== '' ? '?return_to=' . rawurlencode($returnTo) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || mb_strlen($username, 'UTF-8') > 50 || $password === '') {
        $error = '请输入用户名（不超过50字）和密码。';
    } else {
        try {
            $pdo = new PDO('mysql:host=localhost;dbname=valorant_db;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $check = $pdo->prepare('SELECT 1 FROM `user` WHERE username = :username LIMIT 1');
            $check->execute([':username' => $username]);
            if ($check->fetchColumn()) {
                $error = '这个用户名已被使用，请换一个。';
            } else {
                $stmt = $pdo->prepare('INSERT INTO `user` (username, password_hash) VALUES (:username, :password_hash)');
                $stmt->execute([
                    ':username' => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT)
                ]);
                header('Location: login.php?registered=1' . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : ''), true, 303);
                exit;
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = '这个用户名已被使用，请换一个。';
            } else {
                error_log($e->getMessage());
                $error = '注册暂时失败，请稍后重试。';
            }
        }
    }
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>注册 - VALORANT DB</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; background: #0f1923; color: #ece8e1; font-family: "Segoe UI", "Microsoft YaHei", sans-serif; }
.top-nav { background: #141f29; border-bottom: 1px solid #2b3b4a; }
.nav-inner { max-width: 1280px; margin: auto; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.brand { color: #fff; text-decoration: none; font-size: 20px; font-weight: 900; letter-spacing: 2px; }
.brand span, .nav-links a:hover { color: #ff4655; }
.nav-links { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.nav-links a { color: #c7d1da; text-decoration: none; }
.welcome { color: #c7d1da; }
.nav-links button { background: none; border: 0; color: #ff9aa3; cursor: pointer; font: inherit; }
.card { width: min(420px, calc(100% - 32px)); margin: 80px auto; padding: 28px; background: #16222c; border: 1px solid #2b3b4a; border-radius: 10px; }
h1 { margin: 0 0 24px; color: #fff; font-size: 26px; }
.field { display: block; margin: 18px 0; color: #c7d1da; }
.field input { display: block; width: 100%; margin-top: 8px; padding: 11px 12px; background: #0f1923; border: 1px solid #3a4756; border-radius: 6px; color: #fff; font: inherit; }
.field input:focus { outline: 2px solid #ff4655; }
.submit { width: 100%; padding: 12px; background: #ff4655; border: 0; border-radius: 6px; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.submit:hover { background: #e63848; }
.error { color: #ff9aa3; }
.switch { margin-bottom: 0; color: #768079; }
.switch a { color: #ff9aa3; }
</style>
</head>
<body>
<nav class="top-nav"><div class="nav-inner">
    <a class="brand" href="index.php">VALORANT<span>//</span>DB</a>
    <div class="nav-links">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="index.php">选手库</a>
            <span class="welcome">欢迎，<?php echo e($_SESSION['username']); ?></span>
            <form method="post" action="login.php"><button type="submit" name="logout" value="1">退出</button></form>
        <?php else: ?>
            <a href="index.php">游客浏览</a>
            <a href="<?php echo e($loginUrl); ?>">登录</a>
            <a href="register.php">注册</a>
        <?php endif; ?>
    </div>
</div></nav>
<main class="card">
    <h1>注册账号</h1>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo e($error); ?></p><?php endif; ?>
    <form method="post" action="register.php">
        <input type="hidden" name="return_to" value="<?php echo e($returnTo); ?>">
        <label class="field">用户名<input type="text" name="username" maxlength="50" required autocomplete="username" value="<?php echo e($username); ?>"></label>
        <label class="field">密码<input type="password" name="password" required autocomplete="new-password"></label>
        <button class="submit" type="submit">注册</button>
    </form>
    <p class="switch">已有账号？<a href="<?php echo e($loginUrl); ?>">去登录</a></p>
</main>
</body>
</html>
