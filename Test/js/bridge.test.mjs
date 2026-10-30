/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * The Magewire bridge of templates/checkout/payment/affirm-gateway-js.phtml against stubs of both $wire proxies:
 * Magewire 1 (Livewire 2: find() throws for an absent component, unknown properties become server-call functions,
 * actions resolve null) and Magewire 3 (find() returns undefined, actions resolve their return value).
 * The copy below must stay identical to the template's (between the bridge markers, indentation aside).
 *
 * Run from the package root: node --test Test/js/*.test.mjs
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {describe, it} from 'node:test';

/* bridge:start */
// Magewire 1 (Livewire 2) and Magewire 3 both return the component's $wire proxy from Magewire.find(): actions
// are called as methods and public properties read as properties. Magewire 1 throws for a component that is
// not on the page and answers an unknown property with a server-call function, which reads never call.
// Hyvä Checkout 3 adds hyvaCheckout.misc.call(): a plain Magewire 3 action call never settles when its request
// fails, misc.call() rejects instead, so Place Order cannot hang. Older releases have no misc.call().
const createWireBridge = (getMagewire, componentId, getMisc = () => null) => {
    const find = () => {
        try {
            const magewire = getMagewire();
            return (magewire && typeof magewire.find === 'function' && magewire.find(componentId)) || null;
        } catch (e) {
            return null;
        }
    };
    const read = (wire, property) => {
        let value = wire[property];
        if (typeof value === 'function') {
            value = typeof wire.get === 'function' ? wire.get(property) : undefined;
        }
        return value === undefined || typeof value === 'function' ? null : value;
    };
    const call = async (method) => {
        const wire = find();
        if (!wire || typeof wire[method] !== 'function') {
            throw new Error(`Magewire component "${componentId}" is not available.`);
        }
        const misc = getMisc();
        await (misc && typeof misc.call === 'function' ? misc.call(wire, method) : wire[method]());
        return (property) => read(wire, property);
    };
    return {find, read, call};
};
/* bridge:end */

const COMPONENT_ID = 'checkout.payment.method.affirm_gateway';
const TEMPLATE = new URL('../../src/view/frontend/templates/checkout/payment/affirm-gateway-js.phtml', import.meta.url);

/** The code between the bridge markers, without the indentation shared by its lines. */
const bridgeSource = (text) => {
    const start = text.indexOf('/* bridge:start */');
    const end = text.indexOf('/* bridge:end */');
    assert.ok(start !== -1 && end > start, 'bridge markers present');
    const lines = text.slice(text.lastIndexOf('\n', start) + 1, end + '/* bridge:end */'.length).split('\n');
    const indent = Math.min(...lines.filter((line) => line.trim() !== '').map((line) => line.match(/^ */)[0].length));
    return lines.map((line) => line.slice(Math.min(indent, line.match(/^ */)[0].length))).join('\n');
};

/** Server stand-in: prepareCheckout() fills the public properties like the PHP component does. */
const serverActions = {
    prepareCheckout(state) {
        state.checkout = {order_id: '000000123', total: 132025, merchant: {public_api_key: 'PUBLIC'}};
        state.attemptId = 'a'.repeat(64);
        state.errorMessage = null;
        state.mode = 'redirect';
        return undefined;
    },
    prepareInline(state) {
        state.inlineVersion += 1;
        return undefined;
    },
};

const initialState = () => ({
    checkout: null,
    attemptId: null,
    errorMessage: null,
    inlineCheckout: null,
    inlineVersion: 0,
    mode: 'modal',
    edu: false,
});

/** Magewire 1.13 (Livewire 2): the Proxy getter of livewire.js's Component#$wire. */
const magewire1 = (registered = true) => {
    const component = {
        data: initialState(),
        calls: [],
        get(name) {
            return this.data[name];
        },
        set(name, value) {
            this.data[name] = value;
        },
        async call(method, ...params) {
            this.calls.push(method);
            if (!serverActions[method]) {
                throw new Error(`Method ${method} does not exist`);
            }
            serverActions[method](this.data, ...params);
            return null;
        },
    };
    const $wire = new Proxy({}, {
        get(object, property) {
            if (property === '__instance') {
                return component;
            }
            if (typeof property === 'string' && /^emit/.test(property)) {
                return () => undefined;
            }
            if (['get', 'set', 'sync', 'call', 'on', 'upload', 'uploadMultiple', 'removeUpload'].includes(property)) {
                return (...args) => component[property](...args);
            }
            const value = component.get(property);
            return value === undefined ? (...args) => component.call(property, ...args) : value;
        },
    });
    const componentsById = registered ? {[COMPONENT_ID]: {$wire}} : {};
    return {
        component,
        Magewire: {
            find(id) {
                return componentsById[id].$wire;
            },
        },
    };
};

/** Magewire 3.7: generateWireObject() (aliases first, then state, else a server-call function). */
const magewire3 = (registered = true) => {
    const state = initialState();
    const calls = [];
    const aliases = {get: (name) => state[name], set: (name, value) => {
        state[name] = value;
    }};
    const $wire = new Proxy({}, {
        get(target, property) {
            if (property in aliases) {
                return aliases[property];
            }
            if (property in state) {
                return state[property];
            }
            if (property === 'then') {
                return undefined;
            }
            return async (...params) => {
                calls.push(property);
                if (!serverActions[property]) {
                    throw new Error(`Method ${String(property)} does not exist`);
                }
                return serverActions[property](state, ...params) ?? null;
            };
        },
    });
    const components = registered ? {[COMPONENT_ID]: {$wire}} : {};
    return {
        calls,
        state,
        Magewire: {
            find(id) {
                const component = components[id];
                return component && component.$wire;
            },
        },
    };
};

describe('bridge source', () => {
    it('is identical in the template and in this test', () => {
        const self = readFileSync(new URL(import.meta.url), 'utf8');
        assert.equal(bridgeSource(readFileSync(TEMPLATE, 'utf8')), bridgeSource(self));
    });
});

for (const [name, create, callsOf] of [
    ['Magewire 1', magewire1, (stub) => stub.component.calls],
    ['Magewire 3', magewire3, (stub) => stub.calls],
]) {
    describe(`bridge on ${name}`, () => {
        it('finds the component', () => {
            const stub = create();
            const bridge = createWireBridge(() => stub.Magewire, COMPONENT_ID);
            assert.ok(bridge.find());
        });

        it('returns null for a component that is not on the page', () => {
            const stub = create(false);
            assert.equal(createWireBridge(() => stub.Magewire, COMPONENT_ID).find(), null);
        });

        it('returns null before Magewire is loaded', () => {
            assert.equal(createWireBridge(() => undefined, COMPONENT_ID).find(), null);
            assert.equal(createWireBridge(() => ({}), COMPONENT_ID).find(), null);
            assert.equal(createWireBridge(() => {
                throw new ReferenceError('Magewire is not defined');
            }, COMPONENT_ID).find(), null);
        });

        it('calls the action once and reads the properties it set', async () => {
            const stub = create();
            const bridge = createWireBridge(() => stub.Magewire, COMPONENT_ID);

            const read = await bridge.call('prepareCheckout');

            assert.deepEqual(callsOf(stub), ['prepareCheckout']);
            assert.deepEqual(read('checkout'), {
                order_id: '000000123',
                total: 132025,
                merchant: {public_api_key: 'PUBLIC'},
            });
            assert.equal(read('attemptId'), 'a'.repeat(64));
            assert.equal(read('errorMessage'), null);
            assert.equal(read('mode'), 'redirect');
        });

        it('reads null and false values as they are', () => {
            const stub = create();
            const bridge = createWireBridge(() => stub.Magewire, COMPONENT_ID);
            const wire = bridge.find();

            assert.equal(bridge.read(wire, 'inlineCheckout'), null);
            assert.equal(bridge.read(wire, 'edu'), false);
            assert.equal(bridge.read(wire, 'inlineVersion'), 0);
        });

        it('never turns a read of an unknown property into a server call', () => {
            const stub = create();
            const bridge = createWireBridge(() => stub.Magewire, COMPONENT_ID);

            assert.equal(bridge.read(bridge.find(), 'error'), null);
            assert.equal(bridge.read(bridge.find(), 'data'), null);
            assert.deepEqual(callsOf(stub), []);
        });

        it('rejects when the component is missing', async () => {
            const stub = create(false);
            await assert.rejects(
                createWireBridge(() => stub.Magewire, COMPONENT_ID).call('prepareCheckout'),
                /is not available/
            );
        });

        it('passes a failed request on to the caller', async () => {
            const stub = create();
            await assert.rejects(createWireBridge(() => stub.Magewire, COMPONENT_ID).call('missingAction'));
        });
    });
}

describe('bridge reads', () => {
    it('fall back to get() when a proxy answers a property with a function', () => {
        const wire = {checkout: () => undefined, get: (name) => (name === 'checkout' ? {total: 1} : undefined)};
        assert.deepEqual(createWireBridge(() => null, COMPONENT_ID).read(wire, 'checkout'), {total: 1});
    });

    it('return null when neither the property nor get() has a value', () => {
        const wire = {attemptId: () => undefined};
        assert.equal(createWireBridge(() => null, COMPONENT_ID).read(wire, 'attemptId'), null);
    });
});

describe('bridge with hyvaCheckout.misc.call() (Hyvä Checkout 3)', () => {
    it('sends the action through misc.call() and reads the properties it set', async () => {
        const stub = magewire3();
        const sent = [];
        const misc = {call: (wire, method) => {
            sent.push(method);
            return wire[method]();
        }};
        const bridge = createWireBridge(() => stub.Magewire, COMPONENT_ID, () => misc);

        const read = await bridge.call('prepareCheckout');

        assert.deepEqual(sent, ['prepareCheckout']);
        assert.equal(read('attemptId'), 'a'.repeat(64));
    });

    it('rejects when misc.call() rejects for a failed request', async () => {
        const stub = magewire3();
        const misc = {call: () => Promise.reject(new Error('The request of prepareCheckout() failed.'))};
        const bridge = createWireBridge(() => stub.Magewire, COMPONENT_ID, () => misc);

        await assert.rejects(bridge.call('prepareCheckout'), /failed/);
    });

    it('calls the action directly when misc has no call() (Hyvä Checkout 1.3)', async () => {
        const stub = magewire1();
        const bridge = createWireBridge(() => stub.Magewire, COMPONENT_ID, () => ({mergeObjects: () => ({})}));

        await bridge.call('prepareCheckout');

        assert.deepEqual(stub.component.calls, ['prepareCheckout']);
    });
});
