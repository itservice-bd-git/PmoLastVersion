/* Avatar Electric PMO - ตรวจหลังปรับฐานข้อมูล · อ่านอย่างเดียว · รันหลัง 2026_11_17_01_relationships.sql และหลังอัปโค้ด */
/* ทุกแถวต้องเป็น OK ถ้ามี FAIL ให้หยุดและส่งผลมาให้ดู (สำรองฐานข้อมูลไว้ก่อนรันแล้ว กู้คืนได้) */

SELECT 'ไม่มีตาราง MyISAM เหลือ' AS `ตรวจ`, COUNT(*) AS `ค่า`, IF(COUNT(*) = 0, 'OK', 'FAIL') AS `ผล`
  FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND engine <> 'InnoDB'
UNION ALL
SELECT 'จำนวน Foreign Key (ต้องเพิ่มขึ้น 17)', COUNT(*), 'OK'
  FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()
UNION ALL
SELECT 'ตู้ในรอบส่ง: แถวใหม่เท่ากับ JSON (ไม่นับตู้ที่ลบถาวรแล้ว)',
       CONCAT((SELECT COUNT(*) FROM delivery_cabinet), ' / ', (SELECT COUNT(*) FROM project_deliveries d JOIN cabinets c ON JSON_CONTAINS(d.cabinet_ids, CAST(c.id AS CHAR)))),
       IF((SELECT COUNT(*) FROM delivery_cabinet) = (SELECT COUNT(*) FROM project_deliveries d JOIN cabinets c ON JSON_CONTAINS(d.cabinet_ids, CAST(c.id AS CHAR))), 'OK', 'FAIL')
UNION ALL
SELECT 'ตู้ที่ห่อแล้ว: แถวใหม่เท่ากับ JSON',
       CONCAT((SELECT COUNT(*) FROM delivery_cabinet WHERE packed_at IS NOT NULL), ' / ', (SELECT COALESCE(SUM(JSON_LENGTH(packed)), 0) FROM project_deliveries)),
       IF((SELECT COUNT(*) FROM delivery_cabinet WHERE packed_at IS NOT NULL) = (SELECT COALESCE(SUM(JSON_LENGTH(packed)), 0) FROM project_deliveries), 'OK', 'FAIL')
UNION ALL
SELECT 'ตู้ในรอบ FAT: แถวใหม่เท่ากับ JSON',
       CONCAT((SELECT COUNT(*) FROM fat_cabinet), ' / ', (SELECT COUNT(*) FROM project_fats f JOIN cabinets c ON JSON_CONTAINS(f.cabinet_ids, CAST(c.id AS CHAR)))),
       IF((SELECT COUNT(*) FROM fat_cabinet) = (SELECT COUNT(*) FROM project_fats f JOIN cabinets c ON JSON_CONTAINS(f.cabinet_ids, CAST(c.id AS CHAR))), 'OK', 'FAIL')
UNION ALL
SELECT 'แผนกของสีสถานะ: แถวใหม่เท่ากับ JSON',
       CONCAT((SELECT COUNT(*) FROM checklist_status_department), ' / ', (SELECT COUNT(*) FROM checklist_statuses s JOIN departments d ON JSON_CONTAINS(s.department_ids, CAST(d.id AS CHAR)))),
       IF((SELECT COUNT(*) FROM checklist_status_department) = (SELECT COUNT(*) FROM checklist_statuses s JOIN departments d ON JSON_CONTAINS(s.department_ids, CAST(d.id AS CHAR))), 'OK', 'FAIL')
UNION ALL
SELECT 'รายการว่าง = ทุกตู้ / ทุกแผนก ตรงกับ JSON', COUNT(*), IF(COUNT(*) = 0, 'OK', 'FAIL') FROM (
    SELECT id FROM project_deliveries WHERE all_cabinets <> (cabinet_ids IS NULL OR cabinet_ids IN ('[]', '', 'null'))
    UNION ALL SELECT id FROM project_fats WHERE all_cabinets <> (cabinet_ids IS NULL OR cabinet_ids IN ('[]', '', 'null'))
    UNION ALL SELECT id FROM checklist_statuses WHERE all_departments <> (department_ids IS NULL OR department_ids IN ('[]', '', 'null'))
) x
UNION ALL
SELECT 'ทุกโครงการมีลูกค้า', COUNT(*), IF(COUNT(*) = 0, 'OK', 'FAIL') FROM projects WHERE customer_id IS NULL AND customer_name <> ''
UNION ALL
SELECT 'ชื่อลูกค้าตรงกับที่โครงการพิมพ์ไว้', COUNT(*), IF(COUNT(*) = 0, 'OK', 'FAIL')
  FROM projects p JOIN customers c ON c.id = p.customer_id WHERE c.name <> TRIM(p.customer_name)
UNION ALL
SELECT 'ของเดิมยังอยู่: คอลัมน์ cabinet_ids / packed / customer_name', COUNT(*), IF(COUNT(*) = 3, 'OK', 'FAIL')
  FROM information_schema.columns WHERE table_schema = DATABASE()
   AND ((table_name = 'project_deliveries' AND column_name IN ('cabinet_ids', 'packed')) OR (table_name = 'projects' AND column_name = 'customer_name'));
