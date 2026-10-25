<x-account-layout eyebrow="Pesanan Anda" title="Checkout">
    <a href="{{ route('catalog.show', ['product' => $card['product']->slug, 'pickup_date' => $date]) }}" class="underline underline-offset-4">Kembali ke produk</a>
    <h2 class="mt-6 text-xl font-semibold">{{ $card['product']->name }}</h2>
    <p class="mt-2 text-sm">Semua ukuran berbagi kuota. Produksi 09.00–17.00 WIB, pengambilan 09.00–21.00 WIB.</p>
    <form method="GET" action="{{ route('checkout.create', $card['product']->slug) }}" class="my-6 grid gap-3 sm:grid-cols-2 sm:items-end">
        <x-form-field name="pickup_date" label="Tanggal pengambilan" type="date" :value="$date" :min="$window['earliest']->toDateString()" :max="$window['latest']->toDateString()" required />
        <button class="rounded-lg border border-brand-taupe px-4 py-3 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2">Periksa tanggal</button>
    </form>
    <p class="mb-6 text-sm" role="status">Tersedia {{ $card['remaining'] }} dari {{ $card['quota'] }} unit untuk {{ $date }}.</p>
    @if ($card['remaining'] < 1 || $card['product']->variants->isEmpty())
        <p role="status">{{ $card['remaining'] < 1 ? 'Kuota tanggal ini penuh' : 'Belum ada ukuran yang tersedia' }}. Silakan pilih tanggal atau produk lain.</p>
    @else
        @if ($errors->any())
            <div role="alert" class="mb-6 rounded-lg border border-red-300 p-4 text-sm text-red-800 dark:text-red-200">
                <p class="font-semibold">Periksa kembali detail pesanan.</p>
                <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <section aria-labelledby="customer-heading" class="mb-6 border-y border-brand-beige py-4">
            <h2 id="customer-heading" class="font-semibold">Identitas pembeli</h2>
            <p class="mt-2 break-words">{{ $customer->name }}<br>{{ $customer->email }}<br>{{ $customer->whatsapp_number }}</p>
            <a href="{{ route('profile.edit') }}" class="mt-2 inline-block text-sm underline underline-offset-4">Perbarui profil</a>
        </section>
        <form method="POST" action="{{ route('checkout.store') }}" class="grid gap-5" data-checkout-form>
            @csrf
            <input type="hidden" name="product_id" value="{{ $card['product']->id }}">
            <input type="hidden" name="pickup_date" value="{{ $date }}">
            <input type="hidden" name="idempotency_key" value="{{ is_string(old('idempotency_key')) ? old('idempotency_key') : $idempotencyKey }}">
            <div>
                <label for="product_variant_id" class="mb-2 block text-sm font-medium">Ukuran dan harga per {{ $card['product']->sales_unit_label }}</label>
                <select id="product_variant_id" name="product_variant_id" required class="w-full rounded-lg border border-brand-beige bg-white px-3 py-3 dark:bg-brand-dark">
                    <option value="">Pilih ukuran</option>
                    @foreach ($card['product']->variants as $variant)
                        <option value="{{ $variant->id }}" data-price="{{ $variant->price }}" @selected(old('product_variant_id') == $variant->id)>{{ $variant->size_label }} — Rp{{ number_format($variant->price, 0, ',', '.') }}</option>
                    @endforeach
                </select>
            </div>
            <x-form-field name="quantity" label="Jumlah unit" type="number" value="1" min="1" :max="$card['remaining']" step="1" required />
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach (['flavor_code' => ['Rasa', config('checkout.flavors')], 'filling_code' => ['Filling', config('checkout.fillings')]] as $name => [$label, $options])
                    <div>
                        <label for="{{ $name }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
                        <select id="{{ $name }}" name="{{ $name }}" required class="w-full rounded-lg border border-brand-beige bg-white px-3 py-3 dark:bg-brand-dark">
                            <option value="">Pilih {{ strtolower($label) }}</option>
                            @foreach ($options as $code => $option)<option value="{{ $code }}" @selected(old($name) === $code)>{{ $option }}</option>@endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            <x-form-field name="cover_color" label="Warna cover" maxlength="100" required />
            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">Tulisan pada cake</legend>
                <label class="flex items-center gap-3"><input type="radio" name="inscription_choice" value="none" required @checked(old('inscription_choice') === 'none')> Tanpa tulisan</label>
                <label class="flex items-center gap-3"><input type="radio" name="inscription_choice" value="text" required @checked(old('inscription_choice') === 'text')> Gunakan tulisan</label>
                <x-form-field name="inscription" label="Isi tulisan (maksimal 10 kata / 150 karakter)" maxlength="150" />
            </fieldset>
            <x-form-field name="pickup_time" label="Jam pengambilan (09.00–21.00 WIB)" type="time" min="09:00" max="21:00" step="60" required />
            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">Metode pengambilan</legend>
                <label class="flex items-center gap-3"><input type="radio" name="pickup_method" value="self_pickup" required @checked(old('pickup_method') === 'self_pickup')> Ambil sendiri di toko</label>
                <label class="flex items-center gap-3"><input type="radio" name="pickup_method" value="online_courier" required @checked(old('pickup_method') === 'online_courier')> Kurir online</label>
            </fieldset>
            <x-form-field name="handover_note" label="Nama Kurir / Kode Booking / Catatan Penyerahan" maxlength="255" />
            <div class="rounded-lg border border-brand-beige p-4 text-sm leading-6">
                <p>{{ \App\CheckoutDetails::COURIER_POLICY_TEXT }}</p>
                <label class="mt-3 flex items-start gap-3"><input id="courier_terms_accepted" type="checkbox" name="courier_terms_accepted" value="1" class="mt-1 size-4 shrink-0"> Saya menyetujui kebijakan pengambilan oleh kurir (wajib jika memilih kurir online).</label>
            </div>
            <div class="border-t border-brand-beige pt-5">
                <p class="text-sm">Periksa pilihan, identitas, dan waktu pengambilan sebelum membuat pesanan. Harga akhir dan kuota diperiksa kembali oleh server.</p>
                <p class="mt-3 text-lg font-semibold" data-checkout-total>Pilih ukuran untuk melihat total produk.</p>
                <p class="mt-1 text-sm">Tanpa biaya pengiriman atau tambahan MDR. Aksi pembayaran dibatasi 30 menit sejak pesanan dibuat.</p>
                @include('checkout.cancellation-terms', ['pickupDate' => $date, 'instant' => $instant])
            </div>
            <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark">Buat pesanan</button>
        </form>
    @endif
</x-account-layout>
