-- Avatar Electric PMO - fix backfill for Sub Tasks that were already done
-- Run in phpMyAdmin (SQL tab) ONLY IF you already ran
-- 2026_09_24_add_assignment_workflow_to_cabinet_subtasks.sql before this fix existed.
-- (That file has since been corrected in place - a fresh run of it already
-- includes this fix, so running this patch afterwards is unnecessary but
-- harmless, since the WHERE clause only ever matches the leftover rows.)
--
-- Symptom this fixes: a Sub Task whose work is 100% done (status = completed)
-- was backfilled to assignment_status = ASSIGNED ("รอรับงาน") instead of
-- COMPLETED, so it showed up as pending/overdue work waiting to be accepted.

UPDATE `cabinet_subtasks`
SET `assignment_status` = 'COMPLETED'
WHERE `department_id` IS NOT NULL
  AND `status` = 'completed'
  AND `assignment_status` != 'COMPLETED';
