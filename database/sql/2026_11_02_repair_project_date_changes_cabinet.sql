/* Avatar Electric PMO - repair for prod (2026-11-02) */
/* For a database that ran the FIRST version of 2026_10_30_project_date_changes.sql (made before cabinets could be postponed): */
/* its project_date_changes has no cabinet_id. This brings it to what 2026_10_30_000002_create_project_date_changes_tables makes now. */
/* Run once in phpMyAdmin (SQL tab or Import). Back up the database first. Do NOT run on a database that already has the column. */

alter table `project_date_changes` add `cabinet_id` bigint unsigned null after `project_id`;
alter table `project_date_changes` add constraint `project_date_changes_cabinet_id_foreign` foreign key (`cabinet_id`) references `cabinets` (`id`) on delete cascade;
alter table `project_date_changes` add index `project_date_changes_project_id_cabinet_id_kind_index`(`project_id`, `cabinet_id`, `kind`);
alter table `project_date_changes` drop index `project_date_changes_project_id_kind_index`;
