<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommentAttachment extends Model
{
    protected $fillable = [
        'activity_log_id',
        'original_name',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    public function activityLog()
    {
        return $this->belongsTo(ActivityLog::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return asset('uploads/comment-attachments/'.$this->path);
    }

    public function getSizeForHumansAttribute(): string
    {
        $bytes = $this->size;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
