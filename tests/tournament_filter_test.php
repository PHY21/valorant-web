<?php
$base = 'http://localhost/valorant_web/tournaments.php';

function page(array $params): string
{
    global $base;
    $html = file_get_contents($base . '?' . http_build_query($params));
    if ($html === false) {
        throw new RuntimeException('赛事库无法访问');
    }
    return $html;
}

function cards(string $html): array
{
    preg_match_all('/<a class="card"[^>]*>.*?<\/a>/s', $html, $matches);
    return $matches[0];
}

$filtered = cards(page(['year' => '2024', 'level' => '国际赛']));
if (count($filtered) !== 3) {
    throw new RuntimeException('2024 国际赛应有 3 项，实际 ' . count($filtered));
}
foreach ($filtered as $card) {
    if (!str_contains($card, '2024') || !str_contains($card, '国际赛')) {
        throw new RuntimeException('年份与级别组合筛选不正确');
    }
}

$searched = cards(page(['q' => 'Pacific']));
if (count($searched) === 0 || count($searched) >= 57) {
    throw new RuntimeException('赛事名称搜索没有缩小结果');
}
foreach ($searched as $card) {
    if (stripos($card, 'Pacific') === false) {
        throw new RuntimeException('名称搜索混入无关赛事');
    }
}

$combined = cards(page(['q' => 'VCT', 'year' => '2024', 'level' => '国际赛']));
if (count($combined) !== 2) {
    throw new RuntimeException('名称、年份和级别组合筛选应有 2 项，实际 ' . count($combined));
}

$empty = page(['q' => 'never-existing-tournament-12345']);
if (count(cards($empty)) !== 0 || !str_contains($empty, '未找到赛事')) {
    throw new RuntimeException('空结果提示不正确');
}

echo "PASS: 赛事库名称、年份、级别筛选和空结果\n";
