<x-account-layout eyebrow="Area staf" title="Request custom">
    <form method="GET" action="{{ route('staff.custom-requests.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label for="status" class="mb-2 block text-sm">Filter status</label>
            <select id="status" name="status" class="rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">
                <option value="">Semua status</option>
                @foreach (\App\Models\CustomCakeRequestStatus::cases() as $option)<option value="{{ $option->value }}" @selected($status === $option->value)>{{ $option->value }}</option>@endforeach
            </select>
        </div>
        <button class="rounded-lg border border-brand-taupe px-5 py-3">Terapkan</button>
    </form>
    <ul class="mt-6 grid gap-4">
        @forelse ($requests as $custom)
            <li class="rounded-lg border border-brand-beige p-4">
                <a href="{{ route('staff.custom-requests.edit', $custom) }}" class="break-words font-semibold underline underline-offset-4">{{ $custom->request_number }}</a>
                <p class="mt-2 text-sm">{{ $custom->pickup_date->toDateString() }} · {{ $custom->status->value }}</p>
            </li>
        @empty
            <li role="status">Belum ada request untuk filter ini.</li>
        @endforelse
    </ul>
    <div class="mt-6">{{ $requests->links() }}</div>
</x-account-layout>
