<?php

namespace App\Models\Concerns;

use App\Services\ProjectLock;

/**
 * Blocks create/update/delete of this record while its Project is closed.
 * Uses the same activityProjectId() every logged model already defines.
 */
trait GuardsClosedProject
{
    protected static function bootGuardsClosedProject(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(fn ($model) => ProjectLock::assertOpen($model->activityProjectId()));
        }
    }
}
