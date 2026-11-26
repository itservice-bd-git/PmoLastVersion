<?php

namespace App\View\Composers;

use App\Services\EventDates;
use Illuminate\View\View;

/** projects.partials._date-move-fields (the FAT and delivery windows): the reasons a day may be moved for. */
class DateMoveFieldsComposer
{
    public function compose(View $view): void
    {
        $view->with('moveReasons', EventDates::reasons());
    }
}
