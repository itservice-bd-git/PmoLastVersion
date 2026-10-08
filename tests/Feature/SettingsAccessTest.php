<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\JobType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: Settings > Users/Departments/Job Types had no role check, so any signed-in
 * member could create an admin account. They are admin-only now.
 */
class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'set'.$n, 'password' => 'secret-pass', 'role' => $role, 'is_active' => true]);
    }

    public function test_non_admins_cannot_reach_settings_pages(): void
    {
        $dept = Department::create(['name' => 'D', 'code' => 'D']);
        $job = JobType::create(['name' => 'J', 'is_active' => true]);
        $target = $this->user('member');

        foreach (['member', 'project_manager', 'sales', 'production'] as $role) {
            $u = $this->user($role);
            $this->actingAs($u)->get(route('settings.users.index'))->assertForbidden();
            $this->actingAs($u)->get(route('settings.departments.index'))->assertForbidden();
            $this->actingAs($u)->get(route('settings.job-types.index'))->assertForbidden();
            $this->actingAs($u)->put(route('settings.users.update', $target), ['name' => 'x', 'email' => 'x', 'role' => 'admin'])->assertForbidden();
            $this->actingAs($u)->delete(route('settings.departments.destroy', $dept))->assertForbidden();
            $this->actingAs($u)->delete(route('settings.job-types.destroy', $job))->assertForbidden();
        }
    }

    public function test_a_member_cannot_mint_an_admin_account(): void
    {
        $this->actingAs($this->user('member'))
            ->post(route('settings.users.store'), ['name' => 'x', 'email' => 'evil', 'password' => 'password123', 'role' => 'admin', 'is_active' => 1])
            ->assertForbidden();

        $this->assertFalse(User::where('email', 'evil')->exists());
    }

    public function test_admin_still_manages_settings(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('settings.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('settings.departments.index'))->assertOk();
        $this->actingAs($admin)->get(route('settings.job-types.index'))->assertOk();
        $this->actingAs($admin)->post(route('settings.users.store'), ['name' => 'New', 'email' => 'newuser', 'password' => 'password123', 'role' => 'member', 'is_active' => 1])->assertRedirect();
        $this->assertTrue(User::where('email', 'newuser')->exists());
    }
}
