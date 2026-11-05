import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');

function page(seconds, withButton = true) {
    let elapsed = 0;
    let tick;
    let reloads = 0;
    const listeners = new Map();
    const text = { textContent: '' };
    const notice = { textContent: '' };
    const button = withButton ? { disabled: false } : null;
    const countdown = {
        dataset: { remainingSeconds: String(seconds) },
        querySelector: (selector) => ({
            '[data-countdown-text]': text,
            '[data-countdown-notice]': notice,
            '[data-payment-submit]': button,
        })[selector],
    };
    const document = {
        hidden: false,
        querySelector: (selector) => selector === '[data-order-countdown]' ? countdown : null,
        addEventListener: (name, handler) => listeners.set(name, handler),
    };
    runInNewContext(source, {
        document,
        performance: { now: () => elapsed },
        window: { setInterval: (handler) => { tick = handler; }, location: { reload: () => { reloads++; } } },
    });

    return {
        text, notice, button,
        advance(milliseconds) { elapsed += milliseconds; tick(); },
        show() { document.hidden = false; listeners.get('visibilitychange')(); },
        reloads: () => reloads,
    };
}

test('absolute countdown disables the payment action at zero and stays at zero', () => {
    const view = page(1800);
    assert.equal(view.text.textContent, '30:00');
    assert.equal(view.button.disabled, false);
    view.advance(1800000);
    assert.equal(view.text.textContent, '00:00');
    assert.equal(view.button.disabled, true);
    assert.match(view.notice.textContent, /kuota tetap ditahan/);
    view.advance(60000);
    assert.equal(view.text.textContent, '00:00');
});

test('returning to the tab reloads the server deadline without opening payment', () => {
    const view = page(30);
    view.show();
    assert.equal(view.reloads(), 1);
    assert.equal(view.button.disabled, false);
});

test('an expired order without a payment button still renders its hold notice', () => {
    const view = page(0, false);
    assert.equal(view.text.textContent, '00:00');
    assert.match(view.notice.textContent, /Status menunggu kepastian/);
});
