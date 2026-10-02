<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Engineering', 'code' => 'ENG', 'description' => 'Design & Engineering'],
            ['name' => 'Production', 'code' => 'PROD', 'description' => 'Cabinet Production Floor'],
            ['name' => 'Procurement', 'code' => 'PROC', 'description' => 'Purchasing & Materials'],
            ['name' => 'Quality Control', 'code' => 'QC', 'description' => 'Quality Control & Testing'],
            ['name' => 'Sales', 'code' => 'SALES', 'description' => 'Sales & Customer Relations'],
            ['name' => 'Project Management', 'code' => 'PMO', 'description' => 'Project Management Office'],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(['code' => $department['code']], $department);
        }
    }
}
