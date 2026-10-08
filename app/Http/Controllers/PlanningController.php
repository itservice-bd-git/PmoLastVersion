<?php

namespace App\Http\Controllers;

use App\Services\PlanningAssets;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /planning: the original Avatar Planning single-page app running on PMO's own data.
 *
 *  - index: a small per-user shell (resources/planning/shell.html, ~17 KB) with the user's board embedded as
 *           `window.__PMO__`; the page's CSS/JS are static, content-hashed files in public/planning-assets (browser-cached)
 *  - data:  the board again as JSON - the page polls it (with an ETag, so "nothing changed" costs a 304)
 *  - sync:  the page's edits, as a list of operations; each one is applied through PMO's own services and rules
 *           (PlanningSync). The answer carries only the projects that were edited, not the whole board.
 *
 * /planning/board is the same Planning page written natively (Blade partials + Alpine, like My Department): it reads
 * the same board (PlanningBoard) and writes through the same operations (PlanningSync, via `sync`).
 *
 * All three answer with a Server-Timing header (php total, board build, query count) so a slow host can be diagnosed
 * from the browser's Network tab instead of guessed at.
 */
class PlanningController extends Controller
{
    public function index(Request $request, PlanningBoard $board)
    {
        $user = $request->user();
        [$root, $timing] = $this->measure(fn () => $board->build($user));

        $config = [
            'profile' => $board->profile($user),
            'root' => $root,
            'homeUrl' => route('planning.board'),
            'csrf' => csrf_token(),
            'urls' => [
                'sync' => route('planning.sync'),
                'data' => route('planning.data'),
                'assign' => route('assignments.index'),
                'newProject' => route('projects.create'),
            ],
            'generatedAt' => now()->toIso8601String(),
        ];

        // The data holds user-written text (project names, comments), and it is placed inside a <script>: escape
        // < > & ' " as \uXXXX so nothing in it can ever close the tag or break out of the JSON.
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        // the generated shell if it exists (run `php artisan planning:build`), else the one-file source page
        $shell = resource_path(PlanningAssets::SHELL);
        $template = file_get_contents(is_file($shell) ? $shell : resource_path(PlanningAssets::SOURCE));
        // asset base first, the user's data LAST: nothing inside the data can then be mistaken for a placeholder
        $html = str_replace('__PLANNING_ASSETS__', rtrim(asset('planning-assets'), '/'), $template);
        $html = str_replace('/*__PMO_CONFIG__*/null', $json, $html);

        return $this->timed(response($html)->header('Content-Type', 'text/html; charset=UTF-8'), $timing);
    }

    /** The extra board asked for in ?board=, or null for the main one (an unknown id is the main board too). */
    private function boardOf(Request $request): ?\App\Models\Board
    {
        return ($id = $request->integer('board')) ? \App\Models\Board::find($id) : null;
    }

    /** The native (Blade + Alpine) Planning page: no embedded SPA, the board is handed to one Alpine component. */
    public function board(Request $request, PlanningBoard $board)
    {
        $user = $request->user();
        $current = $this->boardOf($request);
        [$root, $timing] = $this->measure(fn () => $board->build($user, null, $current?->id));
        $profile = $board->profile($user);

        $response = response()->view('planning.board', ['noDepartment' => ! $user->canSeeAllWork() && ! $user->department_id, 'config' => [
            'boardId' => PlanningBoard::BOARD_ID,
            'root' => $root,
            'today' => today()->format('Y-m-d'),
            'canEdit' => true,                              // like the Project / Cabinet screens: anyone who can see the project may edit it (dates stay inside their own department's work)
            'isPmo' => $user->canDispatchWork(),            // admin / PM only: delete, alert a department, dispatch; the server enforces it
            'myDept' => $profile['dept_id'],                // '' for PMO roles (they see every department)
            'userName' => $user->name,
            'userId' => $user->id,                          // keys the browser-local "★" list
            'boardNo' => $current?->id,                    // null = the main board
            'boards' => collect([['id' => 'main', 'name' => 'PMO', 'url' => route('planning.board')]])
                ->concat(\App\Models\Board::orderBy('sort')->orderBy('id')->get()->map(fn ($b) => ['id' => 'b'.$b->id, 'name' => $b->name, 'url' => route('planning.board', ['board' => $b->id])]))->all(),
            'mine' => $board->mineProjectIds($user),         // project ids for the "ของฉัน" button
            'people' => $this->mentionablePeople($user),     // for the @ picker (the server re-checks every tag)
            'open' => $request->integer('project') ?: null,  // a notification's link: open this project's window on load
            'urls' => [
                'sync' => route('planning.sync', array_filter(['board' => $current?->id])),
                'comment' => route('planning.comment', array_filter(['board' => $current?->id])),
                'data' => route('planning.data', array_filter(['board' => $current?->id])),
                'assign' => route('assignments.index'),
                'newProject' => route('projects.create', array_filter(['board' => $current?->id])),
                'home' => route('planning.board'),
                'export' => route('my-department.export'),
            ],
        ]]);

        return $this->timed($response, $timing);
    }

    /**
     * The people the @ picker offers: PMO roles may tag anyone active; everyone else only their own department and the
     * PMO roles (PlanningSync::mentionTargets enforces the same rule - and project access - when the comment is sent).
     */
    private function mentionablePeople($user): array
    {
        return \App\Models\User::query()->where('is_active', true)->where('id', '!=', $user->id)
            ->when(! $user->canViewOtherDepartments() && ! \App\Models\AppSetting::get('cross_mention'), fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('role', [\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_PROJECT_MANAGER])
                ->when($user->department_id, fn ($d) => $d->orWhere('department_id', $user->department_id))))
            ->with('department:id,name')->orderBy('name')->limit(300)->get(['id', 'name', 'role', 'department_id'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department?->name])->all();
    }

    public function data(Request $request, PlanningBoard $board)
    {
        [$root, $timing] = $this->measure(fn () => $board->build($request->user(), null, $this->boardOf($request)?->id));
        $json = json_encode(['root' => $root], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $etag = '"'.md5($json).'"';

        // unchanged since the page's last copy: no body (the page sends the ETag it holds; no-store stops the browser doing it itself)
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return $this->timed(response('', 304)->header('ETag', $etag), $timing);
        }

        return $this->timed(response($json)->header('Content-Type', 'application/json')->header('ETag', $etag), $timing);
    }

    public function sync(Request $request, PlanningSync $sync, PlanningBoard $board)
    {
        $data = $request->validate(['ops' => ['required', 'array', 'max:200']]);
        $user = $request->user();

        $results = $sync->apply($user, $data['ops']);

        return $this->answer($user, $board, $results, $this->touchedProjectIds($data['ops']));
    }

    /** A comment with photos / files: same rules as a plain comment (PlanningSync), the files hang on the note it wrote. */
    public function comment(Request $request, PlanningSync $sync, PlanningBoard $board, \App\Services\CommentAttachmentUploader $uploader)
    {
        $data = $request->validate([
            'taskId' => ['required', 'string'],
            'text' => ['nullable', 'string', 'max:2000'],
            'alertDept' => ['nullable', 'string'],
            'mentions' => ['nullable', 'array'],
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['file', 'max:20480', 'mimes:'.\App\Services\CommentAttachmentUploader::ALLOWED_MIMES],
        ]);
        $user = $request->user();

        $op = ['type' => 'comment', 'taskId' => $data['taskId'], 'text' => trim((string) ($data['text'] ?? '')) ?: '(แนบไฟล์)',
            'alertDept' => $data['alertDept'] ?? null, 'mentions' => $data['mentions'] ?? []];
        $results = $sync->apply($user, [$op]);

        if ($results[0]['ok'] && ($note = $sync->lastNote())) {
            foreach ($request->file('files') as $file) {
                $uploader->store($note, $file, $user->id);
            }
        }

        return $this->answer($user, $board, $results, $this->touchedProjectIds([$op]));
    }

    /**
     * Only the projects that were edited come back (a refused edit is part of that: the page needs the true copy to bounce back
     * to). `removed` = asked for but not there any more (deleted, or not visible to this user). If any operation does not say
     * which project it is about, answer with the whole board to stay correct.
     */
    private function answer($user, PlanningBoard $board, array $results, ?array $touched)
    {
        [$payload, $timing] = $this->measure(function () use ($board, $user, $touched) {
            if ($touched === null) {
                return ['root' => $board->build($user, null, $this->boardOf(request())?->id)];
            }
            $tasks = $board->tasks($user, $touched);
            $present = array_column($tasks, 'id');

            return [
                'partial' => true,
                'tasks' => $tasks,
                'removed' => array_values(array_diff(array_map(fn ($id) => 'p'.$id, $touched), $present)),
            ];
        });

        return $this->timed(response()->json(['results' => $results] + $payload), $timing);
    }

    /** @return list<int>|null project ids the operations are about; null when any operation cannot be tied to one */
    private function touchedProjectIds(array $ops): ?array
    {
        $ids = [];
        foreach ($ops as $op) {
            if (! is_array($op)) {
                continue;   // garbage: refused by PlanningSync, touches nothing
            }
            if (! isset($op['taskId']) || ! is_string($op['taskId']) || ! preg_match('/^p(\d+)$/', $op['taskId'], $m)) {
                return null;
            }
            $ids[(int) $m[1]] = true;
        }

        return array_keys($ids);
    }

    /** @return array{0: mixed, 1: array{build: float, queries: int}} */
    private function measure(callable $work): array
    {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $t = microtime(true);
        $result = $work();
        $ms = (microtime(true) - $t) * 1000;

        return [$result, ['build' => $ms, 'queries' => $queries]];
    }

    private function timed($response, array $timing)
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $response->headers->set('Server-Timing', sprintf('app;dur=%.0f, build;dur=%.0f, db;desc="%d queries"', (microtime(true) - $start) * 1000, $timing['build'], $timing['queries']));

        return $response;
    }
}
