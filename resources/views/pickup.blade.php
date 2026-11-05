<x-account-layout title="Pengambilan cake">
    @include('pickup-information')
    <p class="text-sm leading-6">{{ \App\CheckoutDetails::COURIER_POLICY_TEXT }}</p>
</x-account-layout>
