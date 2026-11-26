<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings › แผนก (admin): a department's teams - added, renamed, removed (its people then have no team). */
class TeamController extends Controller
{
    public function store(Request $request, Department $department)
    {
        $team = $department->teams()->create($this->validated($request, $department));

        return back()->with('success', "เพิ่มทีม \"{$team->name}\" ใน {$department->name} แล้ว");
    }

    public function update(Request $request, Team $team)
    {
        $team->update($this->validated($request, $team->department, $team));

        return back()->with('success', "เปลี่ยนชื่อทีมเป็น \"{$team->name}\" แล้ว");
    }

    public function destroy(Team $team)
    {
        $count = $team->users()->count();
        $team->delete();   // its people stay, without a team (users.team_id: null on delete)

        return back()->with('success', "ลบทีม \"{$team->name}\" แล้ว".($count ? " · ผู้ใช้ {$count} คนไม่มีทีมแล้ว" : ''));
    }

    private function validated(Request $request, Department $department, ?Team $team = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('teams', 'name')->where('department_id', $department->id)->ignore($team?->id)],
        ], [
            'name.required' => 'ใส่ชื่อทีม',
            'name.unique' => 'แผนกนี้มีทีมชื่อนี้แล้ว',
        ]);
    }
}
