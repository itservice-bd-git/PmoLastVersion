<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_dashboard_which_requires_login(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
