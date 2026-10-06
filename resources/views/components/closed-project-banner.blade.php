@props(['project'])

@if ($project->isLocked())
    <div class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm flex items-start gap-2">
        <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4" /></svg>
        <p>โครงการนี้ปิดแล้ว (สถานะ {{ \App\Models\Project::$statuses[$project->status] ?? $project->status }}) — แก้ไขงาน ตู้ Checklist และไฟล์แนบไม่ได้ แต่ยังเขียนคอมเมนต์ได้ หากต้องการแก้ไขให้เปลี่ยนสถานะโครงการกลับก่อน</p>
    </div>
@endif
