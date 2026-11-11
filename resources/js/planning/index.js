// The Planning page's Alpine component (registered from app.js before Alpine starts; was an inline <script> in the page,
// now in the bundle so the browser caches it instead of every page load carrying ~90 KB).
// The Planning page's Alpine component (same pattern as my-department/partials/_script).
// Data: the board built by App\Services\PlanningBoard (project -> task, cabinet -> subtask with checklist items tagged by
// department, "deptStages" = one chip per department). Writes: ONE operation per user action, POSTed to planning.sync
// (App\Services\PlanningSync -> PMO's own services and rules). The page updates optimistically; the server's answer
// carries the true copy of the edited projects, so a refused edit simply bounces back with the reason in a toast.
//
// The component is put together from one module per area (./state, ./calendar, ./delivery, ...): each returns a plain
// object of state and methods, merged here with their getters kept (Object.defineProperties, not spread, which would
// read a getter once and freeze its value). Later modules win on a duplicate name, so keep names unique.
import Alpine from 'alpinejs';
import { parse } from './shared';
import state from './state';
import filters from './filters';
import calendar from './calendar';
import fat from './fat';
import delivery from './delivery';
import leftPanel from './left-panel';
import dashboard from './dashboard';
import sync from './sync';
import project from './project';
import assign from './assign';
import feed from './feed';
import checklist from './checklist';
import dayNotes from './day-notes';

const PARTS = [state, filters, calendar, fat, delivery, leftPanel, dashboard, sync, project, assign, feed, checklist, dayNotes];

export default function registerPlanningBoard() {
    Alpine.data('planningBoard', (config) => {
        const board = config.root.boards[config.boardId];
        // "★" is a personal bookmark kept in THIS browser only (per user) - nothing is sent to PMO
        const starKey = 'planning.stars.' + config.userId;
        const loadStars = () => { try { const v = JSON.parse(localStorage.getItem(starKey) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
        const t0 = parse(config.today);
        const ctx = { config, board, starKey, loadStars, t0 };
        const component = {};
        for (const part of PARTS) Object.defineProperties(component, Object.getOwnPropertyDescriptors(part(ctx)));
        return component;
    });
}
