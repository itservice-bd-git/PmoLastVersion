<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'project_id',
        'user_id',
        'loggable_type',
        'loggable_id',
        'action',
        'description',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function loggable()
    {
        return $this->morphTo();
    }

    // Files attached to this entry when it's a Comment (action='note') - see
    // CommentAttachmentUploader. loggable_type/loggable_id above identify what
    // the comment is ABOUT (the Project/Cabinet); this is a separate relation
    // for files attached to the comment itself.
    public function attachments()
    {
        return $this->hasMany(CommentAttachment::class);
    }

    public static function record(?int $projectId, ?int $userId, Model $loggable, string $action, string $description, array $changes = []): ?self
    {
        if ($projectId === null) {
            return null;
        }

        return static::create([
            'project_id' => $projectId,
            'user_id' => $userId,
            'loggable_type' => $loggable::class,
            'loggable_id' => $loggable->getKey(),
            'action' => $action,
            'description' => $description,
            'changes' => $changes ?: null,
        ]);
    }
}
