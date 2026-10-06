<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\Project;
use App\Services\ActivityTreeBuilder;
use App\Support\Csv;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Activity Log tab data - the existing activity_logs rows regrouped as a
     * Cabinet > Task > Sub Task > Checklist tree (see ActivityTreeBuilder).
     * Same access as the Project/Cabinet pages that host the tab: any
     * signed-in user, nothing narrower than those pages already allow.
     */
    public function projectTree(Request $request, Project $project, ActivityTreeBuilder $builder)
    {
        return response()->json($builder->build($project, null, $this->filters($request)));
    }

    public function cabinetTree(Request $request, Cabinet $cabinet, ActivityTreeBuilder $builder)
    {
        return response()->json($builder->build($cabinet->project, $cabinet, $this->filters($request)));
    }

    /**
     * The same filtered tree, flattened: one row per activity with its Cabinet/Task/
     * Sub Task/Checklist path in columns (a CSV has no tree to expand).
     */
    public function projectExport(Request $request, Project $project, ActivityTreeBuilder $builder)
    {
        return $this->export($builder->build($project, null, $this->filters($request)), "ประวัติ-{$project->project_no}");
    }

    public function cabinetExport(Request $request, Cabinet $cabinet, ActivityTreeBuilder $builder)
    {
        return $this->export($builder->build($cabinet->project, $cabinet, $this->filters($request)), "ประวัติ-{$cabinet->mo_no}");
    }

    private function export(array $tree, string $name)
    {
        $rows = [];
        $walk = function (array $nodes, array $path) use (&$walk, &$rows) {
            foreach ($nodes as $node) {
                $here = [...$path, $node['label']];

                foreach ($node['activities'] as $a) {
                    $detail = collect($a['changes'])->map(fn ($c) => "{$c['label']}: {$c['old']} → {$c['new']}")->push($a['detail'])->filter()->implode(' | ');
                    $rows[] = [implode(' › ', $here), $a['at'], $a['actor'], $a['title'], $detail];
                }

                $walk($node['children'], $here);
            }
        };
        $walk($tree['nodes'], []);

        return Csv::download($name.'-'.today()->format('Y-m-d').'.csv', ['ตำแหน่ง (Cabinet › Task › Sub Task › Checklist)', 'เวลา', 'ผู้ดำเนินการ', 'กิจกรรม', 'รายละเอียด'], $rows);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', 'in:create,edit,status,assignment,checklist,attachment,delete'],
            'entity' => ['nullable', 'in:project,cabinet,task,subtask,checklist'],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:asc,desc'],
        ]);
    }

    /**
     * Edits a Comment (a manually-written ActivityLog row, action=note) - the
     * owner only, keeps the original created_at so it stays in place in the
     * timeline; updated_at then differs from created_at, which the view uses
     * to show a "(แก้ไขแล้ว)" marker instead of a separate edit-history log.
     */
    public function update(Request $request, ActivityLog $activityLog)
    {
        abort_unless($activityLog->action === 'note', 404);
        abort_unless($activityLog->user_id === $request->user()->id, 403, 'แก้ไขได้เฉพาะคอมเมนต์ของตัวเองเท่านั้น');

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $activityLog->update(['description' => $data['note']]);

        // loggable_type/loggable_id already identify which page (Project or
        // Cabinet) this comment belongs to - shared by both, so edit lands
        // back on the Comment tab exactly like posting a new one does,
        // instead of back()/Referer bouncing to that page's default tab.
        [$routeName, $param] = $activityLog->loggable_type === Project::class
            ? ['projects.show', 'project']
            : ['cabinets.show', 'cabinet'];

        return redirect()->route($routeName, [$param => $activityLog->loggable_id, 'tab' => 'comment'])
            ->with('success', 'แก้ไขคอมเมนต์เรียบร้อยแล้ว');
    }
}
