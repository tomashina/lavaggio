import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/views/front/desktop/checkout/create.blade.php', import.meta.url), 'utf8');
const checkoutFunction = (name) => {
    const match = source.match(new RegExp(`            const ${name} = function[\\s\\S]*?\\n            };`));
    assert.ok(match, `Checkout function ${name} exists`);
    return match[0].replace(/@json\(__\('[^']*'\)\)/g, '"Widget unavailable"');
};

const setup = async (fullscreen = true) => {
    const state = { closed: 0 };
    const window = {};
    const context = {
        window,
        boxNowDialog: fullscreen ? { open: true, close() { state.closed++; this.open = false; } } : null,
        shippingOptionsRoot: { querySelector: () => ({ dataset: { boxnowPartnerId: 'fixture-partner' } }) },
        document: { createElement: () => ({}), head: { appendChild(script) { script.onload(); } } },
        alert() { assert.fail('The valid widget fixture must load without an alert'); },
        boxNowLockerId: { value: '' }, boxNowLockerName: { value: '' }, boxNowAddressLine1: { value: '' },
        boxNowPostalCode: { value: '' }, boxNowCity: { value: '' },
        boxNowSelectedLabel: { textContent: '' }, boxNowSelectedAddress: { textContent: '' },
    };
    vm.runInNewContext(`
        let boxNowScriptLoaded = false;
        let boxNowWidgetPromise = null;
        ${checkoutFunction('updateBoxNowSelection')}
        ${checkoutFunction('initBoxNowWidget')}
        ${checkoutFunction('dismissBoxNowDialog')}
        globalThis.initialize = initBoxNowWidget;
        globalThis.dismiss = dismissBoxNowDialog;
    `, context);
    assert.equal(await context.initialize(), true);
    return { context, config: window._bn_map_widget_config, state };
};

test('fullscreen iframe keeps vendor selection messages enabled and closes the host after receiving a real locker', async () => {
    const { context, config, state } = await setup();
    // Vendor iframe autoclose hides its map and suppresses the marker-selection message.
    assert.equal(config.type, 'iframe');
    assert.equal(config.autoclose, false);
    assert.equal(config.autoselect, true);
    config.afterSelect({
        boxnowLockerId: 'LOCKER-123', boxnowLockerName: 'BOX NOW Dubec',
        boxnowLockerAddressLine1: 'Testna ulica 1', boxnowLockerPostalCode: '10360', boxnowLockerAddressLine2: 'Sesvete',
    });
    assert.equal(context.boxNowLockerId.value, 'LOCKER-123');
    assert.equal(context.boxNowLockerName.value, 'BOX NOW Dubec');
    assert.equal(context.boxNowAddressLine1.value, 'Testna ulica 1');
    assert.equal(context.boxNowPostalCode.value, '10360');
    assert.equal(context.boxNowCity.value, 'Sesvete');
    assert.equal(context.boxNowSelectedLabel.textContent, 'BOX NOW Dubec / LOCKER-123');
    assert.equal(context.boxNowSelectedAddress.textContent, 'Testna ulica 1, 10360 Sesvete');
    assert.equal(state.closed, 1);
});

test('vendor close notifications and unrelated messages never erase an existing locker selection', async () => {
    const { context, config, state } = await setup();
    context.boxNowLockerId.value = 'PREVIOUS-LOCKER';
    context.boxNowLockerName.value = 'Prethodni paketomat';
    config.afterSelect({ unrelated: true });
    assert.equal(state.closed, 0);
    config.afterSelect({ boxnowClose: 'yes' });
    config.afterSelect('closeIframe');
    assert.equal(state.closed, 2);
    assert.equal(context.boxNowLockerId.value, 'PREVIOUS-LOCKER');
    assert.equal(context.boxNowLockerName.value, 'Prethodni paketomat');
});

test('other storefronts retain the vendor popup and older selection field names', async () => {
    const { context, config, state } = await setup(false);
    assert.equal(config.type, 'popup');
    assert.equal(config.autoclose, true);
    config.afterSelect({ boxnowLockerId: 'LEGACY-123', boxnowLockerDescription: 'Legacy locker', addressLine2: 'Zagreb' });
    assert.equal(context.boxNowLockerId.value, 'LEGACY-123');
    assert.equal(context.boxNowLockerName.value, 'Legacy locker');
    assert.equal(context.boxNowCity.value, 'Zagreb');
    assert.equal(state.closed, 0);
});

test('Escape and cancel explicitly close an open dialog while other keys and closed dialogs are ignored', async () => {
    const { context, state } = await setup();
    let prevented = 0;
    const event = (type, key) => ({ type, key, preventDefault() { prevented++; } });
    context.dismiss(event('keydown', 'Enter'));
    assert.equal(state.closed, 0);
    assert.equal(prevented, 0);
    context.dismiss(event('keydown', 'Escape'));
    assert.equal(state.closed, 1);
    assert.equal(prevented, 1);
    context.dismiss(event('keydown', 'Escape'));
    assert.equal(state.closed, 1);
    context.boxNowDialog.open = true;
    context.dismiss(event('cancel'));
    assert.equal(state.closed, 2);
    assert.equal(prevented, 2);
});
