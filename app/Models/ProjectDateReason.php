<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Settings > สาเหตุการเลื่อน: one reason a project's dates may be moved for (App\Services\ProjectDates). */
class ProjectDateReason extends Model
{
    /** chip colours: [background, text] (Tailwind classes already in the built CSS) */
    public const COLORS = [
        'indigo' => ['bg-indigo-100', 'text-indigo-800'], 'amber' => ['bg-amber-100', 'text-amber-800'],
        'sky' => ['bg-sky-100', 'text-sky-800'], 'orange' => ['bg-orange-100', 'text-orange-800'],
        'red' => ['bg-red-100', 'text-red-800'], 'emerald' => ['bg-emerald-100', 'text-emerald-800'],
        'slate' => ['bg-slate-100', 'text-slate-700'],
    ];

    protected $fillable = ['name', 'color', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function chip(?string $color): string
    {
        return implode(' ', self::COLORS[$color] ?? self::COLORS['slate']);
    }
}
