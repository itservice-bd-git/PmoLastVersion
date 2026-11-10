<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every page here is a logged-in view of live data - never cacheable
        // by a browser, CDN, or hosting-level page cache. See the class docblock.
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'gzip' => \App\Http\Middleware\GzipResponse::class,
            'pmo' => \App\Http\Middleware\EnsureCanEditPlan::class,   // project data / dates: edit.manage (Admin + Project Manager by default)
            'perm' => \App\Http\Middleware\EnsurePermission::class,      // perm:page.planning - Settings > สิทธิ์การใช้งาน
            'project.visible' => \App\Http\Middleware\EnsureProjectVisible::class,   // a project (or anything in one) only for people who see it
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\DisableResponseCaching::class,
            \App\Http\Middleware\RunDailyReminders::class,   // reminders without a cron job (once a day)
            \App\Http\Middleware\EnsureCanWork::class,       // a "ดูได้อย่างเดียว" department (edit.work off) writes nothing
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form sent after the login ran out (or from a page left open too long) - not the bare "419 Page Expired":
        // logged out → log in again, then back to the page the form was on; still logged in → back to the form with
        // what was typed, to send again. A call from a page's script keeps its 419 (resources/js/session-expiry.js).
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            // the framework has already made the TokenMismatchException a 419 by now
            if (! $e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException || $request->expectsJson()) {
                return null;
            }
            if ($request->user()) {
                return back()->withInput($request->except('password', 'password_confirmation', '_token'))
                    ->with('error', 'หน้านี้เปิดค้างไว้นานเกินไป ข้อมูลยังไม่ถูกบันทึก ลองกดบันทึกอีกครั้ง');
            }
            $from = url()->previous();
            if (rtrim(parse_url($from, PHP_URL_PATH) ?? '', '/') !== rtrim(parse_url(route('login'), PHP_URL_PATH) ?? '', '/')) {
                redirect()->setIntendedUrl($from);
            }

            return redirect()->route('login')->with('status', 'หมดเวลาเข้าสู่ระบบ กรุณาเข้าสู่ระบบใหม่ แล้วระบบจะพากลับไปหน้าเดิม');
        });
    })->create();
