<?php

namespace App\Http\Controllers;

use App\Models\CabinetTask;
use Illuminate\Http\Request;

class CabinetTaskController extends Controller
{
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
}
