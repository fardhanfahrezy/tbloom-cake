<x-account-layout eyebrow="Area staf" title="Kelola request custom">
    <a href="{{ route('custom-requests.show', $custom) }}" class="underline underline-offset-4">Lihat detail dan referensi privat</a>
    <p class="mt-4 break-words font-semibold">{{ $custom->request_number }}</p>
    <p class="mt-2">Status: {{ $custom->status->value }} · Pengambilan {{ $custom->pickup_date->toDateString() }}</p>
    <p class="mt-3 text-sm">Catat hasil komunikasi dengan customer pada 09.00–17.00 WIB. Quote dan accepted merupakan catatan komunikasi. Pembayaran dan DP dilakukan di luar sistem.</p>
    @if ($errors->any())
        <div role="alert" class="mt-5 rounded-lg border border-red-300 p-4 text-sm"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if (in_array($custom->status->value, ['accepted', 'rejected'], true))
        <p role="status" class="mt-6">Request ini sudah terminal. Buat request baru untuk kebutuhan baru.</p>
    @else
        @php($designForm = old('form_kind') === 'design')
        <section class="mt-8 border-t border-brand-beige pt-6" aria-labelledby="status-heading">
            <h2 id="status-heading" class="text-xl font-semibold">Perbarui status komunikasi</h2>
            <form method="POST" action="{{ route('staff.custom-requests.status', $custom) }}" class="mt-4 grid gap-4">
                @csrf
                <input type="hidden" name="form_kind" value="status">
                <input type="hidden" name="expected_version" value="{{ !$designForm && is_string(old('expected_version')) ? old('expected_version') : $version }}">
                <input type="hidden" name="idempotency_key" value="{{ !$designForm && is_string(old('idempotency_key')) ? old('idempotency_key') : $transitionKey }}">
                <label for="next-status">Status berikutnya</label>
                <select id="next-status" name="status" required class="rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">
                    <option value="">Pilih status</option>
                    @foreach (match ($custom->status->value) { 'submitted' => ['contacted', 'rejected'], 'contacted' => ['quoted', 'rejected'], default => ['accepted', 'rejected'] } as $status)
                        <option value="{{ $status }}" @selected(!$designForm && old('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                <label for="status-reason">Rangkuman komunikasi / alasan (wajib)</label>
                <textarea id="status-reason" name="reason" rows="3" maxlength="2000" required class="rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">{{ !$designForm && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                <p class="text-sm">Alasan penolakan akan terlihat oleh customer. Jangan masukkan data rekening atau bukti pembayaran.</p>
                <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Simpan status</button>
            </form>
        </section>
        <section class="mt-8 border-t border-brand-beige pt-6" aria-labelledby="design-heading">
            <h2 id="design-heading" class="text-xl font-semibold">Perubahan desain</h2>
            <p class="mt-2 text-sm">Sampai akhir H-2 kalender. Menambah edible print tetap minimal H-7 pada waktu perubahan. Tanggal pengambilan tetap.</p>
            <form method="POST" action="{{ route('staff.custom-requests.design', $custom) }}" class="mt-5 grid gap-4" data-custom-design>
                @csrf @method('PATCH')
                <input type="hidden" name="form_kind" value="design">
                <input type="hidden" name="expected_version" value="{{ $designForm && is_string(old('expected_version')) ? old('expected_version') : $version }}">
                <input type="hidden" name="idempotency_key" value="{{ $designForm && is_string(old('idempotency_key')) ? old('idempotency_key') : $designKey }}">
                @foreach (['size' => ['Ukuran', 100], 'color' => ['Warna', 100]] as $field => [$label, $limit])
                    <div>
                        <label for="design-{{ $field }}" class="mb-2 block">{{ $label }}</label>
                        <input id="design-{{ $field }}" name="changes[{{ $field }}]" value="{{ is_scalar(old('changes.'.$field, $custom->{$field})) ? old('changes.'.$field, $custom->{$field}) : '' }}" maxlength="{{ $limit }}" required class="w-full rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">
                    </div>
                @endforeach
                @foreach (['flavor_code' => ['Rasa', 'flavor', config('checkout.flavors')], 'filling_code' => ['Filling', 'filling', config('checkout.fillings')]] as $field => [$label, $snapshot, $options])
                    <div>
                        <label for="design-{{ $field }}" class="mb-2 block">{{ $label }}</label>
                        <select id="design-{{ $field }}" name="changes[{{ $field }}]" required class="w-full rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">
                            <option value="{{ $custom->{$field} }}" @selected(old('changes.'.$field, $custom->{$field}) === $custom->{$field})>{{ $custom->option_labels_snapshot[$snapshot] }} (tersimpan)</option>
                            @foreach ($options as $code => $option)
                                @if ($code !== $custom->{$field})<option value="{{ $code }}" @selected(old('changes.'.$field) === $code)>{{ $option }}</option>@endif
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <fieldset class="grid gap-3">
                    <legend class="mb-2">Tulisan</legend>
                    @foreach (['none' => 'Tanpa tulisan', 'text' => 'Gunakan tulisan'] as $value => $label)
                        <label><input type="radio" name="changes[inscription_choice]" value="{{ $value }}" required @checked(old('changes.inscription_choice', $custom->inscription !== null && $custom->inscription !== '' ? 'text' : 'none') === $value)> {{ $label }}</label>
                    @endforeach
                    <label for="design-inscription">Isi tulisan (maksimal 150 karakter)</label>
                    <input id="design-inscription" name="changes[inscription]" value="{{ is_string(old('changes.inscription', $custom->inscription)) ? old('changes.inscription', $custom->inscription) : '' }}" maxlength="150" class="rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">
                </fieldset>
                @foreach (['edible_print' => 'Edible print', 'fondant' => 'Fondant'] as $field => $label)
                    <fieldset class="grid gap-3">
                        <legend class="mb-2">{{ $label }}</legend>
                        <label><input type="radio" name="changes[{{ $field }}]" value="0" required @checked((string) old('changes.'.$field, (int) $custom->{$field}) === '0')> Tidak</label>
                        <label><input type="radio" name="changes[{{ $field }}]" value="1" required @checked((string) old('changes.'.$field, (int) $custom->{$field}) === '1')> Ya</label>
                    </fieldset>
                @endforeach
                @foreach (['fondant_details' => 'Detail fondant', 'notes' => 'Catatan'] as $field => $label)
                    <div>
                        <label for="design-{{ $field }}" class="mb-2 block">{{ $label }}</label>
                        <textarea id="design-{{ $field }}" name="changes[{{ $field }}]" rows="3" maxlength="2000" class="w-full rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">{{ is_string(old('changes.'.$field, $custom->{$field})) ? old('changes.'.$field, $custom->{$field}) : '' }}</textarea>
                    </div>
                @endforeach
                <label for="design-reason">Alasan perubahan (wajib)</label>
                <textarea id="design-reason" name="reason" rows="3" maxlength="2000" required class="rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">{{ $designForm && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Simpan perubahan desain</button>
            </form>
        </section>
    @endif
</x-account-layout>
