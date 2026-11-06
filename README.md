# VALORANT 电竞信息网站

基于 PHP、MySQL/MariaDB 和原生 HTML/CSS/JavaScript 的数据库课程项目，提供选手、战队、赛事、特工资料浏览，以及用户注册、登录、评分和评论功能。

## 功能

- 选手库、战队库、赛事库及详情页。
- 赛事名称、年份和级别组合筛选。
- 数据排行榜和特工资料展示。
- 注册、登录、退出；从详情页进入登录后返回原评论区。
- 对选手、战队和赛事进行 1–5 星评分、发表评论，删除自己的评论。
- 手机端页面适配。

## 技术与数据库

- PHP 8.2，使用 PDO 连接数据库和预处理查询。
- MySQL/MariaDB；数据库脚本由 MariaDB 10.4 导出。
- HTML、CSS、JavaScript，无前端构建步骤。
- SQL 包含表结构、基础数据、视图、触发器和存储过程。

基础数据用于课程演示，不代表实时赛事数据。仓库中的 `user` 和 `comment` 表没有预置账号或评论，导入后可以自行注册测试。

## 目录

```text
valorant-web/
├── index.php                         # 选手库首页
├── teams.php / team_detail.php        # 战队
├── tournaments.php                   # 赛事筛选
├── tournament_detail.php             # 赛事详情与评论
├── player_detail.php                 # 选手详情与评论
├── agents.php / agent_detail.php     # 特工
├── stats.php                         # 数据榜
├── login.php / register.php          # 登录与注册
├── auth_return.php                   # 登录返回地址校验
├── img/players/                      # 选手图片
├── Valorant数据库_期末作业.sql         # 建库、建表和基础数据
├── Valorant数据库_分析查询.sql         # 补充分析查询
├── 分析图表/                         # 分析结果图片
└── tests/                            # 已有功能检查脚本
```

## 本地运行（XAMPP）

1. 下载本仓库，或执行：

   ```bash
   git clone https://github.com/PHY21/valorant-web.git
   ```

2. 将项目放到 `C:\xampp\htdocs\valorant_web\`，确保 `index.php` 位于此目录下。
3. 启动 XAMPP 的 Apache 和 MySQL。
4. 打开 `http://localhost/phpmyadmin/`，导入根目录中的 `Valorant数据库_期末作业.sql`。脚本会创建 `valorant_db`；其中含有重建表的语句，已有同名数据库时请先备份，避免覆盖原数据。
5. 打开 `http://localhost/valorant_web/`。

默认连接参数为 `localhost`、数据库 `valorant_db`、用户名 `root`、空密码，对应本机 XAMPP 开发环境。若环境不同，需要修改各 PHP 页面的数据库连接配置；登录和注册页面使用内联 PDO 连接。不要将实际部署密码或数据库账号备份提交到仓库。

## 功能检查

在 Apache、MySQL 已启动，网站位于上述本地地址时，从项目根目录运行：

```powershell
& 'C:\xampp\php\php.exe' tests\auth_return_test.php
& 'C:\xampp\php\php.exe' tests\tournament_filter_test.php
```

这两项检查覆盖评论入口和登录返回地址，以及赛事搜索、组合筛选和空结果提示。

## 在线运行说明

GitHub 仓库用于保存和展示源码。[GitHub Pages 是静态站点服务](https://docs.github.com/en/pages/getting-started-with-github-pages/what-is-github-pages)，无法直接运行本项目的 PHP 和数据库功能。完整网站需要部署到支持 PHP 和 MySQL/MariaDB 的服务器。

本项目用于课程学习与展示；VALORANT 品牌、选手照片等第三方素材的权利归各自权利人所有。
