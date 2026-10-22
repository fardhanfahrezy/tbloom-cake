<x-account-layout title="Kelola kuota produk">
    <p class="mb-4 font-medium">{{ $product->name }}</p>
    <a href="{{ route('staff.products.edit', $product) }}" class="mb-6 inline-block underline underline-offset-4">Kembali ke produk</a>
    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-lg border border-red-500 p-4 text-sm text-red-700 dark:text-red-300"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <section aria-labelledby="default-title">
        <h2 id="default-title" class="text-xl font-semibold">Kuota default</h2>
        <p class="mb-5 mt-2 text-sm">Berlaku untuk tanggal yang belum ditetapkan kapasitasnya. Pengaturan tanggal yang sudah ada tetap mengikuti nilainya sendiri.</p>
        <form method="POST" action="{{ route('staff.products.quota.default', $product) }}" class="grid gap-4">
            @csrf @method('PATCH')
            <x-form-field name="default_daily_quota" label="Kuota default (unit jual)" type="number" :value="$product->default_daily_quota" required min="0" max="4294967295" step="1" />
            <x-form-field name="default_reason" label="Alasan perubahan default" required minlength="2" maxlength="1000" />
            <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Simpan kuota default</button>
        </form>
    </section>
    <section class="mt-10 border-t border-brand-beige pt-8" aria-labelledby="date-title">
        <h2 id="date-title" class="mb-5 text-xl font-semibold">Kapasitas per tanggal</h2>
        <form method="GET" action="{{ route('staff.products.quota.edit', $product) }}" class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2"><label for="date" class="font-medium">Tanggal kapasitas (WIB)</label><input id="date" name="date" type="date" value="{{ $date }}" min="1000-01-01" max="9999-12-31" required class="min-w-0 rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark"></div>
            <button class="self-end rounded-lg border border-brand-burgundy px-5 py-3 dark:border-brand-beige">Lihat kapasitas</button>
        </form>
        <p class="mt-6 font-semibold">Tanggal {{ $date }}: {{ $capacity['allocated'] }} unit dialokasikan dari kapasitas {{ $capacity['quota'] }}.</p>
        <p class="mb-5 mt-2 text-sm">Seluruh ukuran berbagi kuota ini. Kuota nol menutup tanggal bila tidak ada alokasi yang ditahan. Deadline pembayaran saja tidak melepas unit.</p>
        <form method="POST" action="{{ route('staff.products.quota.daily', $product) }}" class="grid gap-4">
            @csrf @method('PUT')
            <input type="hidden" name="availability_date" value="{{ $date }}">
            <x-form-field name="quota_limit" label="Kapasitas tanggal ini (unit jual)" type="number" :value="$capacity['quota']" required min="0" max="4294967295" step="1" />
            <x-form-field name="reason" label="Alasan perubahan kapasitas" required minlength="2" maxlength="1000" />
            <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Simpan kapasitas tanggal</button>
        </form>
    </section>
</x-account-layout>
