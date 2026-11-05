<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * The names and colours of the FAT and delivery states (Settings > สถานะงาน), like the project statuses there. The states
 * themselves are fixed - the app decides with them (no day yet = the delivery department's to pick, delivered = needs a receiver) - so they are only
 * renamed / recoloured, never added or removed. Saved as overrides in AppSetting 'event_statuses'; a name is used when it is
 * written (activity log, notices), so an old log line keeps the name of its day.
 */
final class EventStatuses
{
    public const GROUPS = ['fat' => 'สถานะ FAT', 'delivery' => 'สถานะการจัดส่ง'];

    /** group => key => [name, colour, when it shows] */
    public const DEFAULTS = [
        'fat' => [
            'scheduled' => ['นัดแล้ว', '#a21caf', 'นัด FAT แล้ว ยังไม่ได้ผล'],
            'late' => ['หลังวันครบกำหนด', '#ea580c', 'นัดแล้ว แต่วัน FAT เลยวันครบกำหนดของโครงการ'],
            'passed' => ['ผ่าน', '#059669', ''],
            'failed' => ['ไม่ผ่าน', '#64748b', ''],
        ],
        'delivery' => [
            'draft' => ['ร่าง', '#94a3b8', 'PM ลงวันไว้ ยังไม่ได้ส่งให้แผนกจัดส่ง'],
            'waiting' => ['รอจัดรถ', '#b45309', 'PM ส่งให้แผนกจัดส่งแล้ว ยังไม่ได้จัดรถ / คน'],
            'rejected' => ['ไม่รับงาน', '#dc2626', 'แผนกจัดส่งส่งกลับให้ PM พร้อมเหตุผล ให้เลือกวันใหม่'],
            'booked' => ['จัดรถแล้ว', '#0f766e', 'แผนกจัดส่งจัดรถ / คนแล้ว ไปด้วยรถบริษัท'],
            'external' => ['ขนส่งภายนอก', '#7c3aed', 'แผนกจัดส่งรับงานแล้ว ใช้บริษัทขนส่ง (ชื่อนี้ใช้เป็นตัวเลือกวิธีส่งด้วย)'],
            'pickup' => ['ลูกค้ามารับเอง', '#4338ca', 'แผนกจัดส่งรับงานแล้ว ลูกค้ามารับ (ชื่อนี้ใช้เป็นตัวเลือกวิธีส่งด้วย)'],
            'departed' => ['ออกจากโรงงาน', '#0284c7', ''],
            'delivered' => ['ส่งถึงแล้ว', '#059669', 'ต้องระบุผู้รับ'],
        ],
    ];

    /** @return array<string, array<string, array{name: string, color: string, hint: string, defaultName: string, defaultColor: string}>> */
    public static function all(): array
    {
        $saved = (array) AppSetting::get('event_statuses');
        $out = [];
        foreach (self::DEFAULTS as $group => $states) {
            foreach ($states as $key => [$name, $color, $hint]) {
                $s = (array) (((array) ($saved[$group] ?? []))[$key] ?? []);
                $out[$group][$key] = ['name' => $s['name'] ?? $name, 'color' => $s['color'] ?? $color, 'hint' => $hint, 'defaultName' => $name, 'defaultColor' => $color];
            }
        }

        return $out;
    }

    /** What Planning's script needs: group => key => {name, color}. */
    public static function forPage(): array
    {
        return array_map(fn ($states) => array_map(fn ($s) => ['name' => $s['name'], 'color' => $s['color']], $states), self::all());
    }

    public static function name(string $group, string $key): string
    {
        return self::all()[$group][$key]['name'] ?? $key;
    }

    public static function color(string $group, string $key): string
    {
        return self::all()[$group][$key]['color'] ?? '#64748b';
    }

    /** @param array<string, array<string, array{name?: string, color?: string}>> $input only what differs from the default is kept */
    public static function save(array $input): void
    {
        $keep = [];
        foreach (self::DEFAULTS as $group => $states) {
            foreach ($states as $key => [$name, $color]) {
                $n = trim((string) ($input[$group][$key]['name'] ?? '')) ?: $name;
                $c = strtolower((string) ($input[$group][$key]['color'] ?? '')) ?: $color;
                if ($n !== $name || $c !== $color) {
                    $keep[$group][$key] = ['name' => $n, 'color' => $c];
                }
            }
        }
        AppSetting::put('event_statuses', $keep);
    }
}
