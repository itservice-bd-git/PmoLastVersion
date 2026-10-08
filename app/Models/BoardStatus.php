<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Planning status. The five built-in ones (key set) are what Planning computes; any others are picked by hand per project. */
class BoardStatus extends Model
{
    protected $fillable = ['key', 'name', 'color', 'is_done', 'sort'];

    protected function casts(): array
    {
        return ['is_done' => 'boolean'];
    }

    /** The id the page uses: the old s_* ids for the built-in ones (kept stable), x<id> for the rest. */
    public const PAGE_IDS = ['todo' => 's_todo', 'accepted' => 's_review', 'doing' => 's_doing', 'done' => 's_done', 'late' => 's_fail'];

    public function pageId(): string
    {
        return $this->key ? self::PAGE_IDS[$this->key] : 'x'.$this->id;
    }

    public static function fromPageId(string $id): ?self
    {
        if (($key = array_search($id, self::PAGE_IDS, true)) !== false) {
            return self::where('key', $key)->first();
        }

        return preg_match('/^x(\d+)$/', $id, $m) ? self::whereNull('key')->find((int) $m[1]) : null;
    }
}
