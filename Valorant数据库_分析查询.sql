-- ============================================================================
--  Valorant 职业联赛数据库 —— 数据分析查询集（期末作业升级版）
--  数据库：valorant_db（MariaDB 10.4）
--  配套文件：Valorant数据库_期末作业.sql（建库 + 数据）
--  内容：① 数据质量检查与修复   ② 12 条业务分析查询
--        ③ 视图 / 存储过程 / 触发器（课程加分项）  ④ 报告大纲
--  说明：原查询于 2026-10-19 在本机 valorant_db 验证；2026-10-25 修订后，
--        新增或调整的语句仍需在当前数据库重新执行确认
-- ============================================================================

-- ============================================================================
-- 第一部分：数据质量检查与修复
-- ============================================================================

-- 1.1 重复数据检查：同一选手在同一赛事应只有一条统计记录
--     结果：0 条重复（数据合格）
SELECT player_id, tournament_id, COUNT(*) AS 重复条数
FROM player_tournament_stats
GROUP BY player_id, tournament_id
HAVING COUNT(*) > 1;

-- 1.2 缺失值盘点：以下字段存在缺失，逐一修复
-- ① 选手定位缺失（2 条）
SELECT p.player_id, p.ign, t.team_name
FROM player p JOIN team t ON p.team_id = t.team_id
WHERE p.player_role IS NULL;
-- ② 出生日期缺失（10 条）
SELECT player_id, ign FROM player WHERE birth_date IS NULL;
-- ③ 选手照片链接缺失（原 20 条，已于 2026-10-25 从公开渠道补全 12 条；
--    剩余 8 名 2026 新秀全网无公开定妆照，暂用占位图，属客观缺失）
SELECT player_id, ign FROM player WHERE photo_url IS NULL OR photo_url = '';
-- ④ 2026 年国际赛奖金池缺失检查（当前主数据已补齐，执行后应返回 0 行）
SELECT tournament_id, tournament_name FROM tournament
WHERE prize_pool IS NULL AND tournament_level = '国际赛';

-- 1.3 修复：补全选手定位（已于 2026-10-25 执行，结果如下）
-- 'nothing'（WOLVES，中国）实际担任先锋位；'WoohyuN'（NONGSHIM REDFORCE，韩国）为决斗者
UPDATE player SET player_role = '先锋'  WHERE player_id = 67;   -- nothing
UPDATE player SET player_role = '决斗者' WHERE player_id = 228; -- WoohyuN

-- 1.4 修复：补 2026 国际赛奖金池（已于 2026-10-25 执行）
-- 注意：官方公布值为大师赛各 100 万美元（非 50 万），冠军赛 225 万美元
-- 来源：Esports Charts（大师赛）、维基百科（冠军赛）
UPDATE tournament SET prize_pool = 1000000 WHERE tournament_id = 16; -- VCT 2026 圣地亚哥大师赛
UPDATE tournament SET prize_pool = 1000000 WHERE tournament_id = 17; -- VCT 2026 伦敦大师赛
UPDATE tournament SET prize_pool = 2250000 WHERE tournament_id = 18; -- VALORANT Champions 2026（上海）

-- 1.5 修复：冠军赛决赛结果（已于 2026-10-25 按官方结果更新）
-- 最终结果：LOUD 冠军 / Team Vitality 亚军 / 100 Thieves 季军 / G2 殿军
-- 检查语句（执行后应返回 0 行）：
SELECT t.team_name, tt.placement, tt.wins, tt.losses
FROM team_tournament tt JOIN team t ON tt.team_id = t.team_id
WHERE tt.placement = '决赛进行中';
-- 已执行的更新（记录备查）：
-- UPDATE team_tournament SET placement = '1st' WHERE id = 280; -- LOUD
-- UPDATE team_tournament SET placement = '2nd' WHERE id = 440; -- VIT
-- UPDATE team_tournament SET placement = '3rd' WHERE id = 281; -- 100T

-- 1.6 设计小建议（可不改表，写进设计文档即可体现思考）：
--     a) team_tournament.placement 存的是字符串（'5th-8th' 等），排序/比较名次很别扭，
--        更优设计：加一列 placement_num TINYINT 存名次区间下限；
--     b) 若想计算“特工胜率”，需要一张 match（单场对局）表：match_id、比赛双方、胜负、
--        每名选手当场所用特工 —— 现有表只能算出场表现，算不了胜率；
--     c) prize_pool 建议用 INT（美元整数）并加 COMMENT 说明货币单位。

-- ============================================================================
-- 第二部分：业务分析查询（12 条）
-- ============================================================================

-- Q1【多表连接 + 聚合 + HAVING + 排序】
-- 现役选手生涯综合表现 TOP20：按平均 rating 排名（要求总场次≥15，避免小样本）
SELECT p.ign                            AS 选手ID,
       p.player_role                    AS 定位,
       t.team_tag                       AS 现战队,
       r.region_name                    AS 赛区,
       COUNT(s.stat_id)                 AS 参赛赛事数,
       SUM(s.matches_played)            AS 总场次,
       SUM(s.kills)                     AS 总击杀,
       ROUND(AVG(s.acs), 1)             AS 场均ACS,
       ROUND(AVG(s.kd), 2)              AS 平均KD,
       ROUND(AVG(s.rating), 2)          AS 平均Rating
FROM player p
JOIN player_tournament_stats s ON p.player_id = s.player_id
JOIN team t                    ON p.team_id  = t.team_id
JOIN region r                  ON t.region_id = r.region_id
WHERE p.status = '现役'
GROUP BY p.player_id, p.player_role, t.team_tag, r.region_name
HAVING SUM(s.matches_played) >= 15
ORDER BY 平均Rating DESC
LIMIT 20;

-- Q2【窗口函数 ROW_NUMBER + PARTITION BY】
-- 每场国际赛事的 rating 前三名（分区排名）
SELECT * FROM (
    SELECT tt.tournament_name,
           p.ign,
           s.rating,
           ROW_NUMBER() OVER (PARTITION BY s.tournament_id ORDER BY s.rating DESC) AS 赛事排名
    FROM player_tournament_stats s
    JOIN player p     ON s.player_id = p.player_id
    JOIN tournament tt ON s.tournament_id = tt.tournament_id
    WHERE tt.tournament_level = '国际赛'
) t
WHERE 赛事排名 <= 3
ORDER BY tournament_name, 赛事排名;

-- Q3【分组对比】
-- 国际赛与联赛的选手表现差异（国际赛样本 = 各赛区顶尖选手，数据应更接近）
SELECT tt.tournament_level          AS 赛事级别,
       COUNT(DISTINCT s.player_id)  AS 参赛选手数,
       ROUND(AVG(s.rating), 2)      AS 平均Rating,
       ROUND(AVG(s.kd), 2)          AS 平均KD,
       ROUND(AVG(s.acs), 1)         AS 平均ACS
FROM player_tournament_stats s
JOIN tournament tt ON s.tournament_id = tt.tournament_id
GROUP BY tt.tournament_level;

-- Q4【维度下钻】
-- 各特工在职业赛场的表现排行（出场人次 + 场均数据）
SELECT a.agent_name        AS 特工,
       a.agent_role        AS 定位,
       COUNT(*)            AS 出场人次,
       ROUND(AVG(s.acs), 1)   AS 平均ACS,
       ROUND(AVG(s.rating), 2) AS 平均Rating,
       ROUND(AVG(s.kd), 2)  AS 平均KD
FROM player_tournament_stats s
JOIN agent a ON s.agent_id = a.agent_id
GROUP BY a.agent_id, a.agent_name, a.agent_role
ORDER BY 平均Rating DESC;

-- Q5【时间趋势】
-- 国际赛年度奖金池趋势（2021-2026，2026 年数据已补录）
SELECT tournament_year          AS 年份,
       COUNT(*)                 AS 国际赛事数,
       ROUND(SUM(prize_pool),0) AS 总奖金池美元
FROM tournament
WHERE tournament_level = '国际赛'
GROUP BY tournament_year
ORDER BY tournament_year;

-- Q6【CASE WHEN 名次映射】
-- 战队国际赛成绩榜：冠军次数、前三次数（把 '3rd-4th' 等字符串映射为可比较的“前三”）
SELECT t.team_tag                                   AS 战队,
       r.region_name                                AS 赛区,
       COUNT(*)                                     AS 国际赛参赛次数,
       SUM(CASE WHEN tt.placement = '1st' THEN 1 ELSE 0 END) AS 冠军次数,
       SUM(CASE WHEN tt.placement IN ('1st','2nd','3rd','3rd-4th')
                THEN 1 ELSE 0 END)                  AS 前三次数
FROM team_tournament tt
JOIN team t       ON tt.team_id = t.team_id
JOIN region r     ON t.region_id = r.region_id
JOIN tournament tr ON tt.tournament_id = tr.tournament_id
WHERE tr.tournament_level = '国际赛'
  AND tt.placement <> '决赛进行中'
GROUP BY t.team_id, t.team_tag, r.region_name
ORDER BY 冠军次数 DESC, 前三次数 DESC, 国际赛参赛次数 DESC;

-- Q7【日期函数 + CASE 分桶】
-- 现役选手年龄结构（截至 2026 年初，空出生日期单独归为“未知”）
SELECT CASE
         WHEN birth_date IS NULL THEN '未知'
         WHEN TIMESTAMPDIFF(YEAR, birth_date, '2026-01-01') < 18  THEN '未满18岁'
         WHEN TIMESTAMPDIFF(YEAR, birth_date, '2026-01-01') <= 20 THEN '18-20岁'
         WHEN TIMESTAMPDIFF(YEAR, birth_date, '2026-01-01') <= 23 THEN '21-23岁'
         ELSE '24岁及以上'
       END AS 年龄段,
       COUNT(*) AS 人数
FROM player
WHERE status = '现役'
GROUP BY 年龄段
ORDER BY MIN(TIMESTAMPDIFF(YEAR, birth_date, '2026-01-01'));

-- Q8【LEFT JOIN 防丢数据 + 派生表去重】
-- 四大赛区规模对比：战队数、选手数、平均成立年份
-- 注意：平均成立年份先用派生表按战队聚合（每队只计一次），
--       避免 JOIN 选手表后选手多的战队被重复加权
SELECT r.region_name                  AS 赛区,
       COUNT(DISTINCT t.team_id)      AS 战队数,
       COUNT(DISTINCT p.player_id)    AS 选手数,
       r2.平均成立年份
FROM region r
LEFT JOIN team t   ON r.region_id = t.region_id
LEFT JOIN player p ON t.team_id   = p.team_id
LEFT JOIN (
    SELECT region_id, ROUND(AVG(founded_year)) AS 平均成立年份
    FROM team
    GROUP BY region_id
) r2 ON r.region_id = r2.region_id
GROUP BY r.region_id, r.region_name, r2.平均成立年份
ORDER BY 战队数 DESC;

-- Q9【关联表使用】
-- 现役职业选手的特工池：使用人数、平均熟练度、签名特工人数
SELECT a.agent_name             AS 特工,
       a.agent_role             AS 定位,
       COUNT(*)                 AS 现役使用人数,
       ROUND(AVG(pa.proficiency), 1) AS 平均熟练度,
       SUM(pa.is_signature)     AS 签名特工人数
FROM player_agent pa
JOIN agent a  ON pa.agent_id  = a.agent_id
JOIN player p ON pa.player_id = p.player_id
WHERE p.status = '现役'
GROUP BY a.agent_id, a.agent_name, a.agent_role
ORDER BY 现役使用人数 DESC;

-- Q10【子查询 + 外援主题】
-- 外援分析：韩国选手在非太平洋赛区效力（电竞“韩援”现象）
SELECT p.ign          AS 选手ID,
       t.team_name    AS 战队,
       r.region_name  AS 所在赛区
FROM player p
JOIN team t   ON p.team_id = t.team_id
JOIN region r ON t.region_id = r.region_id
WHERE p.nationality = '韩国'
  AND r.region_name IN ('美洲赛区','EMEA赛区','中国赛区')
ORDER BY r.region_name, t.team_name;

-- Q11【历史榜单】
-- 历年各联赛冠军（placement = '1st'）
SELECT tr.tournament_year  AS 年份,
       tr.tournament_name  AS 赛事,
       t.team_tag          AS 冠军战队,
       t.team_name         AS 战队全名,
       r.region_name       AS 赛区
FROM team_tournament tt
JOIN tournament tr ON tt.tournament_id = tr.tournament_id
JOIN team t        ON tt.team_id = t.team_id
JOIN region r      ON t.region_id = r.region_id
WHERE tt.placement = '1st'
  AND tr.tournament_level = '联赛'
ORDER BY tr.tournament_year, tr.tournament_name;

-- Q12【决赛专题】
-- 2026 上海冠军赛最终名次与胜负数据
SELECT t.team_name   AS 战队,
       t.team_tag    AS 标签,
       r.region_name AS 赛区,
       tt.wins       AS 胜场,
       tt.losses     AS 负场,
       tt.placement  AS 名次
FROM team_tournament tt
JOIN team t   ON tt.team_id = t.team_id
JOIN region r ON t.region_id = r.region_id
WHERE tt.tournament_id = 18
ORDER BY tt.wins DESC;

-- ============================================================================
-- 第三部分：视图 / 存储过程 / 触发器（课程加分项）
-- 注：存储过程和触发器需在支持 DELIMITER 的客户端（HeidiSQL/Navicat/
--     MySQL 命令行）中执行；phpMyAdmin 可直接粘贴过程体。
-- ============================================================================

-- V1 视图：选手生涯汇总（供报告/二次分析直接查询）
CREATE OR REPLACE VIEW v_player_career AS
SELECT p.ign                          AS 选手ID,
       p.player_role                  AS 定位,
       p.nationality                  AS 国籍,
       t.team_tag                     AS 现战队,
       r.region_name                  AS 赛区,
       COUNT(s.stat_id)               AS 参赛赛事数,
       SUM(s.matches_played)          AS 总场次,
       SUM(s.kills)                   AS 总击杀,
       SUM(s.deaths)                  AS 总死亡,
       SUM(s.assists)                 AS 总助攻,
       ROUND(AVG(s.acs),1)            AS 场均ACS,
       ROUND(AVG(s.kd),2)             AS 平均KD,
       ROUND(AVG(s.rating),2)         AS 平均Rating
FROM player p
JOIN player_tournament_stats s ON p.player_id = s.player_id
JOIN team t   ON p.team_id   = t.team_id
JOIN region r ON t.region_id = r.region_id
GROUP BY p.player_id, p.player_role, p.nationality, t.team_tag, r.region_name;

-- V2 视图：赛区-战队规模汇总
CREATE OR REPLACE VIEW v_region_teams AS
SELECT r.region_name               AS 赛区,
       COUNT(DISTINCT t.team_id)   AS 战队数,
       COUNT(DISTINCT p.player_id) AS 选手数,
       r2.平均成立年份
FROM region r
LEFT JOIN team t   ON r.region_id = t.region_id
LEFT JOIN player p ON t.team_id   = p.team_id
LEFT JOIN (
    SELECT region_id, ROUND(AVG(founded_year)) AS 平均成立年份
    FROM team
    GROUP BY region_id
) r2 ON r.region_id = r2.region_id
GROUP BY r.region_id, r.region_name, r2.平均成立年份;

-- P1 存储过程：按年份查询该年所有赛事中 rating TOP10 的选手
DROP PROCEDURE IF EXISTS sp_top_players_by_year;
DELIMITER //
CREATE PROCEDURE sp_top_players_by_year(IN p_year INT)
BEGIN
    SELECT tt.tournament_name  AS 赛事,
           p.ign               AS 选手ID,
           s.rating            AS Rating,
           s.kd                AS KD
    FROM player_tournament_stats s
    JOIN player p     ON s.player_id = p.player_id
    JOIN tournament tt ON s.tournament_id = tt.tournament_id
    WHERE tt.tournament_year = p_year
    ORDER BY s.rating DESC
    LIMIT 10;
END//
DELIMITER ;

-- 用法：CALL sp_top_players_by_year(2025);

-- T1 触发器：rating 取值范围校验（0 ~ 2），防止脏数据写入
DROP TRIGGER IF EXISTS trg_rating_check;
DELIMITER //
CREATE TRIGGER trg_rating_check
BEFORE INSERT ON player_tournament_stats
FOR EACH ROW
BEGIN
    IF NEW.rating < 0 OR NEW.rating > 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rating 必须在 0-2 之间';
    END IF;
END//
DELIMITER ;

-- T2 触发器：删除选手时写入归档日志（实现“删除留痕”）
CREATE TABLE IF NOT EXISTS player_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT,
    ign VARCHAR(50),
    deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(100) DEFAULT '选手记录已删除'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_player_delete_log;
DELIMITER //
CREATE TRIGGER trg_player_delete_log
AFTER DELETE ON player
FOR EACH ROW
BEGIN
    INSERT INTO player_log(player_id, ign) VALUES (OLD.player_id, OLD.ign);
END//
DELIMITER ;

-- ============================================================================
-- 第四部分：分析报告大纲（交作业/放简历都可用）
-- ============================================================================
-- 1. 项目背景：VALORANT 职业联赛生态（2021-2026），数据来源与采集说明
-- 2. 数据库设计：E-R 图、8 张表结构、外键与索引设计、范式说明（3NF）
-- 3. 数据分析（对应第二部分查询，每节一图一结论）：
--    3.1 选手表现榜（Q1/Q2）→ 谁是历史最强选手（aspas/primmie 等）
--    3.2 赛事生态（Q3/Q5/Q11）→ 国际赛强度更高、奖金池逐年上涨、各联赛冠军分布
--    3.3 特工与选手（Q4/Q9）→ 决斗者特工个人表现更高、Jett 出场率第一
--    3.4 赛区对比（Q8/Q6）→ 四大赛区各 12 队、FNC/SEN 国际赛成绩最好、EDG 为 CN 首冠
--    3.5 人才结构（Q7/Q10）→ 现役选手集中于 21-23 岁、韩援现象
-- 4. 数据质量与改进：缺失值修复记录、placement 字符串优化建议、match 表扩展设想
-- 5. 结论与展望：数据库如何支撑战队运营/赛事数据分析（可扩展：赔率、BP 分析等）
-- ============================================================================
