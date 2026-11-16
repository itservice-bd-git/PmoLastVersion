<?php

namespace App\Providers;

use App\Models\User;
use App\Services\HandoffService;
use App\Services\PlanningBoard;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // one per request: it reads Settings > การส่งต่องาน once (the cabinet page asks it for every Sub Task)
        $this->app->scoped(HandoffService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // any saved / deleted record: the Planning board's version moves, so its 15-second poll rebuilds (PlanningBoard::stamp)
        Event::listen(['eloquent.saved: *', 'eloquent.deleted: *', 'eloquent.restored: *'], fn () => PlanningBoard::touch());

        // ถังขยะ (routes: 'can:manage-trash'): Admin / Project Manager
        Gate::define('manage-trash', fn (User $user) => $user->canManageTrash()
            ? Response::allow() : Response::deny('เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager'));

        // @editplan … @endeditplan: buttons that change project data / dates - Admin / Project Manager only (routes: 'pmo')
        Blade::if('editplan', fn () => (bool) auth()->user()?->canDispatchWork());
        // @edittemplates … @endedittemplates: changing the cabinet templates (Settings > สิทธิ์การใช้งาน: edit.templates; routes: 'perm:edit.templates')
        Blade::if('edittemplates', fn () => (bool) auth()->user()?->allows('edit.templates'));

        // Every date a person reads is dd/mm/yyyy in พ.ศ. (15/09/2569); stored / sent values stay Y-m-d (ค.ศ.).
        Carbon::macro('thaiDate', fn () => $this->format('d/m/').($this->year + 543));
        Carbon::macro('thaiDateTime', fn () => $this->format('d/m/').($this->year + 543).$this->format(' H:i'));
        //
    }
}
