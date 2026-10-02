-- Avatar Electric PMO - Department assignment workflow
-- Run ONCE in phpMyAdmin (SQL tab) on the production database, BEFORE uploading the new code.
-- Safe for existing data: no rows are deleted; only new columns are added.

START TRANSACTION;

ALTER TABLE `cabinet_subtasks`
  ADD COLUMN `assignment_status` VARCHAR(20) NOT NULL DEFAULT 'UNASSIGNED' AFTER `department_id`,
  ADD COLUMN `accepted_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `assignment_status`,
  ADD COLUMN `accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `accepted_by`,
  ADD COLUMN `started_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `accepted_at`,
  ADD COLUMN `started_at` TIMESTAMP NULL DEFAULT NULL AFTER `started_by`,
  ADD COLUMN `completed_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `started_at`,
  ADD COLUMN `completed_at` TIMESTAMP NULL DEFAULT NULL AFTER `completed_by`,
  ADD INDEX `cabinet_subtasks_assignment_status_index` (`assignment_status`),
  ADD CONSTRAINT `cabinet_subtasks_accepted_by_foreign` FOREIGN KEY (`accepted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cabinet_subtasks_started_by_foreign` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cabinet_subtasks_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Existing sub tasks that already have a department = "assigned, waiting for acceptance"
-- (department can still be corrected). Sub tasks without a department stay UNASSIGNED.
-- Exception: work that's already done (status = completed) backfills straight to
-- COMPLETED so it doesn't reappear as pending work waiting to be accepted.
UPDATE `cabinet_subtasks` SET `assignment_status` = 'ASSIGNED' WHERE `department_id` IS NOT NULL AND `status` != 'completed';
UPDATE `cabinet_subtasks` SET `assignment_status` = 'COMPLETED' WHERE `department_id` IS NOT NULL AND `status` = 'completed';

-- Keep Laravel's migration history in sync (so a future `artisan migrate` won't re-run this).
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_24_000001_add_assignment_workflow_to_cabinet_subtasks_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

COMMIT;

-- ---------------------------------------------------------------------------
-- ROLLBACK (only if you need to undo; this drops the workflow data):
--
-- ALTER TABLE `cabinet_subtasks`
--   DROP FOREIGN KEY `cabinet_subtasks_accepted_by_foreign`,
--   DROP FOREIGN KEY `cabinet_subtasks_started_by_foreign`,
--   DROP FOREIGN KEY `cabinet_subtasks_completed_by_foreign`;
-- ALTER TABLE `cabinet_subtasks`
--   DROP INDEX `cabinet_subtasks_assignment_status_index`,
--   DROP COLUMN `assignment_status`, DROP COLUMN `accepted_by`, DROP COLUMN `accepted_at`,
--   DROP COLUMN `started_by`, DROP COLUMN `started_at`, DROP COLUMN `completed_by`, DROP COLUMN `completed_at`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_09_24_000001_add_assignment_workflow_to_cabinet_subtasks_table';
