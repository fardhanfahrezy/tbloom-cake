<x-account-layout title="Request Custom Cake">
    <p class="leading-7">Ceritakan desain cake Anda. Request ini bukan pesanan berbayar dan tidak menahan kuota Simple Cake. Penawaran dan pembayaran disepakati melalui WhatsApp di luar website.</p>
    <p class="mt-3 text-sm">Minimal H-3 kalender, atau H-7 jika menggunakan edible print, sampai D+90. Tidak ada cutoff pukul 17.00 untuk pengajuan custom.</p>
    @if ($errors->any())
        <div role="alert" class="my-5 rounded-lg border border-red-300 p-4 text-sm">
            <p>Periksa kembali field berikut. Pilih ulang file referensi setelah memperbaiki kesalahan.</p>
            <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <form method="POST" action="{{ route('custom-requests.store') }}" enctype="multipart/form-data" class="mt-6 grid gap-6" data-custom-form>
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ is_string(old('idempotency_key')) ? old('idempotency_key') : $idempotencyKey }}">
        <p data-custom-progress role="status" class="text-sm"></p>
        <fieldset data-custom-step class="grid gap-5">
            <legend tabindex="-1" class="mb-5 text-lg font-semibold">1. Jadwal dan pilihan cake</legend>
            <p class="break-words text-sm">Nama: {{ auth()->user()->name }}<br>WhatsApp: {{ auth()->user()->whatsapp_number }}. <a href="{{ route('profile.edit') }}" class="underline">Perbarui profil</a></p>
            <x-form-field name="pickup_date" label="Tanggal pengambilan" type="date" :min="$window['earliest']->toDateString()" :max="$window['latest']->toDateString()" :data-minimum="$window['earliest']->toDateString()" :data-print-minimum="$window['earliest']->addDays(4)->toDateString()" required />
            <x-form-field name="size" label="Ukuran yang diinginkan" maxlength="100" required />
            @foreach (['flavor_code' => ['Rasa', config('checkout.flavors')], 'filling_code' => ['Filling', config('checkout.fillings')]] as $name => [$label, $options])
                <div>
                    <label for="{{ $name }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
                    <select id="{{ $name }}" name="{{ $name }}" required @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror class="w-full rounded-lg border border-brand-beige bg-white px-3 py-3 dark:bg-brand-dark">
                        <option value="">Pilih {{ strtolower($label) }}</option>
                        @foreach ($options as $code => $option)<option value="{{ $code }}" @selected(old($name) === $code)>{{ $option }}</option>@endforeach
                    </select>
                    @error($name)<p id="{{ $name }}-error" role="alert" class="mt-2 text-sm">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </fieldset>
        <fieldset data-custom-step class="grid gap-5">
            <legend tabindex="-1" class="mb-5 text-lg font-semibold">2. Desain dan personalisasi</legend>
            <x-form-field name="color" label="Warna yang diinginkan" maxlength="100" required />
            <fieldset class="grid gap-3">
                <legend class="mb-2 font-medium">Tulisan pada cake</legend>
                <label><input type="radio" name="inscription_choice" value="none" required @checked(old('inscription_choice') === 'none')> Tanpa tulisan</label>
                <label><input type="radio" name="inscription_choice" value="text" required @checked(old('inscription_choice') === 'text')> Gunakan tulisan</label>
                @error('inscription_choice')<p role="alert">{{ $message }}</p>@enderror
                <x-form-field name="inscription" label="Isi tulisan (maksimal 150 karakter)" maxlength="150" />
            </fieldset>
            @foreach (['edible_print' => 'Edible print (minimal H-7 kalender)', 'fondant' => 'Fondant'] as $name => $label)
                <fieldset class="grid gap-3">
                    <legend class="mb-2 font-medium">{{ $label }}</legend>
                    <label><input type="radio" name="{{ $name }}" value="0" required @checked((string) old($name) === '0')> Tidak</label>
                    <label><input type="radio" name="{{ $name }}" value="1" required @checked((string) old($name) === '1')> Ya</label>
                    @error($name)<p role="alert">{{ $message }}</p>@enderror
                </fieldset>
            @endforeach
            <x-form-field name="fondant_details" label="Detail fondant (wajib jika dipilih)" maxlength="2000" />
        </fieldset>
        <fieldset data-custom-step class="grid gap-5">
            <legend tabindex="-1" class="mb-5 text-lg font-semibold">3. Referensi dan pengajuan</legend>
            <div>
                <label for="attachments" class="mb-2 block font-medium">Gambar referensi privat</label>
                <input id="attachments" name="attachments[]" type="file" multiple required accept="image/jpeg,image/png,image/webp" aria-describedby="attachments-help attachments-errors" @if ($errors->has('attachments') || $errors->has('attachments.*')) aria-invalid="true" @endif class="w-full rounded-lg border border-brand-beige p-3">
                <p id="attachments-help" class="mt-2 text-sm">Satu sampai tiga gambar JPEG, PNG, atau WebP. Maksimal 5 MB per file. Pilihan file tetap tersimpan ketika berpindah langkah.</p>
                <div id="attachments-errors">
                    @foreach (array_merge($errors->get('attachments'), $errors->get('attachments.*')) as $messages)
                        @foreach ((array) $messages as $message)<p role="alert">{{ $message }}</p>@endforeach
                    @endforeach
                </div>
            </div>
            <div>
                <label for="notes" class="mb-2 block font-medium">Catatan opsional</label>
                <textarea id="notes" name="notes" maxlength="2000" rows="4" @error('notes') aria-invalid="true" aria-describedby="notes-error" @enderror class="w-full rounded-lg border border-brand-beige bg-white px-3 py-3 dark:bg-brand-dark">{{ is_string(old('notes')) ? old('notes') : '' }}</textarea>
                @error('notes')<p id="notes-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <p class="text-sm">Request tersimpan sebelum notifikasi diproses. Status di website tetap tersedia jika WhatsApp gagal. Staf menindaklanjuti pada jam 09.00–17.00 WIB; belum ada SLA penanganan.</p>
        </fieldset>
        <div class="flex flex-wrap gap-3">
            <button type="button" data-custom-back hidden class="rounded-lg border border-brand-taupe px-5 py-3 font-semibold">Kembali</button>
            <button type="button" data-custom-next hidden class="rounded-lg border border-brand-taupe px-5 py-3 font-semibold">Lanjut</button>
            <button type="submit" data-custom-submit class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-4 dark:bg-brand-beige dark:text-brand-dark">Kirim request</button>
        </div>
    </form>
</x-account-layout>
