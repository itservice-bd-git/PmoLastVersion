<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CabinetAttachment extends Model
{
    protected $fillable = [
        'cabinet_id',
        'original_name',
        'path',
        'mime_type',
        'size',
        'description',
        'uploaded_by',
    ];

    public function cabinet()
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return asset('uploads/cabinet-attachments/'.$this->path);
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
