<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardLabel;
use Illuminate\Http\Request;

/** Settings > บอร์ด and แท็ก (admin routes): extra Planning calendars (e.g. Service) and the tags projects can carry. */
class BoardController extends Controller
{
    public function boards()
    {
        return view('settings.boards', ['boards' => Board::withCount('projects')->orderBy('sort')->orderBy('id')->get()]);
    }

    public function storeBoard(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        Board::create(['name' => trim($data['name']), 'sort' => (int) Board::max('sort') + 1]);

        return back()->with('success', 'เพิ่มบอร์ดแล้ว');
    }

    public function updateBoard(Request $request, Board $board)
    {
        $board->update($request->validate(['name' => ['required', 'string', 'max:60']]));

        return back()->with('success', 'บันทึกบอร์ดแล้ว');
    }

    public function destroyBoard(Board $board)
    {
        $board->delete();   // its projects go back to the main board (foreign key: set null) - nothing is deleted

        return back()->with('success', 'ลบบอร์ดแล้ว โครงการในบอร์ดนี้กลับไปอยู่บอร์ดหลัก');
    }

    public function labels()
    {
        return view('settings.labels', ['labels' => BoardLabel::withCount('projects')->orderBy('sort')->orderBy('id')->get()]);
    }

    public function storeLabel(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:40'], 'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']]);
        BoardLabel::create(['name' => trim($data['name']), 'color' => $data['color'], 'sort' => (int) BoardLabel::max('sort') + 1]);

        return back()->with('success', 'เพิ่มแท็กแล้ว');
    }

    public function updateLabel(Request $request, BoardLabel $label)
    {
        $label->update($request->validate(['name' => ['required', 'string', 'max:40'], 'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']]));

        return back()->with('success', 'บันทึกแท็กแล้ว');
    }

    public function destroyLabel(BoardLabel $label)
    {
        $label->delete();

        return back()->with('success', 'ลบแท็กแล้ว');
    }
}
