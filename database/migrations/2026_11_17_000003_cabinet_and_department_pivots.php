<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists of ids kept in JSON become join tables (CLAUDE.md database rules), so a cabinet / department in a list is a
 * real reference: it cannot point at a row that is gone, and "which rounds is this cabinet in" is a lookup.
 *   project_deliveries.cabinet_ids + packed -> delivery_cabinet (one row per cabinet of a round, with packed_at)
 *   project_fats.cabinet_ids                -> fat_cabinet
 *   checklist_statuses.department_ids       -> checklist_status_department
 * (project_folders.department_ids is left alone: no code reads that table since file uploads were removed, ff2a2c0)
 * An empty list meant "every cabinet / every department". That is now said by all_cabinets / all_departments, so a
 * list that loses its last cabinet does not turn into "all" by itself.
 * The JSON columns stay until the clean-up round (nothing is lost, and the code from before this still works).
 * MariaDB 10.4 has no JSON_TABLE: a list is read item by item with JSON_EXTRACT over the numbers 0-99 (a round has
 * at most a few cabinets; the check file compares the totals). Ids that point nowhere are left out - the FK needs it.
 */
return new class extends Migration
{
    /** [join table, owner table, owner key, json column, referenced table, referenced key, flag] */
    private const LISTS = [
        ['delivery_cabinet', 'project_deliveries', 'project_delivery_id', 'cabinet_ids', 'cabinets', 'cabinet_id', 'all_cabinets'],
        ['fat_cabinet', 'project_fats', 'project_fat_id', 'cabinet_ids', 'cabinets', 'cabinet_id', 'all_cabinets'],
        ['checklist_status_department', 'checklist_statuses', 'checklist_status_id', 'department_ids', 'departments', 'department_id', 'all_departments'],
    ];

    public function up(): void
    {
        foreach (self::LISTS as [$join, $owner, $ownerKey, $json, $ref, $refKey, $flag]) {
            Schema::create($join, function (Blueprint $table) use ($join, $owner, $ownerKey, $ref, $refKey) {
                $table->id();
                $table->foreignId($ownerKey)->constrained($owner)->cascadeOnDelete();
                $table->foreignId($refKey)->constrained($ref)->cascadeOnDelete();
                if ($join === 'delivery_cabinet') {
                    $table->dateTime('packed_at')->nullable();   // ห่อตู้เสร็จสิ้น (was project_deliveries.packed)
                    $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
                }
                $table->timestamps();
                $table->unique([$ownerKey, $refKey], "{$join}_unique");   // the default name is over MySQL's 64 characters
            });
            Schema::table($owner, function (Blueprint $table) use ($flag, $json) {
                $table->boolean($flag)->default(true)->after($json);
            });

            $mysql = DB::getDriverName() === 'mysql';
            $item = $mysql
                ? "CAST(JSON_UNQUOTE(JSON_EXTRACT(o.{$json}, CONCAT('\$[', n.n, ']'))) AS UNSIGNED)"
                : "CAST(json_extract(o.{$json}, '\$[' || n.n || ']') AS INTEGER)";
            $numbers = '(SELECT a.d + 10 * b.d AS n FROM '.self::digits('a').' CROSS JOIN '.self::digits('b').')';
            $items = "SELECT o.id AS owner_id, {$item} AS ref_id FROM {$owner} o CROSS JOIN {$numbers} n "
                ."WHERE o.{$json} IS NOT NULL AND n.n < ".($mysql ? "JSON_LENGTH(o.{$json})" : "json_array_length(o.{$json})");
            $packed = $join !== 'delivery_cabinet' ? '' : ($mysql
                ? ", JSON_UNQUOTE(JSON_EXTRACT(o2.packed, CONCAT('\$.\"', x.ref_id, '\"')))"
                : ", json_extract(o2.packed, '\$.\"' || x.ref_id || '\"')");
            DB::statement("INSERT INTO {$join} ({$ownerKey}, {$refKey}".($packed ? ', packed_at' : '').', created_at, updated_at) '
                ."SELECT DISTINCT x.owner_id, x.ref_id{$packed}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM ({$items}) x "
                ."JOIN {$ref} r ON r.id = x.ref_id"
                .($packed ? " JOIN {$owner} o2 ON o2.id = x.owner_id" : ''));

            // a list with something in it means "these only"; empty or none meant "all"
            DB::table($owner)->update([$flag => DB::raw("CASE WHEN {$json} IS NULL OR {$json} IN ('[]', '', 'null') THEN 1 ELSE 0 END")]);
        }
    }

    private static function digits(string $alias): string
    {
        return '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 '
            ."UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) {$alias}";
    }

    public function down(): void
    {
        foreach (array_reverse(self::LISTS) as [$join, $owner, , , , , $flag]) {
            Schema::dropIfExists($join);
            Schema::table($owner, fn (Blueprint $table) => $table->dropColumn($flag));
        }
    }
};
