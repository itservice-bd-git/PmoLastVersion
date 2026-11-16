/* Avatar Electric PMO - ปรับสถานะ Sub Task เก่าให้ตรงกับ Checklist (รันครั้งเดียว) */
/* 1) สำรองฐานข้อมูลก่อน  2) รัน 2026_11_16_check_subtask_status.sql ดูรายการ  3) รันไฟล์นี้ใน phpMyAdmin (แท็บ SQL) */
/* งานที่เป็นเสร็จงานแล้วแต่ Checklist ไม่ครบ ไม่ถูกแตะ: จะปรับเองเมื่อมีคนติ๊ก / ยกเลิกติ๊ก Checklist ของงานนั้น */
/* ไม่บันทึกประวัติกิจกรรม และไม่ส่งแจ้งเตือน · แก้เฉพาะ assignment_status และ completed_at */
/* ใช้ comment แบบนี้เท่านั้น (ไม่ใช้ "--") เพราะถ้าบรรทัดหายตอนวาง คำสั่งถัดไปจะถูกข้ามเงียบ ๆ */

/* ติ๊กครบทุกข้อแล้ว แต่ยังไม่เป็นเสร็จงาน -> เสร็จงานแล้ว */
UPDATE `cabinet_subtasks`
SET `assignment_status` = 'COMPLETED', `completed_at` = COALESCE(`completed_at`, `updated_at`)
WHERE `deleted_at` IS NULL
  AND `assignment_status` <> 'COMPLETED'
  AND EXISTS (SELECT 1 FROM `cabinet_checklists` k WHERE k.`cabinet_subtask_id` = `cabinet_subtasks`.`id` AND k.`deleted_at` IS NULL)
  AND NOT EXISTS (SELECT 1 FROM `cabinet_checklists` k WHERE k.`cabinet_subtask_id` = `cabinet_subtasks`.`id` AND k.`deleted_at` IS NULL AND k.`is_completed` = 0);
