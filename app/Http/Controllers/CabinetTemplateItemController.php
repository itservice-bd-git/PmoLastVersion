<?php

namespace App\Http\Controllers;

use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CabinetTemplateItemController extends Controller
{
    public function storeTask(Request $request, CabinetTemplate $cabinetTemplate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
        $data['sequence'] = $cabinetTemplate->taskTemplates()->max('sequence') + 1;

        $taskTemplate = $cabinetTemplate->taskTemplates()->create($data);

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('cabinet-templates.partials._task', [
                    'taskTemplate' => $taskTemplate,
                    'departments' => Department::orderBy('name')->get(),
                ])->render(),
            ]);
        }

        return back()->with('success', 'เพิ่ม Task เรียบร้อยแล้ว');
    }

    public function updateTask(Request $request, CabinetTaskTemplate $taskTemplate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $taskTemplate->update($data);

        if ($request->wantsJson()) {
            return response()->json([
                'task_template' => [
                    'name' => $taskTemplate->name,
                ],
            ]);
        }

        return back()->with('success', 'แก้ไข Task เรียบร้อยแล้ว');
    }

    public function destroyTask(Request $request, CabinetTaskTemplate $taskTemplate)
    {
        $template = $taskTemplate->cabinetTemplate;
        $taskTemplate->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'ลบ Task เรียบร้อยแล้ว']);
        }

        return redirect()->route('cabinet-templates.show', $template)->with('success', 'ลบ Task เรียบร้อยแล้ว');
    }

    /**
     * Persist a new Task display order within one template. Only {id, sequence}
     * pairs are accepted (not full task payloads) since that's all a reorder
     * needs - everything else about each task is untouched.
     */
    public function reorderTasks(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct', 'exists:cabinet_task_templates,id'],
            'items.*.sequence' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                CabinetTaskTemplate::whereKey($item['id'])->update(['sequence' => $item['sequence']]);
            }
        });

        return response()->json(['message' => 'บันทึกลำดับเรียบร้อยแล้ว']);
    }

    public function storeSubtask(Request $request, CabinetTaskTemplate $taskTemplate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);
        $data['sequence'] = $taskTemplate->subtaskTemplates()->max('sequence') + 1;

        $subtaskTemplate = $taskTemplate->subtaskTemplates()->create($data);

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('cabinet-templates.partials._subtask', [
                    'subtaskTemplate' => $subtaskTemplate,
                    'departments' => Department::orderBy('name')->get(),
                ])->render(),
            ]);
        }

        return back()->with('success', 'เพิ่ม Sub Task เรียบร้อยแล้ว');
    }

    public function updateSubtask(Request $request, CabinetSubtaskTemplate $subtaskTemplate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $subtaskTemplate->update($data);

        if ($request->wantsJson()) {
            $subtaskTemplate->load('department');

            return response()->json([
                'subtask_template' => [
                    'name' => $subtaskTemplate->name,
                    'department_name' => $subtaskTemplate->department?->name,
                ],
            ]);
        }

        return back()->with('success', 'แก้ไข Sub Task เรียบร้อยแล้ว');
    }

    public function destroySubtask(Request $request, CabinetSubtaskTemplate $subtaskTemplate)
    {
        $template = $subtaskTemplate->taskTemplate->cabinetTemplate;
        $subtaskTemplate->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'ลบ Sub Task เรียบร้อยแล้ว']);
        }

        return redirect()->route('cabinet-templates.show', $template)->with('success', 'ลบ Sub Task เรียบร้อยแล้ว');
    }

    public function reorderSubtasks(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct', 'exists:cabinet_subtask_templates,id'],
            'items.*.sequence' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                CabinetSubtaskTemplate::whereKey($item['id'])->update(['sequence' => $item['sequence']]);
            }
        });

        return response()->json(['message' => 'บันทึกลำดับเรียบร้อยแล้ว']);
    }

    public function storeChecklist(Request $request, CabinetSubtaskTemplate $subtaskTemplate)
    {
        $request->validate([
            'names' => ['required', 'string'],
        ]);

        $names = collect(preg_split('/\r\n|\r|\n/', $request->input('names')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'กรุณากรอกชื่อ Checklist อย่างน้อย 1 รายการ'], 422);
            }

            return back()->with('error', 'กรุณากรอกชื่อ Checklist อย่างน้อย 1 รายการ');
        }

        $sequence = $subtaskTemplate->checklistTemplates()->max('sequence') + 1;

        $created = $names->map(function (string $name) use ($subtaskTemplate, &$sequence) {
            return $subtaskTemplate->checklistTemplates()->create([
                'name' => $name,
                'sequence' => $sequence++,
            ]);
        });

        if ($request->wantsJson()) {
            $html = $created->map(fn ($checklistTemplate) => view('cabinet-templates.partials._checklist', [
                'checklistTemplate' => $checklistTemplate,
            ])->render())->implode('');

            return response()->json(['html' => $html, 'count' => $created->count()]);
        }

        return back()->with('success', "เพิ่ม Checklist {$names->count()} รายการเรียบร้อยแล้ว");
    }

    public function updateChecklist(Request $request, ChecklistTemplate $checklistTemplate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $checklistTemplate->update($data);

        if ($request->wantsJson()) {
            return response()->json(['checklist_template' => ['name' => $checklistTemplate->name]]);
        }

        return back()->with('success', 'แก้ไข Checklist เรียบร้อยแล้ว');
    }

    public function destroyChecklist(Request $request, ChecklistTemplate $checklistTemplate)
    {
        $template = $checklistTemplate->subtaskTemplate->taskTemplate->cabinetTemplate;
        $checklistTemplate->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'ลบ Checklist เรียบร้อยแล้ว']);
        }

        return redirect()->route('cabinet-templates.show', $template)->with('success', 'ลบ Checklist เรียบร้อยแล้ว');
    }

    public function reorderChecklists(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct', 'exists:checklist_templates,id'],
            'items.*.sequence' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                ChecklistTemplate::whereKey($item['id'])->update(['sequence' => $item['sequence']]);
            }
        });

        return response()->json(['message' => 'บันทึกลำดับเรียบร้อยแล้ว']);
    }
}
