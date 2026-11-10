const mobileNavigation = document.querySelector('[data-mobile-navigation]');
if (mobileNavigation) {
    const summary = mobileNavigation.querySelector('summary');

    mobileNavigation.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => mobileNavigation.removeAttribute('open'));
    });

    document.addEventListener('click', (event) => {
        if (!mobileNavigation.contains(event.target)) mobileNavigation.removeAttribute('open');
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileNavigation.open) {
            mobileNavigation.removeAttribute('open');
            summary?.focus();
        }
    });
}

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

const customForm = document.querySelector('[data-custom-form]');
if (customForm) {
    const steps = [...customForm.querySelectorAll('[data-custom-step]')];
    const back = customForm.querySelector('[data-custom-back]');
    const next = customForm.querySelector('[data-custom-next]');
    const submit = customForm.querySelector('[data-custom-submit]');
    let step = Math.max(0, steps.findIndex((panel) => panel.querySelector('[aria-invalid="true"]')));
    const showStep = (index, focus = true) => {
        step = index;
        steps.forEach((panel, position) => { panel.hidden = position !== step; });
        back.hidden = step === 0;
        next.hidden = step === steps.length - 1;
        submit.hidden = step !== steps.length - 1;
        customForm.querySelector('[data-custom-progress]').textContent = `Langkah ${step + 1} dari ${steps.length}`;
        if (focus) steps[step].querySelector('legend').focus();
    };
    const updateCustom = () => {
        const inscription = customForm.elements.namedItem('inscription');
        const fondant = customForm.elements.namedItem('fondant_details');
        inscription.disabled = customForm.querySelector('[name="inscription_choice"]:checked')?.value !== 'text';
        inscription.required = !inscription.disabled;
        fondant.disabled = customForm.querySelector('[name="fondant"]:checked')?.value !== '1';
        fondant.required = !fondant.disabled;
        const date = customForm.elements.namedItem('pickup_date');
        const print = customForm.querySelector('[name="edible_print"]:checked')?.value === '1';
        date.min = print ? date.dataset.printMinimum : date.dataset.minimum;
    };
    back.addEventListener('click', () => showStep(step - 1));
    next.addEventListener('click', () => {
        const invalid = [...steps[step].querySelectorAll('input, select, textarea')].find((field) => !field.checkValidity());
        if (invalid) invalid.reportValidity();
        else showStep(step + 1);
    });
    customForm.addEventListener('change', updateCustom);
    customForm.noValidate = true;
    customForm.addEventListener('submit', (event) => {
        const invalid = [...customForm.querySelectorAll('input, select, textarea')].find((field) => !field.checkValidity());
        if (invalid) {
            event.preventDefault();
            const index = steps.findIndex((panel) => panel.contains(invalid));
            if (index >= 0) showStep(index, false);
            invalid.reportValidity();
            return;
        }
        submit.disabled = true;
        submit.textContent = 'Menyimpan request…';
    });
    window.addEventListener('pageshow', () => {
        submit.disabled = false;
        submit.textContent = 'Kirim request';
    });
    updateCustom();
    showStep(step, false);
}

const checkoutForm = document.querySelector('[data-checkout-form]');
const customDesign = document.querySelector('[data-custom-design]');
if (customDesign) {
    const updateDesign = () => {
        for (const [field, choice, value] of [['inscription', 'inscription_choice', 'text'], ['fondant_details', 'fondant', '1']]) {
            const input = customDesign.elements.namedItem(`changes[${field}]`);
            input.disabled = customDesign.querySelector(`[name="changes[${choice}]"]:checked`)?.value !== value;
            input.required = !input.disabled;
        }
    };
    customDesign.addEventListener('change', updateDesign);
    updateDesign();
}

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
        if (event.submitter) {
            event.submitter.disabled = true;
            event.submitter.textContent = 'Menyimpan pesanan…';
        }
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

const authForm = document.querySelector('[data-auth-form]');
if (authForm) {
    for (const toggle of authForm.querySelectorAll?.('[data-password-toggle]') ?? []) {
        toggle.addEventListener('click', () => {
            const inputId = toggle.getAttribute('aria-controls');
            const input = inputId ? document.getElementById?.(inputId) : null;
            if (!input) return;

            const isVisible = input.type === 'text';
            input.type = isVisible ? 'password' : 'text';
            toggle.setAttribute('aria-pressed', String(!isVisible));
            toggle.setAttribute('aria-label', isVisible ? 'Tampilkan kata sandi' : 'Sembunyikan kata sandi');

            const iconOff = toggle.querySelector('[data-password-icon-off]');
            const iconOn = toggle.querySelector('[data-password-icon-on]');
            if (iconOff && iconOn) {
                iconOff.hidden = !isVisible;
                iconOn.hidden = isVisible;
            }
        });
    }

    const submitButton = authForm.querySelector('[data-auth-submit]');
    const originalText = submitButton?.textContent;

    authForm.addEventListener('submit', () => {
        if (!submitButton) return;
        submitButton.disabled = true;
        if (submitButton.dataset?.loadingText) {
            submitButton.textContent = submitButton.dataset.loadingText;
        }
    });

    window.addEventListener('pageshow', () => {
        if (!submitButton) return;
        submitButton.disabled = false;
        if (originalText) {
            submitButton.textContent = originalText;
        }
    });
}
