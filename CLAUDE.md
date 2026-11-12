# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Avatar Electric PMO: project and production tracking for electrical cabinets. Laravel 12, PHP 8.2, Blade + Tailwind (Vite) + Alpine.js, server-rendered. UI text and activity-log messages are in Thai; keep user-facing strings Thai.

## Commands

```bash
composer setup                          # install, .env, key, migrate, npm build
composer dev                            # serve + queue + pail + vite together
php artisan migrate --seed              # DemoProjectSeeder gives PJ-690001 with 4 cabinets
npm run dev | npm run build             # Vite

php artisan test                        # whole suite (composer test also clears config first)
php artisan test --filter=TrashAndLockTest
php artisan test tests/Feature/PlanningSyncTest.php

node tests/browser/smoke.mjs           # a browser walk through every main page + Planning, read-only (see the file: users.php up, serve, run, down)
BASE=http://127.0.0.1:8001 node tests/browser/e2e.mjs   # the save paths (project, cabinet, checklist, trash, a round from PM to ตรวจแล้ว via the driver link) - WRITES: only on a copy of the database (pmo_e2e), see the file
php artisan pmo:send-reminders          # due-soon/overdue notices; scheduled daily 08:00 in routes/console.php
php artisan pmo:migration-sql --since=<last migration prod has> --output=database/sql/<name>.sql
```

The PHP tests do not run the page's JavaScript: after a refactor of views or `resources/js`, also run `tests/browser/smoke.mjs` (Playwright). It opens pages and windows, saves nothing, and fails on any JS error.

Tests run on in-memory **sqlite** (phpunit.xml) while the app runs on MySQL, so migrations and raw SQL must work on both (e.g. no `UPDATE ... JOIN`). Run the full suite after refactors: it is the main safety net.

## Production constraints

Production has **no shell, no `npm`, and no `artisan`**: schema changes go through phpMyAdmin and front-end assets must be built locally and uploaded. Consequences:
- Never write hand-made `.sql` for schema changes; generate it from the migrations with `pmo:migration-sql` and run it **before** uploading the matching code (and back up first).
- `public/build/` is what prod serves for CSS/JS. It is gitignored, so build it locally and hand it over separately.
- `DisableResponseCaching` middleware sends no-store on every web response because shared-hosting caches (LiteSpeed) otherwise show stale data. Don't remove it.
- Reminders need a `* * * * * php artisan schedule:run` cron entry.

## Domain model

`Project → Cabinet → CabinetTask → CabinetSubtask → CabinetChecklist`. Cabinets are created by copying a `CabinetTemplate` tree (`CabinetTemplateService`, `CabinetCopyService`); editing a cabinet never touches the template.

- **Progress** (`ProgressService`) is persisted bottom-up on every checklist toggle: checklist % → subtask → task → cabinet (plain averages; `weight` columns exist but are unused). Project `task_progress` and `production_progress` are computed and always shown separately. Derived status is `not_started/in_progress/completed`; `on_hold` is preserved.
- **Assignment workflow** (`SubtaskAssignmentService`): a subtask is dispatched to a department and moves `UNASSIGNED → ASSIGNED → ACCEPTED → IN_PROGRESS → COMPLETED`. The department is locked once accepted. While `ASSIGNED`, the department may refuse it (`reject()`, "ไม่รับงาน" with a reason): it goes back to `UNASSIGNED` with `rejected_*` set, whoever dispatched it is notified, and the next dispatch clears them. Every transition re-reads the row under `lockForUpdate`, checks the actor, writes the activity log and notifies. Only `canDispatchWork()` roles (admin, project_manager) may dispatch; roles are on `User` (`admin`, `project_manager`, `sales`, `production`, `member`).
- **Visibility**: PMO roles see all departments; others only their own department's subtasks. `WorkScope`, `DepartmentSchedule::resolveDepartment` and `PlanningBoard` implement this and must stay in agreement. Enforce on the server, not just in the UI. A user may also look after extra departments (`department_user`, Settings > ผู้ใช้งาน) and works for them as a member: ask "is this my department's work" with `User::departmentIds()` / `inDepartment()` and find a department's people with `User::ofDepartment()`, never `department_id` alone. Permissions (`Access`) still come from the user's own department.
- **Checklist pairs** (`SubtaskPairs`, set on the cabinet template and per cabinet): a Sub Task comparing with another shares its list (`SharedChecklist`). The receiver holds a copy of each source item (`cabinet_checklists.source_checklist_id`); add / rename / order / delete happen at the source and reach the copies, a copy ticked by the receiver blocks deleting it at the source, and each side ticks its own copy, except that a receiver ticking its copy ticks the source item too (and unticking takes back only that tick); the source department is notified. Any new code path that adds, renames, moves or deletes cabinet checklist items must go through `SharedChecklist`.
- **Checklist notice rules** (`ChecklistNoticeRules`, Settings > แจ้งเตือน Checklist): per department and event (date / tick / cabinet / before N / late N) who hears what about its paired checklist. A department with a rule for an event replaces the built-in `HandoffService` notice of that event; one with none keeps it.
- **Delivery** (`ProjectDelivery`, `DeliveryFlow`): PM sends a round to the delivery department, which moves it through these steps:
  1. จัดรถ / คน
  2. ห่อ (packing)
  3. แจ้งเปิดบิล
  4. ออกจากโรงงาน
  5. ส่งถึงแล้ว

  It may also refuse a round (ไม่รับงาน, with a reason).
  - A driver can report the arrival with photos through a signed link that needs no login (`DriverLinkController`). That round waits as รอตรวจ (`review_at`) until the department presses ตรวจแล้ว, and only then does PM hear about it.
  - `DeliveryBrief` writes the LINE message for the crew.
  - The งานจัดส่ง page is `DeliveryController`.
- **Automation** (`AutomationService`): admin-defined rules run after a checklist tick and call the normal `start()/complete()` service methods, so all workflow checks still apply; they only move work forward. Date-limit rules constrain dates.

## Cross-cutting model behaviour (traits in `app/Models/Concerns`)

- `LogsActivity`: a model opts in and overrides `activityProjectId()`, `activityLabel()`, `activityLoggableFields()`. Only listed fields are diffed; progress/status are deliberately excluded to avoid roll-up noise. Suppress the auto message with `Model::withoutEvents(...)`. `ActivityTreeBuilder` and `ActivityLogController` render the log; project comments are `activity_logs` rows with action `note` (the old `remark` columns were migrated into them).
- `GuardsClosedProject` + `ProjectLock`: a project with status `completed`/`cancelled` is read-only, enforced at model events (create/update/delete) via `ProjectLockedException` (HTTP 423 / flash error). Code that uses `saveQuietly()` or mass updates bypasses model events and must call `ProjectLock::assertOpen()` itself. Comments and reopening stay allowed.
- `CascadesSoftDeletes`: deleting a project/cabinet/task/subtask soft-deletes the subtree with the **same** `deleted_at` via mass updates (no per-row log spam); restore brings back only rows carrying that timestamp. `project_no` / `mo_no` are non-unique indexes so deleted numbers can be reused.
- Notifications: `NotificationService` + `PmoNotice` (database channel); per-user preferences in `NotificationPreference`, global settings in `AppSetting`.
  - `NotificationService` is the only entry point. It keeps who hears what (the people helpers), `batch()`, and `send()`, through which every notice passes (preferences, collapsing, de-duplication, web push).
  - The kinds of notice live in its traits in `app/Services/Concerns`: `WorkNotices`, `ProjectNotices`, `NoticeReceipts` (seen / รับทราบ), `DeliveryNotices`, `Reminders`.
  - Add a new notice to the trait of its kind. Never call `send()` from outside.

## Planning page

`/planning/board` is the main working page (`/my-department` and `/planning` redirect to it). Server-rendered Blade plus one Alpine component:

- **View:** `resources/views/planning/board.blade.php` and `partials/`:
  - `_toolbar`, `_left-panel` ("งานที่ต้องเสร็จ"), `_month`, `_timeline`, `_dashboard`, `_events` (the FAT / จัดส่ง tabs)
  - `_task-modal` (the project window), `_checklist-popover`, `_overlays`
- **Behaviour:** the `planningBoard` component, put together in `resources/js/planning/index.js` from one module per area:

  | Module | Area |
  |---|---|
  | `state` | data and view state |
  | `filters` | lookups, filtering |
  | `calendar` | เดือน, ไทม์ไลน์ |
  | `fat` | FAT |
  | `delivery` | rounds, FAT/จัดส่ง tabs, the รอบส่ง drawer |
  | `left-panel` | the left panel |
  | `dashboard` | the dashboard |
  | `sync` | the save queue |
  | `project` | the project window |
  | `assign` | handing work out |
  | `feed` | remarks, comments, work waiting to be accepted |
  | `checklist` | checklists |
  | `day-notes` | หมายเหตุรายวัน |

  Constants and date helpers are in `shared.js`.
- **Module rules:**
  - Each module returns a plain object. In a method, `this` is the whole component, so a module calls another module's methods freely.
  - `index.js` merges the modules with `Object.defineProperties`, which keeps getters (`cur`, `clTask`, `clCab`). Never merge with spread, and keep member names unique: on a duplicate, the later module wins.
  - Put new code in the module of its area. Add a module, and add it to `PARTS`, when a new area outgrows its neighbour.
- **Data:** `PlanningBoard` builds the JSON (`planning.data`). The shape of one project (task, cabinets, FAT, rounds, comments) is in `Concerns\BoardProjectShape`.
  - Every edit is one operation through `planning.sync` (`PlanningSync`). It is applied by the same services and rules as the rest of the app, and refused operations come back with a Thai reason.
  - The page never writes data itself.
  - `PlanningSync` keeps `apply()`/`applyOne()` (the dispatch, one transaction per operation) and the shared helpers. The operations live in its traits in `app/Services/Concerns`: `SyncProjects`, `SyncStages`, `SyncCabinets`, `SyncChecklist`, `SyncComments`. A new operation goes in the trait of its area, plus a `match` arm in `applyOne()`.
- **Delivery rounds** are drawn differently for the delivery department than for everyone else:
  - **The delivery department** (`shipUrl` set, `calendar.js` `roundCards()`): in เดือน each round is its own card on its day(s) (`monthEntries`). On a phone the card shows only the time/state and the SO. A งานขนส่ง project has no bar of its own. In ไทม์ไลน์ each round is a segment with its words to the right (`delivery.js` `tlDels`, `del-seg`).
  - The delivery department also gets tabs over เดือน: ทั้งหมด / รอบส่ง / งาน Sub Task (`calKind`, remembered in this browser). The top "จัดส่ง" tab stays as it is.
  - **Everyone else:** the rounds are tabs on the project's bar (`delBands`).
- **หมายเหตุรายวัน** (`day-notes.js`, `DayNoteController`, table `department_day_notes`): a department's notes for a day.
  - Many notes per day. Only the department's own people see them (`User::departmentIds()`); a user with no department has none.
  - Only the writer edits or deletes a note.
  - Shown as a badge at the right of the day in เดือน and a button in the left panel. Both open one window.
  - Loaded per month as JSON (`planning.day-notes.*`), not part of the board, so the board's ETag polling is unaffected.
- **Guards:** `AlpineHandlersTest` fails when a template calls a method no module defines. `PlanningBoardPageTest` keeps a page-size budget.

The old single-file SPA (`resources/planning/index.html`, `public/planning-assets/`, `planning:build`) was removed in 2026-11; `/planning/classic` redirects to the board.

## Where things live

| Area | Code |
|---|---|
| Controllers | `app/Http/Controllers`. Thin: they validate, call a service, answer. Settings screens are under `Settings/`. |
| Business rules | `app/Services`, one class per workflow or report. A service that grows past ~300 lines is split by area into traits in `app/Services/Concerns`; the class keeps its constructor, constants, public entry points and shared helpers, so callers never change: |
| | `NotificationService` → `*Notices`, `Reminders` · `PlanningSync` → `Sync*` · `PlanningBoard` → `BoardProjectShape`, `BoardStamp`, `BoardPalette` · `DeliveryFlow` → `DeliveryRequests` (PM), `DeliverySteps` (delivery dept) · `DailyWork` → `DailyWorkChart`, `DailyWorkFeed` · `ChecklistEditor` → `ChecklistEdits`, `ChecklistLive` · `HandoffService` → `HandoffNotices` · `ProjectDates` → `MovesLinkedDates`, `DescribesDateChanges` · `SubtaskAssignmentService` → `DispatchesWork`, `AssignmentChecks` |
| Lists | The โครงการ list and its CSV (filters, order, department dots) are `ProjectList`. |
| Permissions | The rules are methods on `User` (`canDispatchWork()`, `canManageTrash()`, `allows()`) and on the models (`CabinetSubtask::isChecklistEditableBy()` / `canTickBy()`), used by both the views and the server. Routes use the middleware aliases (`pmo`, `admin`, `perm:…`, `project.visible`) and `can:manage-trash` (a Gate in `AppServiceProvider`). `app/Policies/CabinetSubtaskPolicy` gives the checklist routes their Thai refusals (`Gate::authorize('editChecklist', $subtask)`). A refusal always says why in Thai. |
| Form Requests | `app/Http/Requests`. Used for a form with an add / edit pair (`ProjectRequest`, `Settings\UserRequest`, …), where the route's model tells edit from add; `ProjectRequest::projectData()` also drops what the person may not change. One-off checks stay inline `$request->validate()` with their Thai messages. |
| Models | `app/Models`, with behaviour traits in `app/Models/Concerns` (see above), and traits that split a large model: `PresentsDelivery` (how a round is said and shown), `ProjectMoney`, `ProjectProgress`, `SubtaskAssignmentRules`, `SubtaskDue`. Relations, casts, `booted()` and the `LogsActivity` overrides stay on the model (two traits may not define the same method). |
| Page scripts | `resources/js`, registered from `app.js` before Alpine starts. Not inline in Blade. |
| Views | `resources/views`. Reusable pieces are `components/`, e.g. `<x-round-button>`. Never build HTML in PHP strings in a view: make a component. |

`resources/js` in detail:

| File | Page |
|---|---|
| `planning/` | Planning (see above) |
| `deliveries.js` | งานจัดส่ง, `deliveriesPage` |
| `assignment.js` | handing work to departments on the cabinet / project / dispatch pages; its functions are on `window` |
| `activity-tree.js` | `x-activity-tree` |
| `work-summary.js` | reports/today |
| `template-editor.js` | cabinet-templates/show |
| `checklist-editor.js` | `x-checklist-editor` |
| `cabinet-page.js` | cabinets/show (values from `window.pmoCabinetPage`) |
| `bell.js`, `status-report.js` | the layout's bell, `x-status-report` |
| `project-info.js` | projects/show "ข้อมูลโครงการ" |
| `notice-rules.js`, `push-device.js` | Settings > แจ้งเตือน Checklist, Settings > การแจ้งเตือน |
| `embed-bridge.js` | `x-embed-layout` pages (`body[data-embed]`) inside Planning's รอบส่ง window |
| `ceo-report.js` | the CEO report's print page: its own Vite entry, since that page loads no `app.js` |

A page passes server values as `@js(...)` in its `x-data` call or as one `window.pmo…` object; the code itself is never inline. The one inline script left is the layout's sidebar state in `<head>` (it must run before the first paint).

## Conventions

- Prefer putting logic in `app/Services` over controllers. When splitting a class, check afterwards that its methods are unchanged (reflection) and that every class a trait names is imported. The new-project page's FAT / delivery go through `NewProjectAppointments`; the Planning page's CSV export is `DepartmentExportController`, at its old `my-department.export` address.
- A trait in a `Concerns` folder is in a different namespace from the class that uses it, so import every class it names. A missing import fails only when that code runs; the browser smoke's server-error check catches it.
- Project fields such as owner / sales person are plain text columns (`project_owner`, `sales_person`), not user FKs. Extra per-department project fields are `DepartmentField` / `ProjectFieldValue`. Planning boards, statuses and labels are `Board` / `BoardStatus` / `BoardLabel`.
- Exports are CSV via `App\Support\Csv`.
- Admin-only settings routes use the `admin` middleware alias (`EnsureAdmin`); the `gzip` alias compresses the planning responses.
- Removed and kept only as data:
  - The public `/request` form (2026-10): the `project_requests` table and the `ProjectRequest` model.
  - The My Department page (2026-11): its starred Sub Tasks rows. `/my-department` redirects to Planning.
