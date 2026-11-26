<?php

namespace App\View\Composers;

use App\Services\NotificationFeed;
use Illuminate\View\View;

/** layouts.app: the bell's first feed, drawn with the page (it then asks NotificationController::feed every 15 s). */
class BellComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();
        $view->with('bellFeed', $user ? NotificationFeed::for($user) : null);
    }
}
