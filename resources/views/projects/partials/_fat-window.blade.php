{{-- Planning's project window › "ลง / แก้วัน FAT" (ProjectFatController@form): the project page's FAT window on its own,
     with $f (ProjectFat::formData) from the controller. --}}
@php
    $fatCabinets = $project->cabinets()->orderBy('sequence')->orderBy('id')->get(['id', 'mo_no', 'cabinet_name']);
    $cabName = fn ($c) => $c->mo_no ?: $c->cabinet_name;
@endphp
<div id="project-fat" x-data="{ due: @js($project->due_date?->format('Y-m-d')), f: @js($f) }">
    @include('projects.partials._fat-form')
</div>
