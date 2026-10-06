<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\ProjectLock;
use App\Models\Project;
use App\Models\ProjectAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectAttachmentController extends Controller
{
    /**
     * Same approach as CabinetAttachmentController - files move straight
     * into public/uploads/project-attachments, no storage:link needed.
     */
    private const UPLOAD_DIR = 'uploads/project-attachments';

    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png,dwg,dxf';

    public function store(Request $request, Project $project)
    {
        ProjectLock::assertOpen($project->id);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:'.self::ALLOWED_MIMES],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $data['file'];
        $destination = public_path(self::UPLOAD_DIR);

        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $htaccess = $destination.'/.htaccess';
        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
        }

        // Capture metadata before move() - it relocates the underlying temp
        // file, after which the UploadedFile object can no longer stat it.
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType();
        $size = $file->getSize();
        $storedName = Str::random(40).'.'.$file->getClientOriginalExtension();

        $file->move($destination, $storedName);

        $attachment = $project->attachments()->create([
            'original_name' => $originalName,
            'path' => $storedName,
            'mime_type' => $mimeType,
            'size' => $size,
            'description' => $data['description'] ?? null,
            'uploaded_by' => auth()->id(),
        ]);

        ActivityLog::record(
            $project->id,
            auth()->id(),
            $project,
            'attachment_uploaded',
            "อัปโหลดไฟล์ \"{$attachment->original_name}\" ให้โครงการ {$project->project_no}".($attachment->description ? " ({$attachment->description})" : '')
        );

        return back()->with('success', 'อัปโหลดไฟล์เรียบร้อยแล้ว');
    }

    public function destroy(ProjectAttachment $attachment)
    {
        $project = $attachment->project;
        ProjectLock::assertOpen($project->id);
        $filePath = public_path(self::UPLOAD_DIR.'/'.$attachment->path);

        if (is_file($filePath)) {
            unlink($filePath);
        }

        $attachment->delete();

        ActivityLog::record(
            $project->id,
            auth()->id(),
            $project,
            'attachment_deleted',
            "ลบไฟล์ \"{$attachment->original_name}\" จากโครงการ {$project->project_no}"
        );

        return back()->with('success', 'ลบไฟล์เรียบร้อยแล้ว');
    }
}
