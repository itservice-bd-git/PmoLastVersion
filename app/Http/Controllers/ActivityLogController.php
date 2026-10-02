<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
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
