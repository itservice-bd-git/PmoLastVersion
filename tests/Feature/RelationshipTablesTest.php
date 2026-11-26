<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\ChecklistStatus;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The join tables and customers of 2026_11_17 follow the columns the code still writes (KeepsIdListsInStep,
 * Project::linkCustomer): the old JSON / text stays as it was, the new rows always match it.
 */
class RelationshipTablesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Cabinet> */
    private array $cab = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::forceCreate(['project_no' => 'P-REL', 'project_name' => 'Rel', 'customer_name' => 'บจก. ทดสอบ', 'status' => 'in_progress', 'priority' => 'normal']);
        foreach ([1, 2, 3] as $i) {
            $this->cab[] = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => "MO-R{$i}", 'cabinet_name' => "C{$i}", 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        }
    }

    private function rows(string $table, string $own, int $id): array
    {
        return DB::table($table)->where($own, $id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_a_rounds_cabinets_and_their_packing_follow_cabinet_ids_and_packed(): void
    {
        [$a, $b, $c] = $this->cab;
        $packer = User::forceCreate(['name' => 'Packer', 'email' => 'packer@example.com', 'password' => 'secret-pass', 'role' => 'member', 'is_active' => true]);
        $d = ProjectDelivery::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'status' => 'waiting', 'delivery_date' => '2026-11-20', 'cabinet_ids' => [$a->id, $b->id, 9999]]);

        $this->assertSame([$a->id, $b->id], $d->cabinets()->orderBy('cabinets.id')->pluck('cabinets.id')->all(), 'an id that points nowhere is left out');
        $this->assertFalse((bool) $d->fresh()->all_cabinets);

        // packed: the cabinet's row gets when, and who saved it
        $this->actingAs($packer);
        $d->packed = [$a->id => '2026-11-19 10:30'];
        $d->save();
        $row = collect($this->rows('delivery_cabinet', 'project_delivery_id', $d->id))->firstWhere('cabinet_id', $a->id);
        $this->assertSame(['2026-11-19 10:30:00', $packer->id], [(string) $row['packed_at'], (int) $row['packed_by']]);
        $this->assertNull(collect($this->rows('delivery_cabinet', 'project_delivery_id', $d->id))->firstWhere('cabinet_id', $b->id)['packed_at']);

        // a cabinet swapped, packing taken back: the rows follow
        $d->update(['cabinet_ids' => [$a->id, $c->id], 'packed' => null]);
        $rows = collect($this->rows('delivery_cabinet', 'project_delivery_id', $d->id));
        $this->assertSame([$a->id, $c->id], $rows->pluck('cabinet_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        $this->assertSame([null, null], $rows->pluck('packed_at')->all());

        // no cabinets named = every cabinet
        $d->update(['cabinet_ids' => []]);
        $this->assertSame([], $this->rows('delivery_cabinet', 'project_delivery_id', $d->id));
        $this->assertTrue((bool) $d->fresh()->all_cabinets);
        $this->assertSame([], $d->fresh()->cabinet_ids, 'the old column is as the code wrote it');
    }

    public function test_a_cabinet_knows_its_rounds_and_a_cabinet_deleted_for_good_leaves_them(): void
    {
        [$a, $b] = $this->cab;
        $d = ProjectDelivery::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'status' => 'waiting', 'delivery_date' => '2026-11-20', 'cabinet_ids' => [$a->id, $b->id]]);
        $f = ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'fat_date' => '2026-11-18', 'status' => 'scheduled', 'cabinet_ids' => [$a->id]]);

        $this->assertSame([$d->id], $a->deliveries()->pluck('project_deliveries.id')->all());
        $this->assertSame([$f->id], $a->fats()->pluck('project_fats.id')->all());

        $a->forceDelete();
        $this->assertSame([$b->id], DB::table('delivery_cabinet')->where('project_delivery_id', $d->id)->pluck('cabinet_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([], $this->rows('fat_cabinet', 'project_fat_id', $f->id));
    }

    public function test_a_fat_round_with_no_cabinets_named_is_for_every_cabinet(): void
    {
        $f = ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'fat_date' => '2026-11-18', 'status' => 'scheduled', 'cabinet_ids' => null]);
        $this->assertTrue((bool) $f->fresh()->all_cabinets);
        $this->assertSame([], $this->rows('fat_cabinet', 'project_fat_id', $f->id));

        $f->update(['cabinet_ids' => [$this->cab[1]->id]]);
        $this->assertFalse((bool) $f->fresh()->all_cabinets);
        $this->assertSame([$this->cab[1]->id], $f->cabinets()->pluck('cabinets.id')->all());
    }

    public function test_a_checklist_status_departments_follow_department_ids(): void
    {
        $qc = Department::create(['name' => 'QC', 'code' => 'QC']);
        $wire = Department::create(['name' => 'Wiring', 'code' => 'WR']);
        $s = ChecklistStatus::create(['name' => 'รอของ', 'color' => 'red', 'department_ids' => [$qc->id, $wire->id]]);

        $this->assertSame([$qc->id, $wire->id], $s->departments()->orderBy('departments.id')->pluck('departments.id')->all());
        $this->assertFalse((bool) $s->fresh()->all_departments);

        $s->update(['department_ids' => null]);
        $this->assertSame([], $s->departments()->pluck('departments.id')->all());
        $this->assertTrue((bool) $s->fresh()->all_departments);
    }

    public function test_a_project_points_at_the_customer_of_its_name(): void
    {
        $first = $this->project->fresh();
        $this->assertSame('บจก. ทดสอบ', $first->customer->name);

        $second = Project::forceCreate(['project_no' => 'P-REL2', 'project_name' => 'Rel 2', 'customer_name' => ' บจก. ทดสอบ ', 'customer_phone' => '02-111-2222', 'status' => 'planning', 'priority' => 'normal']);
        $this->assertSame($first->customer_id, $second->customer_id, 'the same customer, not a second one');
        $this->assertSame('02-111-2222', Customer::find($second->customer_id)->phone, 'the phone fills a customer that had none');

        $second->update(['customer_name' => 'บริษัท ใหม่']);
        $this->assertSame('บริษัท ใหม่', $second->fresh()->customer->name);
        $this->assertSame(2, Customer::count());
        $this->assertSame('บริษัท ใหม่', $second->fresh()->customer_name, 'the text column is as the form wrote it');
    }
}
