# Avatar Electric – Project & Production Tracking System (V1)

Laravel 12 + MySQL + Tailwind/Livewire stack (Breeze blade), matching the sibling
`PMO` / `PmoNew` apps in this htdocs folder — rebuilt from scratch with a tight
V1 scope instead of their accumulated feature set.

## Stack

- PHP 8.2, Laravel 12, MySQL/MariaDB (`avatar_pmo_v1`)
- Blade + Tailwind (Vite), Alpine.js for modals/expand-collapse
- No SPA framework — server-rendered pages, small vanilla-JS `fetch` calls for
  live checklist toggling only

## Run it

```bash
composer install
npm install && npm run build   # or `npm run dev` while working on styles/JS
cp .env.example .env           # already configured for this repo's .env
php artisan migrate --seed
php artisan serve
```

Visit `http://127.0.0.1:8000`.

**Settings → Users**.

## Demo data

`DemoProjectSeeder` creates project `PJ-690001` (MDB Factory Expansion, ABC
Company) with 4 cabinets (MO-690001..004), each seeded from the **Standard
Cabinet Template** (Equipment / Busbar / Steel / Wiring, 2 sub tasks each, 3
checklist items each) with a mix of checklist completion so every progress
tier (0/low/mid/high/100%) is visible on first login.

Re-seed anytime with `php artisan migrate:fresh --seed`.

## Architecture notes

- **Progress roll-up** (`app/Services/ProgressService.php`): Checklist →
  Sub Task → Task → Cabinet is recalculated and persisted bottom-up on every
  checklist toggle. Project-level Task Progress and Production Progress are
  computed as plain averages (`Project::task_progress` /
  `Project::production_progress`) and always shown **separately**, never
  merged into one number, per spec.
- **Templates** (`app/Services/CabinetTemplateService.php`): creating a
  cabinet copies a `CabinetTemplate`'s Task/Sub Task/Checklist tree into the
  cabinet's own real rows. Editing a cabinet's tasks never touches the
  master template.
- **Weighting is schema-ready but inactive in V1**: `cabinets.weight` and
  `cabinet_tasks.weight` exist and default to plain-average behavior; wiring
  them into the roll-up is a later-phase change, not a V1 one.
- **Deferred by design** (per spec section 22): capacity planning, resource
  allocation, delay requests, risk prediction/forecast, approvals,
  notifications, ERP/BOM/inventory integration. The schema (separate
  `project_tasks` vs. `cabinet_tasks`, template tables, department field on
  sub tasks) is meant to leave room for these without a rewrite.
# PmoLastVersion
# PmoLastVersion
