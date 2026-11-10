/* Avatar Electric PMO - repair of a half-run 2026_11_05_delivery_v2.sql (MariaDB).
   Symptom: "Field 'window_from' doesn't have a default value" when saving a การจัดส่ง round.
   Safe to run whatever line the earlier file stopped at, and safe to run twice: every step checks first.
   Run in phpMyAdmin (SQL tab or Import) on the production database. Back up the database first.
   Ends in the same table shape as migration 2026_11_05_000001_delivery_pm_date_and_vehicles. */

/* 1. the vehicles of a round (new table) */
create table if not exists `project_delivery_vehicles` (`id` bigint unsigned not null auto_increment primary key, `project_delivery_id` bigint unsigned not null, `vehicle_id` bigint unsigned null, `label` varchar(150) null, `driver_name` varchar(100) null, `crew` json null, `sequence` smallint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `project_delivery_vehicles` add index if not exists `project_delivery_vehicles_project_delivery_id_foreign`(`project_delivery_id`);
alter table `project_delivery_vehicles` add index if not exists `project_delivery_vehicles_vehicle_id_index`(`vehicle_id`);

/* 2. the new columns on a round */
alter table `project_deliveries` add column if not exists `delivery_end_date` date null after `delivery_date`;
alter table `project_deliveries` add column if not exists `vehicle_types` json null;
alter table `project_deliveries` add column if not exists `packed` json null after `cabinet_ids`;
alter table `project_deliveries` add column if not exists `billed_at` datetime null after `packed`;
alter table `project_deliveries` add column if not exists `billed_by` bigint unsigned null after `billed_at`;

/* 3. carry the old data over - each only while its old column is still there, and never twice */
set @has = (select count(*) from information_schema.columns where table_schema = database() and table_name = 'project_deliveries' and column_name = 'window_from');
set @sql = if(@has, 'update project_deliveries set delivery_end_date = window_to where delivery_date is null and delivery_end_date is null and window_to > window_from', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;
set @sql = if(@has, 'update project_deliveries set delivery_date = window_from where delivery_date is null', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @has = (select count(*) from information_schema.columns where table_schema = database() and table_name = 'project_deliveries' and column_name = 'vehicle_type');
set @sql = if(@has, 'update project_deliveries set vehicle_types = json_array(vehicle_type) where vehicle_types is null and vehicle_type is not null and vehicle_type <> ''''', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @has = (select count(*) from information_schema.columns where table_schema = database() and table_name = 'project_deliveries' and column_name in ('vehicle_id', 'driver_name', 'crew'));
set @sql = if(@has = 3, 'insert into project_delivery_vehicles (project_delivery_id, vehicle_id, driver_name, crew, sequence, created_at, updated_at) select d.id, d.vehicle_id, d.driver_name, d.crew, 1, d.updated_at, d.updated_at from project_deliveries d where (d.vehicle_id is not null or d.driver_name is not null) and not exists (select 1 from project_delivery_vehicles v where v.project_delivery_id = d.id)', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

/* 4. drop the old columns (window_from is the one that stops new rounds being saved) */
alter table `project_deliveries` drop index if exists `project_deliveries_status_window_to_index`;
alter table `project_deliveries` drop foreign key if exists `project_deliveries_vehicle_id_foreign`;
alter table `project_deliveries` drop index if exists `project_deliveries_vehicle_id_foreign`;
alter table `project_deliveries` drop column if exists `vehicle_id`;
alter table `project_deliveries` drop column if exists `window_from`;
alter table `project_deliveries` drop column if exists `window_to`;
alter table `project_deliveries` drop column if exists `vehicle_type`;
alter table `project_deliveries` drop column if exists `driver_name`;
alter table `project_deliveries` drop column if exists `crew`;
alter table `project_deliveries` add index if not exists `project_deliveries_status_delivery_date_index`(`status`, `delivery_date`);

/* 5. mark the migration done (once) */
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_05_000001_delivery_pm_date_and_vehicles', x.`b` FROM (SELECT COALESCE(MAX(`batch`), 0) + 1 AS `b` FROM `migrations`) x
    WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_11_05_000001_delivery_pm_date_and_vehicles');
