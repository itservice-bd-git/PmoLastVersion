<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectRequest extends Model
{
    const STATUS_PENDING = 'pending';

    const STATUS_IMPORTED = 'imported';

    const STATUS_REJECTED = 'rejected';

    public static array $statuses = [
        self::STATUS_PENDING => 'รอพิจารณา',
        self::STATUS_IMPORTED => 'นำเข้าแล้ว',
        self::STATUS_REJECTED => 'ปฏิเสธ',
    ];

    protected $fillable = [
        'requester_name', 'contact', 'customer_name', 'title', 'description', 'quantity', 'needed_by',
        'status', 'reject_reason', 'project_id', 'handled_by', 'handled_at',
    ];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'handled_at' => 'datetime', 'quantity' => 'integer'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Next "PJ-nnnnnn" after the highest existing one (blank when the numbering scheme is not in use yet). */
    public static function suggestedProjectNo(): string
    {
        $max = Project::withTrashed()->where('project_no', 'like', 'PJ-%')->pluck('project_no')
            ->map(fn ($no) => preg_match('/^PJ-(\d+)$/', $no, $m) ? (int) $m[1] : null)
            ->filter()->max();

        return $max ? 'PJ-'.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT) : '';
    }
}
