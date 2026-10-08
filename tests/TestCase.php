<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // AppSetting remembers its rows for the request; a test process is many "requests" over refreshed databases
        \App\Models\AppSetting::flush();
    }
}
