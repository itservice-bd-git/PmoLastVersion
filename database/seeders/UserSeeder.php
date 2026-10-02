<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $pmo = Department::where('code', 'PMO')->first();
        $sales = Department::where('code', 'SALES')->first();
        $prod = Department::where('code', 'PROD')->first();

        $admin = User::updateOrCreate(
            ['email' => 'pholpaween@avatar-electric.com'],
            [
                'name' => 'Pholpaween (Admin)',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'department_id' => $pmo?->id,
                'position' => 'System Administrator',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $projectManager = User::updateOrCreate(
            ['email' => 'pm@avatar-electric.com'],
            [
                'name' => 'Somchai (Project Manager)',
                'password' => Hash::make('password'),
                'role' => User::ROLE_PROJECT_MANAGER,
                'department_id' => $pmo?->id,
                'position' => 'Project Manager',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $sales1 = User::updateOrCreate(
            ['email' => 'sales@avatar-electric.com'],
            [
                'name' => 'Suda (Sales)',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SALES,
                'department_id' => $sales?->id,
                'position' => 'Sales Executive',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'production@avatar-electric.com'],
            [
                'name' => 'Anan (Production Supervisor)',
                'password' => Hash::make('password'),
                'role' => User::ROLE_PRODUCTION,
                'department_id' => $prod?->id,
                'position' => 'Production Supervisor',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command?->info('Demo users created. Login with any @avatar-electric.com address above, password: password');
    }
}
