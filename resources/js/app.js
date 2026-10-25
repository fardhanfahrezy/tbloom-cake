const catalogPage = document.querySelector('[data-catalog-page]');

if (catalogPage) {
    document.querySelector('[data-catalog-filters]')?.addEventListener('change', (event) => {
        if (event.target.matches('input[type="date"], select')) {
            event.currentTarget.requestSubmit();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            window.location.reload();
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });
}

const checkoutForm = document.querySelector('[data-checkout-form]');
if (checkoutForm) {
    const variant = checkoutForm.elements.namedItem('product_variant_id');
    const quantity = checkoutForm.elements.namedItem('quantity');
    const inscription = checkoutForm.elements.namedItem('inscription');
    const consent = checkoutForm.elements.namedItem('courier_terms_accepted');
    const updateCheckout = () => {
        const price = variant.selectedOptions[0]?.dataset.price;
        const total = checkoutForm.querySelector('[data-checkout-total]');
        total.textContent = price && /^[1-9][0-9]*$/.test(quantity.value)
            ? `Total produk Rp${(BigInt(price) * BigInt(quantity.value)).toLocaleString('id-ID')}`
            : 'Pilih ukuran dan jumlah untuk melihat total produk.';
        const hasText = checkoutForm.querySelector('[name="inscription_choice"]:checked')?.value === 'text';
        inscription.disabled = !hasText;
        inscription.required = hasText;
        consent.required = checkoutForm.querySelector('[name="pickup_method"]:checked')?.value === 'online_courier';
    };
    checkoutForm.addEventListener('input', updateCheckout);
    checkoutForm.addEventListener('change', updateCheckout);
    checkoutForm.addEventListener('submit', (event) => {
        event.submitter.disabled = true;
        event.submitter.textContent = 'Menyimpan pesanan…';
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });
    updateCheckout();
}

const countdown = document.querySelector('[data-order-countdown]');
if (countdown) {
    const initialSeconds = Number(countdown.dataset.remainingSeconds);
    const startedAt = performance.now();
    const updateCountdown = () => {
        const remaining = Math.max(0, initialSeconds - Math.floor((performance.now() - startedAt) / 1000));
        countdown.querySelector('[data-countdown-text]').textContent = `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
        if (remaining === 0) {
            countdown.querySelector('[data-countdown-notice]').textContent = 'Batas aksi bayar telah lewat. Status menunggu kepastian; kuota tetap ditahan.';
            const paymentButton = countdown.querySelector('[data-payment-submit]');
            if (paymentButton) paymentButton.disabled = true;
        }
    };
    updateCountdown();
    window.setInterval(updateCountdown, 1000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) window.location.reload();
    });
}
