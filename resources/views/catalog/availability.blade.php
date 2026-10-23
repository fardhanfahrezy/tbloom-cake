<p class="font-medium" role="status">{{ $card['remaining'] > 0 ? "Tersedia {$card['remaining']} dari {$card['quota']} unit" : 'Penuh' }}</p>
<p class="text-sm">Unit jual: {{ $card['product']->sales_unit_label }}</p>
