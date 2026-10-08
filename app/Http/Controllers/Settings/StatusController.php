<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BoardStatus;
use Illuminate\Http\Request;

/**
 * Settings > สถานะงาน (admin routes): the statuses Planning offers. The five built-in ones are what Planning works out by itself from
 * the departments' progress - they can be renamed / recoloured but not removed. Any others are picked by hand per project.
 */
class StatusController extends Controller
{
    public function index()
    {
        return view('settings.statuses', ['statuses' => BoardStatus::orderBy('sort')->orderBy('id')->get()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'statuses' => ['required', 'array'],
            'statuses.*.name' => ['required', 'string', 'max:40'],
            'statuses.*.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        foreach (BoardStatus::whereIn('id', array_keys($data['statuses']))->get() as $status) {
            $row = $data['statuses'][$status->id];
            $status->update([
                'name' => trim($row['name']),
                'color' => $row['color'],
                // "finished" is fixed for the built-in ones (it is what the computed ones mean)
                'is_done' => $status->key ? $status->is_done : ! empty($request->input("statuses.{$status->id}.is_done")),
            ]);
        }

        return back()->with('success', 'บันทึกสถานะแล้ว');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        BoardStatus::create($data + ['is_done' => $request->boolean('is_done'), 'sort' => (int) BoardStatus::max('sort') + 1]);

        return back()->with('success', 'เพิ่มสถานะแล้ว');
    }

    public function destroy(BoardStatus $status)
    {
        abort_if($status->key, 422, 'สถานะที่ระบบคำนวณเองลบไม่ได้');

        $status->delete();   // projects that had it picked go back to the computed status (foreign key: set null)

        return back()->with('success', 'ลบสถานะแล้ว');
    }

    public function move(Request $request, BoardStatus $status)
    {
        $dir = $request->validate(['dir' => ['required', 'in:up,down']])['dir'];
        $list = BoardStatus::orderBy('sort')->orderBy('id')->get()->values();
        $i = $list->search(fn ($s) => $s->id === $status->id);
        $j = $dir === 'up' ? $i - 1 : $i + 1;

        if (isset($list[$j])) {
            $list->splice($i, 1, [$list[$j]]);
            $list->splice($j, 1, [$status]);
            $list->each(fn ($s, $n) => $s->update(['sort' => $n + 1]));
        }

        return back();
    }
}
