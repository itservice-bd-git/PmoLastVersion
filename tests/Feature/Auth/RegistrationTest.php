<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_department_is_optional_when_registering(): void
    {
        $this->post('/register', [
            'name' => 'No Department',
            'email' => 'no-department@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertNull(User::where('email', 'no-department@example.com')->first()->department_id);
    }

    public function test_department_can_be_chosen_when_registering(): void
    {
        $department = Department::create(['name' => 'Busbar', 'code' => 'BUS']);

        $this->post('/register', [
            'name' => 'Has Department',
            'email' => 'has-department@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'department_id' => $department->id,
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame($department->id, User::where('email', 'has-department@example.com')->first()->department_id);
    }
}
