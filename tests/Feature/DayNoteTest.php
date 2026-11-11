<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentDayNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** หมายเหตุรายวัน (DayNoteController): a department's notes for a day on the Planning page. */
class DayNoteTest extends TestCase
{
    use RefreshDatabase;

    private Department $ship;

    private Department $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ship = Department::create(['name' => 'จัดส่งสินค้า', 'code' => 'SHP', 'is_active' => true]);
        $this->qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'N'.++$n, 'email' => 'note'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function add(User $u, Department $d, string $date = '2026-11-20', string $body = 'รถเครนเข้าศูนย์')
    {
        return $this->actingAs($u)->postJson(route('planning.day-notes.store'), ['department_id' => $d->id, 'date' => $date, 'body' => $body]);
    }

    public function test_a_department_writes_many_notes_a_day_and_only_it_sees_them(): void
    {
        $a = $this->user($this->ship);
        $b = $this->user($this->ship);
        $this->add($a, $this->ship)->assertCreated()->assertJsonPath('note.by', $a->name)->assertJsonPath('note.mine', true);
        $this->add($b, $this->ship, body: 'ส่ง 2 จุด ออก 07:00')->assertCreated();
        $this->add($a, $this->ship, '2026-12-01', 'เดือนหน้า')->assertCreated();

        $range = ['from' => '2026-11-01', 'to' => '2026-11-30'];
        $notes = $this->actingAs($b)->getJson(route('planning.day-notes.index', $range))->assertOk()->json('notes');
        $this->assertSame(['รถเครนเข้าศูนย์', 'ส่ง 2 จุด ออก 07:00'], array_column($notes, 'body'), 'the month asked for, in order');
        $this->assertSame([false, true], array_column($notes, 'mine'));

        // another department, an admin with no department: nothing
        $this->assertSame([], $this->actingAs($this->user($this->qc))->getJson(route('planning.day-notes.index', $range))->json('notes'));
        $this->assertSame([], $this->actingAs($this->user(null, 'admin'))->getJson(route('planning.day-notes.index', $range))->json('notes'));

        // someone who also looks after the delivery department (Settings > ผู้ใช้งาน) sees and writes them
        $helper = $this->user($this->qc);
        $helper->extraDepartments()->attach($this->ship->id);
        $this->assertCount(2, $this->actingAs($helper->fresh())->getJson(route('planning.day-notes.index', $range))->json('notes'));
    }

    public function test_only_into_your_own_department_and_never_empty_or_too_long(): void
    {
        $a = $this->user($this->ship);
        $this->add($a, $this->qc)->assertStatus(422)->assertJsonValidationErrors(['department_id' => 'เขียนหมายเหตุได้เฉพาะแผนกของคุณ']);
        $this->add($a, $this->ship, body: '')->assertStatus(422)->assertJsonValidationErrors(['body' => 'พิมพ์หมายเหตุก่อน']);
        $this->add($a, $this->ship, body: str_repeat('ก', 501))->assertStatus(422)->assertJsonValidationErrors('body');
        $this->assertSame(0, DepartmentDayNote::count());
    }

    public function test_only_the_writer_changes_or_deletes_a_note(): void
    {
        $a = $this->user($this->ship);
        $b = $this->user($this->ship);
        $id = $this->add($a, $this->ship)->json('note.id');

        $this->actingAs($b)->patchJson(route('planning.day-notes.update', $id), ['body' => 'แก้'])->assertForbidden();
        $this->actingAs($b)->deleteJson(route('planning.day-notes.destroy', $id))->assertForbidden();
        $this->actingAs($this->user(null, 'admin'))->deleteJson(route('planning.day-notes.destroy', $id))->assertForbidden();

        $this->actingAs($a)->patchJson(route('planning.day-notes.update', $id), ['body' => 'ศูนย์รับรถพรุ่งนี้'])->assertOk()->assertJsonPath('note.body', 'ศูนย์รับรถพรุ่งนี้');
        $this->actingAs($a)->deleteJson(route('planning.day-notes.destroy', $id))->assertOk();
        $this->assertSame(0, DepartmentDayNote::count());
    }

    public function test_the_planning_page_knows_the_users_departments_for_notes(): void
    {
        $html = $this->actingAs($this->user($this->ship))->get(route('planning.board'))->assertOk()->getContent();
        $this->assertStringContainsString('"noteDepts":[{"id":'.$this->ship->id, $html);
        $this->assertStringContainsString('openDayNotes(', $html);

        $html = $this->actingAs($this->user(null, 'admin'))->get(route('planning.board'))->assertOk()->getContent();
        $this->assertStringContainsString('"noteDepts":[]', $html);
    }
}
