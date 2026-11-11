<?php

namespace App\Http\Controllers;

use App\Models\DepartmentDayNote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * หมายเหตุรายวัน on the Planning page: notes a department keeps for a day ("รถ 6 ล้อเครนเข้าศูนย์", "ส่ง 2 จุด ออก 07:00").
 *
 * - Seen only by the department's own people (User::departmentIds(): their department and the ones they look after);
 *   a user with no department has none. Enforced here, not by the page.
 * - Anyone of the department adds; only the one who wrote a note changes or deletes it.
 * - JSON only: the page keeps its month of notes and asks again when the month changes or after a save.
 */
class DayNoteController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $user = $request->user();

        $notes = DepartmentDayNote::with('user:id,name')->whereIn('department_id', $user->departmentIds() ?: [0])
            ->whereBetween('date', [$data['from'], $data['to']])->orderBy('date')->orderBy('id')->get();

        return response()->json(['notes' => $notes->map->toBoard($user)->all()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::in($user->departmentIds())],
            'date' => ['required', 'date_format:Y-m-d'],
            'body' => ['required', 'string', 'max:'.DepartmentDayNote::MAX],
        ], ['department_id.in' => 'เขียนหมายเหตุได้เฉพาะแผนกของคุณ', 'body.required' => 'พิมพ์หมายเหตุก่อน', 'body.max' => 'หมายเหตุยาวได้ไม่เกิน '.DepartmentDayNote::MAX.' ตัวอักษร']);

        $note = DepartmentDayNote::create(['department_id' => $data['department_id'], 'date' => $data['date'], 'body' => trim($data['body']), 'user_id' => $user->id]);

        return response()->json(['note' => $note->load('user:id,name')->toBoard($user)], 201);
    }

    public function update(Request $request, DepartmentDayNote $note)
    {
        $this->ownOnly($request, $note);
        $data = $request->validate(['body' => ['required', 'string', 'max:'.DepartmentDayNote::MAX]],
            ['body.required' => 'พิมพ์หมายเหตุก่อน', 'body.max' => 'หมายเหตุยาวได้ไม่เกิน '.DepartmentDayNote::MAX.' ตัวอักษร']);
        $note->update(['body' => trim($data['body'])]);

        return response()->json(['note' => $note->load('user:id,name')->toBoard($request->user())]);
    }

    public function destroy(Request $request, DepartmentDayNote $note)
    {
        $this->ownOnly($request, $note);
        $note->delete();

        return response()->json(['ok' => true]);
    }

    /** the writer, while still in that department; anyone else gets a 403 with a Thai reason */
    private function ownOnly(Request $request, DepartmentDayNote $note): void
    {
        $user = $request->user();
        abort_unless($note->user_id === $user->id && in_array((int) $note->department_id, array_map('intval', $user->departmentIds()), true), 403, 'แก้หรือลบได้เฉพาะหมายเหตุที่คุณเขียน');
    }
}
