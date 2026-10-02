<?php

namespace Database\Seeders;

use App\Models\CabinetTemplate;
use Illuminate\Database\Seeder;

class CabinetTemplateSeeder extends Seeder
{
    /**
     * Standard Cabinet Template: Equipment / Busbar / Steel / Wiring,
     * each with 2 demo sub tasks and 3 demo checklist items.
     */
    public function run(): void
    {
        $template = CabinetTemplate::updateOrCreate(
            ['code' => 'STANDARD'],
            [
                'name' => 'Standard Cabinet Template',
                'description' => 'Default production template: Equipment, Busbar, Steel, Wiring',
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $template->taskTemplates()->delete();

        $tasks = [
            'Equipment' => ['Prepare Equipment', 'Equipment Installation'],
            'Busbar' => ['Busbar Cutting & Bending', 'Busbar Installation'],
            'Steel' => ['Steel Structure Preparation', 'Steel Painting & Finishing'],
            'Wiring' => ['Control Wiring', 'Power Wiring'],
        ];

        $taskSequence = 0;

        foreach ($tasks as $taskName => $subtaskNames) {
            $taskTemplate = $template->taskTemplates()->create([
                'name' => $taskName,
                'description' => "{$taskName} production task",
                'weight' => 0,
                'sequence' => $taskSequence++,
            ]);

            $subtaskSequence = 0;

            foreach ($subtaskNames as $subtaskName) {
                $subtaskTemplate = $taskTemplate->subtaskTemplates()->create([
                    'name' => $subtaskName,
                    'description' => null,
                    'department_id' => null,
                    'sequence' => $subtaskSequence++,
                ]);

                foreach (['Checklist 01', 'Checklist 02', 'Checklist 03'] as $i => $checklistName) {
                    $subtaskTemplate->checklistTemplates()->create([
                        'name' => "{$checklistName} - {$subtaskName}",
                        'description' => null,
                        'sequence' => $i,
                    ]);
                }
            }
        }

        $this->command?->info('Standard Cabinet Template seeded (4 tasks, 8 sub tasks, 24 checklist items).');
    }
}
