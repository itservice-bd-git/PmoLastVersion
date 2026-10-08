<x-guest-layout>
    <h1 class="text-base font-semibold text-slate-900">ยื่นขอโครงการ / Project Request</h1>
    <p class="mt-1 text-xs text-slate-500">กรอกรายละเอียดงานที่ต้องการ ทีมงานจะพิจารณาและติดต่อกลับตามช่องทางที่ให้ไว้</p>

    <form method="POST" action="{{ route('requests.store') }}" class="mt-4 space-y-4">
        @csrf

        {{-- Honeypot: hidden from people (and assistive tech); only bots fill it. --}}
        <div class="hidden" aria-hidden="true">
            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div>
            <x-input-label for="requester_name" value="ชื่อผู้ขอ" />
            <x-text-input id="requester_name" name="requester_name" class="mt-1 block w-full" :value="old('requester_name')" required maxlength="150" />
            <x-input-error :messages="$errors->get('requester_name')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="contact" value="ช่องทางติดต่อกลับ (เบอร์โทร / อีเมล / LINE)" />
            <x-text-input id="contact" name="contact" class="mt-1 block w-full" :value="old('contact')" required maxlength="150" />
            <x-input-error :messages="$errors->get('contact')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="customer_name" value="บริษัท / ลูกค้า" />
            <x-text-input id="customer_name" name="customer_name" class="mt-1 block w-full" :value="old('customer_name')" required maxlength="200" />
            <x-input-error :messages="$errors->get('customer_name')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="title" value="ชื่องาน / โครงการ" />
            <x-text-input id="title" name="title" class="mt-1 block w-full" :value="old('title')" required maxlength="200" />
            <x-input-error :messages="$errors->get('title')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="description" value="รายละเอียด" />
            <textarea id="description" name="description" rows="4" maxlength="3000" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ old('description') }}</textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-1" />
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <x-input-label for="quantity" value="จำนวนตู้ (ถ้าทราบ)" />
                <x-text-input id="quantity" name="quantity" type="number" min="1" max="9999" class="mt-1 block w-full" :value="old('quantity')" />
                <x-input-error :messages="$errors->get('quantity')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="needed_by" value="ต้องการภายในวันที่" />
                <x-text-input id="needed_by" name="needed_by" type="date" class="mt-1 block w-full" :value="old('needed_by')" min="{{ today()->format('Y-m-d') }}" />
                <x-input-error :messages="$errors->get('needed_by')" class="mt-1" />
            </div>
        </div>

        <x-primary-button class="w-full justify-center">ส่งคำขอ</x-primary-button>
    </form>
</x-guest-layout>
