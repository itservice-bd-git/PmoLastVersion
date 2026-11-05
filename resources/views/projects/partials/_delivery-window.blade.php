{{-- Planning's project window › "นัด / แก้การส่ง" (ProjectDeliveryController@form): the project page's delivery window on
     its own, with $f / $cabinets / $vehicles from the controller. --}}
<div id="project-delivery" x-data="{ f: @js($f), cabs: @js($cabinets), vehicles: @js($vehicles) }">
    @include('projects.partials._delivery-form')
</div>
