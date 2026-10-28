<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\CabinetTask;
use App\Services\ProgressService;
use Illuminate\Http\Request;

class CabinetTaskController extends Controller
{
    /** Admin / PM (route: pmo): a new Task at the end of this cabinet's list. Only this cabinet - the template never changes. */
    public function store(Request $request, Cabinet $cabinet, ProgressService $progress)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $task = $cabinet->tasks()->create([   // a closed project refuses (GuardsClosedProject -> flash error)
            'name' => trim($data['name']),
            'sequence' => (int) $cabinet->tasks()->max('sequence') + 1,
            'status' => 'not_started',
            'progress' => 0,
        ]);
        $progress->recalculateCabinet($cabinet->fresh());

        return redirect()->route('cabinets.show', $cabinet)->with('success', "เพิ่ม Task \"{$task->name}\" แล้ว (เฉพาะตู้นี้ · เทมเพลตไม่เปลี่ยน)");
    }

    public function update(Request $request, CabinetTask $cabinetTask)
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'remark' => ['nullable', 'string'],
        ]);

        $cabinetTask->update($data);

        return back()->with('success', 'บันทึกงานเรียบร้อยแล้ว');
    }

    /**
     * Admin / PM (route: pmo): this cabinet's Task goes to the trash with its Sub Tasks and Checklists (same deleted_at, so
     * a restore brings back exactly them). Only this cabinet's copy - the template it came from is never touched.
     */
    public function destroy(CabinetTask $cabinetTask, ProgressService $progress)
    {
        $cabinet = $cabinetTask->cabinet;
        $name = $cabinetTask->name;
        $cabinetTask->delete();   // a closed project refuses (GuardsClosedProject -> flash error)
        $progress->recalculateCabinet($cabinet->fresh());

        return redirect()->route('cabinets.show', $cabinet)->with('success', "ลบ Task \"{$name}\" แล้ว — กู้คืนได้จากถังขยะ (เทมเพลตไม่เปลี่ยน)");
    }
}
