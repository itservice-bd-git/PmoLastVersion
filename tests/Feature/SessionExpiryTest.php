<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The login ran out: a form goes to log in (then back), not to "419 Page Expired"; scripts are told to reload. */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // the CSRF check is off in tests: a route that fails it the same way
        Route::middleware('web')->post('/_expired-form', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    }

    public function test_a_form_sent_after_the_login_ran_out_goes_to_log_in_then_back_to_its_page(): void
    {
        $this->from('/projects/5')->post('/_expired-form', ['note' => 'x'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'หมดเวลาเข้าสู่ระบบ กรุณาเข้าสู่ระบบใหม่ แล้วระบบจะพากลับไปหน้าเดิม')
            ->assertSessionHas('url.intended', url('/projects/5'));

        $this->get(route('login'))->assertSee('หมดเวลาเข้าสู่ระบบ กรุณาเข้าสู่ระบบใหม่');

        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(url('/projects/5'));
    }

    public function test_the_login_form_itself_expired_does_not_send_back_to_the_login_page(): void
    {
        $this->from(route('login'))->post('/_expired-form')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('url.intended');
    }

    public function test_still_logged_in_goes_back_to_the_form_with_what_was_typed(): void
    {
        $this->actingAs(User::factory()->create())
            ->from('/projects/5')->post('/_expired-form', ['note' => 'ลูกค้าเลื่อน', 'password' => 'secret'])
            ->assertRedirect('/projects/5')
            ->assertSessionHas('error', 'หน้านี้เปิดค้างไว้นานเกินไป ข้อมูลยังไม่ถูกบันทึก ลองกดบันทึกอีกครั้ง')
            ->assertSessionHasInput('note', 'ลูกค้าเลื่อน')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_a_script_call_keeps_its_419_and_a_logged_out_one_gets_401(): void
    {
        $this->postJson('/_expired-form')->assertStatus(419);
        $this->getJson(route('notifications.feed'))->assertStatus(401);
    }

    public function test_pages_tell_the_script_where_log_in_is_and_the_login_page_has_the_note(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSee('<meta name="login-url" content="'.route('login').'">', false);
        auth()->logout();

        $this->get(route('login'))->assertSee('id="session-expired-note" hidden', false);
    }
}
