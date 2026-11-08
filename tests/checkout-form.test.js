import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');

function checkoutPage() {
    const listeners = new Map();
    const total = { textContent: '' };
    const variant = { selectedOptions: [{ dataset: { price: '120000' } }] };
    const quantity = { value: '2' };
    const inscription = { disabled: false, required: true };
    const consent = { required: false };
    const fields = { product_variant_id: variant, quantity, inscription, courier_terms_accepted: consent };
    const form = {
        elements: { namedItem: (key) => fields[key] },
        querySelector: (selector) => selector === '[data-checkout-total]' ? total : null,
        addEventListener: (event, handler) => listeners.set(event, handler),
    };
    const document = {
        querySelector: (selector) => selector === '[data-checkout-form]' ? form : null,
        addEventListener: () => {},
    };
    runInNewContext(source, { document, window: { addEventListener: () => {} } });
    return { form, total, submit: (submitter) => listeners.get('submit')({ submitter }) };
}

test('checkout renders an exact product total and disables clicked submit button', () => {
    const page = checkoutPage();
    assert.equal(page.total.textContent, 'Total produk Rp240.000');
    const submitter = { disabled: false, textContent: 'Buat pesanan' };
    page.submit(submitter);
    assert.equal(submitter.disabled, true);
    assert.equal(submitter.textContent, 'Menyimpan pesanan…');
});

test('checkout accepts a programmatic submit event with no submitter', () => {
    const page = checkoutPage();
    assert.doesNotThrow(() => page.submit(null));
});
