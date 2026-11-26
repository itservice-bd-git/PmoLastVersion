/* Avatar Electric PMO - ตรวจก่อนปรับฐานข้อมูล (ตารางใหม่ + Foreign Key) · อ่านอย่างเดียว ไม่แก้ข้อมูล */
/* รันใน phpMyAdmin แท็บ SQL ก่อน 2026_11_17_01_relationships.sql · ทุกแถวต้องเป็น OK ถ้ามี STOP ให้หยุดและส่งผลมาให้ดู */

SELECT 'MariaDB / MySQL version (ต้อง 10.2 ขึ้นไป)' AS `ตรวจ`, VERSION() AS `ค่า`,
       IF(VERSION() LIKE '5.%' OR VERSION() LIKE '10.0%' OR VERSION() LIKE '10.1%', 'STOP', 'OK') AS `ผล`
UNION ALL
SELECT 'ยังไม่เคยรันชุดนี้ (ตาราง customers ต้องยังไม่มี)', COUNT(*), IF(COUNT(*) = 0, 'OK', 'STOP')
  FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('customers', 'delivery_cabinet', 'fat_cabinet', 'checklist_status_department')
UNION ALL
SELECT 'ตารางที่จะแปลงจาก MyISAM เป็น InnoDB', COUNT(*), 'OK'
  FROM information_schema.tables WHERE table_schema = DATABASE() AND engine = 'MyISAM'
UNION ALL
SELECT 'ไฟล์แนบคอมเมนต์ที่ไม่มีคอมเมนต์แล้ว (ต้อง 0)', COUNT(*), IF(COUNT(*) = 0, 'OK', 'STOP')
  FROM comment_attachments x WHERE NOT EXISTS (SELECT 1 FROM activity_logs a WHERE a.id = x.activity_log_id)
UNION ALL
SELECT 'ไฟล์แนบตู้ที่ไม่มีตู้แล้ว (ต้อง 0)', COUNT(*), IF(COUNT(*) = 0, 'OK', 'STOP')
  FROM cabinet_attachments x WHERE NOT EXISTS (SELECT 1 FROM cabinets c WHERE c.id = x.cabinet_id)
UNION ALL
SELECT 'ไฟล์แนบโครงการที่ไม่มีโครงการแล้ว (ต้อง 0)', COUNT(*), IF(COUNT(*) = 0, 'OK', 'STOP')
  FROM project_attachments x WHERE NOT EXISTS (SELECT 1 FROM projects p WHERE p.id = x.project_id)
UNION ALL
SELECT 'ประวัติที่ชี้โครงการที่ลบถาวรแล้ว (จะตั้งเป็นว่าง ตัวประวัติยังอยู่)', COUNT(*), 'OK'
  FROM activity_logs a WHERE a.project_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM projects p WHERE p.id = a.project_id)
UNION ALL
SELECT 'ประวัติที่ชี้ผู้ใช้ที่ลบแล้ว (จะตั้งเป็นว่าง)', COUNT(*), 'OK'
  FROM activity_logs a WHERE a.user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = a.user_id)
UNION ALL
SELECT 'รอบส่งที่มีตู้เกิน 99 ตู้ (ต้อง 0)', COUNT(*), IF(COUNT(*) = 0, 'OK', 'STOP')
  FROM project_deliveries WHERE JSON_LENGTH(cabinet_ids) > 99
UNION ALL
SELECT 'รอบ FAT ที่มีตู้เกิน 99 ตู้ (ต้อง 0)', COUNT(*), IF(COUNT(*) = 0, 'OK', 'STOP')
  FROM project_fats WHERE JSON_LENGTH(cabinet_ids) > 99
UNION ALL
SELECT 'ตู้ในรอบส่ง (JSON) ทั้งหมด', COALESCE(SUM(JSON_LENGTH(cabinet_ids)), 0), 'OK' FROM project_deliveries
UNION ALL
SELECT 'ตู้ที่ห่อแล้ว (JSON) ทั้งหมด', COALESCE(SUM(JSON_LENGTH(packed)), 0), 'OK' FROM project_deliveries
UNION ALL
SELECT 'ตู้ในรอบ FAT (JSON) ทั้งหมด', COALESCE(SUM(JSON_LENGTH(cabinet_ids)), 0), 'OK' FROM project_fats
UNION ALL
SELECT 'ชื่อลูกค้าที่ไม่ซ้ำกัน (จะได้ลูกค้ากี่ราย)', COUNT(DISTINCT customer_name), 'OK' FROM projects WHERE customer_name <> '';
