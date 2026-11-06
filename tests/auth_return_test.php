<?php
$base = 'http://localhost/valorant_web/';
$targets = [
    'player_detail.php?id=21' => 'player_detail.php?id=21#comments',
    'team_detail.php?id=104' => 'team_detail.php?id=104#comments',
    'tournament_detail.php?id=69' => 'tournament_detail.php?id=69#comments',
];

foreach ($targets as $page => $returnTo) {
    $html = file_get_contents($base . $page);
    $expected = 'href="login.php?return_to=' . rawurlencode($returnTo) . '"';
    if ($html === false || !str_contains($html, $expected)) {
        throw new RuntimeException($page . ' 的评价入口没有保留返回地址');
    }
}

require __DIR__ . '/../auth_return.php';
foreach ($targets as $returnTo) {
    if (validated_return_to($returnTo) !== $returnTo) {
        throw new RuntimeException('合法详情页被拒绝: ' . $returnTo);
    }
}

foreach (['https://evil.example/', '//evil.example/', 'index.php', '../login.php', 'player_detail.php?id=0', 'player_detail.php?id=1&x=1', "player_detail.php?id=1\r\nLocation: https://evil.example/"] as $bad) {
    if (validated_return_to($bad) !== '') {
        throw new RuntimeException('非法返回地址被接受: ' . $bad);
    }
}

$login = file_get_contents($base . 'login.php?return_to=' . rawurlencode('player_detail.php?id=21#comments'));
if ($login === false || !str_contains($login, 'name="return_to" value="player_detail.php?id=21#comments"')) {
    throw new RuntimeException('登录表单没有保留返回地址');
}

$register = file_get_contents($base . 'register.php?return_to=' . rawurlencode('player_detail.php?id=21#comments'));
if ($register === false || !str_contains($register, 'name="return_to" value="player_detail.php?id=21#comments"')) {
    throw new RuntimeException('注册表单没有保留返回地址');
}

foreach (['login.php', 'login.php?return_to=' . rawurlencode('https://evil.example/')] as $page) {
    $html = file_get_contents($base . $page);
    if ($html === false || !str_contains($html, 'name="return_to" value=""')) {
        throw new RuntimeException($page . ' 没有忽略无效或缺失的返回地址');
    }
}

echo "PASS: 评论入口、登录注册表单和返回地址校验\n";
