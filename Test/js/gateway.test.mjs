/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Runs the script of templates/checkout/payment/affirm-gateway-js.phtml against fakes of Hyvä Checkout's API,
 * Magewire, the window.hyvaAffirm loader, affirm.js, Alpine and the DOM: the Place Order flows (modal and
 * redirect) and the inline widget's coalescing and stale-reply handling.
 *
 * Run from the package root: node --test Test/js/*.test.mjs
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {describe, it} from 'node:test';
import vm from 'node:vm';

const COMPONENT_ID = 'checkout.payment.method.affirm_gateway';
const CONTAINER_ID = 'hyva-affirm-inline-checkout';
const ATTEMPT_ID = 'a'.repeat(64);
const MESSAGES = {
    unavailable: 'unavailable',
    sdk: 'sdk',
    cancelled: 'cancelled',
    failed: 'failed',
    validation: 'validation',
    storage: 'storage',
};
const CONFIG = {
    code: 'affirm_gateway',
    componentId: COMPONENT_ID,
    containerId: CONTAINER_ID,
    storage: {group: 'payment', token: 'checkout_token', attempt: 'attempt_id'},
    messages: MESSAGES,
};

const TEMPLATE = new URL('../../src/view/frontend/templates/checkout/payment/affirm-gateway-js.phtml', import.meta.url);
const SCRIPT = (() => {
    const match = readFileSync(TEMPLATE, 'utf8').match(/<script>\n([\s\S]*?)<\/script>/);
    assert.ok(match, 'the template has an inline script');
    const placeholder = '<?= /* @noEscape */ $json ?>';
    assert.ok(match[1].includes(placeholder));
    return match[1].replace(placeholder, JSON.stringify(CONFIG));
})();

/** Deep equality across the vm realm boundary (objects made in the page script have its prototypes). */
const same = (actual, expected, message) => assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected, message);

/** Lets queued promise callbacks run. */
const settle = async () => {
    for (let i = 0; i < 20; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

const checkoutObject = (mode) => ({order_id: '000000123', total: 132025, metadata: {mode}});

/** affirm.js double recording what the script asks of it. */
const fakeAffirm = ({withOpen = true} = {}) => {
    const calls = [];
    const affirm = {calls, options: null};
    const checkout = (object) => calls.push(['checkout', object]);
    checkout.reset = () => calls.push(['reset']);
    if (withOpen) {
        checkout.open = (options) => {
            calls.push(['open']);
            affirm.options = options;
        };
    }
    checkout.post = (options) => {
        calls.push(['post']);
        affirm.options = options;
    };
    checkout.inline = (options) => calls.push(['inline', options]);
    affirm.checkout = checkout;
    return affirm;
};

/** Magewire 3 style $wire with a server stand-in for the component's actions. */
const fakeMagewire = (actions) => {
    const state = {checkout: null, attemptId: null, errorMessage: null, mode: 'modal', inlineVersion: 0};
    const calls = [];
    const $wire = new Proxy({}, {
        get(target, property) {
            if (property === 'get') {
                return (name) => state[name];
            }
            if (property in state) {
                return state[property];
            }
            return async () => {
                calls.push(property);
                if (actions[property]) {
                    await actions[property](state);
                }
                return null;
            };
        },
    });
    return {state, calls, Magewire: {find: (id) => (id === COMPONENT_ID ? $wire : undefined)}};
};

const prepared = (mode) => (state) => {
    state.checkout = checkoutObject(mode);
    state.attemptId = ATTEMPT_ID;
    state.errorMessage = null;
    state.mode = mode;
};

/** Loads the script into a fresh page with fake globals. */
const page = ({actions = {}, affirm = fakeAffirm(), loadFails = false, storageFails = false} = {}) => {
    const listeners = {};
    const notices = [];
    const storage = [];
    const observers = [];
    let timers = [];
    let now = 0;
    let registered = null;

    const window = {
        Magewire: null,
        hyvaAffirm: {
            version: 1,
            load: () => (loadFails ? Promise.reject(new Error('blocked')) : Promise.resolve(affirm)),
        },
        Alpine: undefined,
        addEventListener(type, callback, options) {
            (listeners[type] ||= []).push({callback, once: Boolean(options && options.once)});
        },
        removeEventListener(type, callback) {
            listeners[type] = (listeners[type] || []).filter((listener) => listener.callback !== callback);
        },
        dispatch(type, event = {}) {
            for (const listener of [...(listeners[type] || [])]) {
                if (listener.once) {
                    window.removeEventListener(type, listener.callback);
                }
                listener.callback(event);
            }
        },
    };
    const magewire = fakeMagewire(actions);
    window.Magewire = magewire.Magewire;

    const button = {isConnected: true, disabled: true, focused: 0, focus() {
        this.focused++;
        document.activeElement = this;
    }};
    const container = {cleared: 0, replaceChildren() {
        this.cleared++;
    }};
    const root = {
        dataset: {edu: '1', inlineVersion: '0', inlineCheckout: ''},
        isConnected: true,
        contains: (element) => element === container,
    };
    const document = {
        body: {},
        activeElement: button,
        getElementById: (id) => (id === CONTAINER_ID ? container : null),
    };

    class FakeMutationObserver {
        constructor(callback) {
            this.callback = callback;
            this.connected = false;
            observers.push(this);
        }

        observe(target, options) {
            assert.equal(target, root);
            same(options.attributeFilter, ['data-inline-version']);
            this.connected = true;
        }

        disconnect() {
            this.connected = false;
        }
    }

    const hyvaCheckout = {
        payment: {
            registerMethod({code, method}) {
                registered = {code, method};
                return method;
            },
            dispatchExceptionMessage: (text, type) => notices.push([type, text]),
            dispatchSuccessMessage: (text) => notices.push(['clear', text]),
        },
        storage: {
            data: {payment: {}},
            setValue(key, value, group) {
                if (storageFails && value !== null) {
                    return Promise.reject(new Error('Browser Storage is unavailable.'));
                }
                storage.push([key, value, group]);
                this.data[group][key] = value;
                return Promise.resolve(value);
            },
        },
    };

    const context = vm.createContext({
        window,
        document,
        hyvaCheckout,
        MutationObserver: FakeMutationObserver,
        setTimeout: (callback, delay = 0) => {
            const id = timers.length + 1 + now;
            timers.push({id, callback, at: now + delay});
            return id;
        },
        clearTimeout: (id) => {
            timers = timers.filter((timer) => timer.id !== id);
        },
    });
    vm.runInContext(SCRIPT, context);

    const alpine = {components: {}, data(name, factory) {
        this.components[name] = factory;
    }};
    window.Alpine = alpine;
    window.dispatch('alpine:init');

    return {
        window,
        document,
        hyvaCheckout,
        affirm,
        magewire,
        notices,
        storage,
        observers,
        button,
        root,
        container,
        method: () => registered.method,
        code: () => registered.code,
        /** Runs the timers due within the next `ms` milliseconds, in order. */
        advance(ms) {
            const until = now + ms;
            for (;;) {
                const due = timers.filter((timer) => timer.at <= until).sort((a, b) => a.at - b.at)[0];
                if (!due) {
                    break;
                }
                timers = timers.filter((timer) => timer !== due);
                now = due.at;
                due.callback();
            }
            now = until;
        },
        /** An Alpine instance of hyvaCheckoutAffirmGateway on the method root. */
        mount() {
            const data = alpine.components.hyvaCheckoutAffirmGateway();
            data.$el = root;
            data.init();
            return data;
        },
        /** What Magewire's morph does after a rebuild: new attributes, then the mutation records. */
        morph(version, checkout) {
            root.dataset.inlineVersion = String(version);
            root.dataset.inlineCheckout = checkout === null ? '' : JSON.stringify(checkout);
            observers.filter((observer) => observer.connected).forEach((observer) => observer.callback([]));
        },
    };
};

describe('registration', () => {
    it('registers affirm_gateway and the Alpine component', () => {
        const p = page();
        assert.equal(p.code(), 'affirm_gateway');
        for (const name of ['initialize', 'uninitialize', 'placeOrder', 'handleException']) {
            assert.equal(typeof p.method()[name], 'function');
        }
        assert.equal(typeof p.mount().destroy, 'function');
    });

    it('preloads affirm.js when Affirm is selected', async () => {
        let loads = 0;
        const p = page();
        p.window.hyvaAffirm.load = () => {
            loads++;
            return Promise.resolve(p.affirm);
        };
        await p.method().initialize();
        assert.equal(loads, 1);
    });
});

describe('modal Place Order', () => {
    it('hands the token and attempt to Hyvä and places through fallback()', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        let fallbacks = 0;
        const fallback = async () => {
            fallbacks++;
            assert.equal(p.window.Magewire.find(COMPONENT_ID).get('attemptId'), ATTEMPT_ID);
        };

        const placing = p.method().placeOrder({fallback});
        await settle();
        assert.deepEqual(p.affirm.calls.map((call) => call[0]), ['reset', 'checkout', 'open']);
        same(p.affirm.calls[1][1], checkoutObject('modal'));
        p.affirm.options.onSuccess({checkout_token: 'TOKEN_1'});
        await placing;

        assert.deepEqual(p.magewire.calls, ['prepareCheckout']);
        assert.equal(fallbacks, 1);
        assert.deepEqual(p.storage.slice(-2), [
            ['checkout_token', 'TOKEN_1', 'payment'],
            ['attempt_id', ATTEMPT_ID, 'payment'],
        ]);
        assert.deepEqual(p.notices, [['clear', '']]);
    });

    it('accepts checkout_id when Affirm sends no checkout_token', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        const placing = p.method().placeOrder({fallback: async () => {}});
        await settle();
        p.affirm.options.onSuccess({checkout_id: 'ID_2'});
        await placing;
        assert.deepEqual(p.storage.find((entry) => entry[0] === 'checkout_token' && entry[1]), [
            'checkout_token', 'ID_2', 'payment',
        ]);
    });

    it('rejects with a warning when the shopper closes Affirm, without placing', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        let fallbacks = 0;
        const placing = p.method().placeOrder({fallback: async () => {
            fallbacks++;
        }});
        await settle();
        p.affirm.options.onFail({reason: 'canceled'});
        await assert.rejects(placing, /cancelled/);
        assert.equal(fallbacks, 0);
        assert.deepEqual(p.notices.at(-1), ['warning', MESSAGES.cancelled]);
        assert.equal(p.storage.filter((entry) => entry[1] !== null).length, 0);
    });

    it('reports a declined or failed checkout and a validation error', async () => {
        for (const [trigger, message] of [
            [(options) => options.onFail({reason: 'declined'}), MESSAGES.failed],
            [(options) => options.onValidationError({}), MESSAGES.validation],
            [(options) => options.onSuccess({}), MESSAGES.failed],
        ]) {
            const p = page({actions: {prepareCheckout: prepared('modal')}});
            const placing = p.method().placeOrder({fallback: async () => {}});
            await settle();
            trigger(p.affirm.options);
            await assert.rejects(placing, new RegExp(message));
            assert.equal(p.notices.at(-1)[1], message);
        }
    });

    it('shows the server message and never opens Affirm when preparation fails', async () => {
        const p = page({actions: {prepareCheckout: (state) => {
            state.errorMessage = 'Affirm is not available for this cart.';
        }}});
        await assert.rejects(p.method().placeOrder({fallback: async () => {}}), /not available for this cart/);
        assert.deepEqual(p.affirm.calls, []);
        assert.deepEqual(p.notices.at(-1), ['error', 'Affirm is not available for this cart.']);
    });

    it('fails when the component is missing or the request fails', async () => {
        const missing = page();
        missing.window.Magewire = {find: () => undefined};
        await assert.rejects(missing.method().placeOrder({fallback: async () => {}}), /unavailable/);

        const broken = page({actions: {prepareCheckout: () => {
            throw new Error('HTTP 500');
        }}});
        await assert.rejects(broken.method().placeOrder({fallback: async () => {}}), /unavailable/);
        assert.deepEqual(broken.notices.at(-1), ['error', MESSAGES.unavailable]);
    });

    it('reports a blocked affirm.js', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}, loadFails: true});
        await assert.rejects(p.method().placeOrder({fallback: async () => {}}), /sdk/);
        assert.deepEqual(p.notices.at(-1), ['error', MESSAGES.sdk]);
    });

    it('clears the stored token when the server refuses the order and adds no second message', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        const placing = p.method().placeOrder({fallback: async () => {
            throw new Error('The order could not be placed.');
        }});
        await settle();
        p.affirm.options.onSuccess({checkout_token: 'TOKEN_1'});
        await assert.rejects(placing, /could not be placed/);
        assert.deepEqual(p.storage.slice(-2), [['checkout_token', null, 'payment'], ['attempt_id', null, 'payment']]);
        assert.deepEqual(p.notices, [['clear', '']]);
    });

    it('treats the error event of Hyvä Checkout before 1.3.14 as a failed placement', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        const placing = p.method().placeOrder({fallback: async () => {
            p.window.dispatch('order:place:affirm_gateway:error', {detail: {text: 'Refused.'}});
        }});
        await settle();
        p.affirm.options.onSuccess({checkout_token: 'TOKEN_1'});
        await assert.rejects(placing, /could not be placed/);
        assert.deepEqual(p.storage.slice(-1), [['attempt_id', null, 'payment']]);
    });

    it('calls the server through hyvaCheckout.misc.call() when Hyvä Checkout 3 provides it', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        const viaMisc = [];
        p.hyvaCheckout.misc = {call: async (wire, method) => {
            viaMisc.push(method);
            return wire[method]();
        }};
        p.method().placeOrder({fallback: async () => {}});
        await settle();
        assert.deepEqual(viaMisc, ['prepareCheckout']);
        assert.deepEqual(p.affirm.calls.map((call) => call[0]), ['reset', 'checkout', 'open']);
    });

    it('reports a failed request that hyvaCheckout.misc.call() rejects', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        p.hyvaCheckout.misc = {call: () => Promise.reject(new Error('The request of prepareCheckout() failed.'))};
        await assert.rejects(p.method().placeOrder({fallback: async () => {}}), /unavailable/);
        assert.deepEqual(p.notices.at(-1), ['error', MESSAGES.unavailable]);
        assert.deepEqual(p.affirm.calls, []);
    });

    it('keeps reported errors out of Hyvä Checkout 3 handleException() and passes the rest on', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        const placing = p.method().placeOrder({fallback: async () => {}});
        await settle();
        p.affirm.options.onFail({reason: 'canceled'});
        const reported = await placing.catch((error) => error);

        const handled = [];
        const fallback = async ({exception}) => handled.push(exception);
        await p.method().handleException({exception: reported, fallback});
        assert.deepEqual(handled, []);

        const other = new Error('The order could not be placed.');
        await p.method().handleException({exception: other, fallback});
        assert.deepEqual(handled, [other]);
        await p.method().handleException({exception: other, fallback: null});
    });

    it('reports blocked browser storage', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}, storageFails: true});
        const placing = p.method().placeOrder({fallback: async () => assert.fail('must not place')});
        await settle();
        p.affirm.options.onSuccess({checkout_token: 'TOKEN_1'});
        await assert.rejects(placing, /storage/);
    });

    it('returns the focus to Place Order once Hyvä re-enables it', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        const placing = p.method().placeOrder({fallback: async () => {}});
        await settle();
        // Affirm's frame took the focus; its removal leaves it on the body.
        p.document.activeElement = p.document.body;
        p.affirm.options.onFail({reason: 'canceled'});
        await assert.rejects(placing);

        p.advance(0);
        assert.equal(p.button.focused, 0, 'still disabled while Hyvä finishes');
        p.button.disabled = false;
        p.advance(50);
        assert.equal(p.button.focused, 1);
    });

    it('leaves the focus alone when the shopper already moved on', async () => {
        const p = page({actions: {prepareCheckout: prepared('modal')}});
        const placing = p.method().placeOrder({fallback: async () => {}});
        await settle();
        p.document.activeElement = {focus: () => assert.fail('not this one')};
        p.button.disabled = false;
        p.affirm.options.onFail({reason: 'canceled'});
        await assert.rejects(placing);
        p.advance(100);
        assert.equal(p.button.focused, 0);
    });
});

describe('redirect Place Order', () => {
    it('opens Affirm with the checkout object and keeps Place Order busy', async () => {
        const p = page({actions: {prepareCheckout: prepared('redirect')}});
        let done = false;
        p.method().placeOrder({fallback: async () => assert.fail('redirect never places here')})
            .then(() => {
                done = true;
            }, () => {
                done = true;
            });
        await settle();
        assert.deepEqual(p.affirm.calls.map((call) => call[0]), ['reset', 'checkout', 'open']);
        same(p.affirm.calls[1][1], checkoutObject('redirect'));
        p.window.dispatch('pagehide');
        p.advance(120000);
        await settle();
        assert.equal(done, false);
        assert.deepEqual(p.storage.filter((entry) => entry[1] !== null), []);
    });

    it('falls back to post() without open()', async () => {
        const p = page({actions: {prepareCheckout: prepared('redirect')}, affirm: fakeAffirm({withOpen: false})});
        p.method().placeOrder({fallback: async () => {}}).catch(() => {});
        await settle();
        assert.deepEqual(p.affirm.calls.map((call) => call[0]), ['reset', 'checkout', 'post']);
    });

    it('gives the button back on a validation error, a bfcache return or a stalled navigation', async () => {
        const scenarios = [
            [(p) => p.affirm.options.onValidationError({}), MESSAGES.validation],
            [(p) => p.window.dispatch('pageshow', {persisted: true}), MESSAGES.failed],
            [(p) => p.advance(60000), MESSAGES.unavailable],
        ];
        for (const [trigger, message] of scenarios) {
            const p = page({actions: {prepareCheckout: prepared('redirect')}});
            const placing = p.method().placeOrder({fallback: async () => {}});
            await settle();
            trigger(p);
            await assert.rejects(placing, new RegExp(message));
        }
    });
});

describe('inline widget', () => {
    const inline = (total) => ({total, metadata: {mode: 'inline'}});

    it('asks the server for its object once the method is active', async () => {
        const p = page({actions: {prepareInline: (state) => {
            state.inlineVersion++;
        }}});
        p.mount();
        assert.deepEqual(p.magewire.calls, [], 'nothing before activation');
        await p.method().initialize();
        await p.method().initialize();
        assert.deepEqual(p.magewire.calls, ['prepareInline']);
    });

    it('renders the newest version once per burst', async () => {
        const p = page();
        p.mount();
        await p.method().initialize();

        p.morph(1, inline(100));
        p.morph(2, inline(200));
        p.advance(150);
        await settle();

        const rendered = p.affirm.calls.filter((call) => call[0] === 'checkout').map((call) => call[1].total);
        assert.deepEqual(rendered, [200]);
        same(p.affirm.calls.at(-1), ['inline', {merchant: {inline_container: CONTAINER_ID}}]);
    });

    it('ignores a version older than the one shown', async () => {
        const p = page();
        p.mount();
        await p.method().initialize();
        p.morph(3, inline(300));
        p.advance(150);
        await settle();

        p.morph(2, inline(200));
        p.advance(150);
        await settle();

        const rendered = p.affirm.calls.filter((call) => call[0] === 'checkout').map((call) => call[1].total);
        assert.deepEqual(rendered, [300]);
    });

    it('empties the widget when the server has no object', async () => {
        const p = page();
        p.mount();
        await p.method().initialize();
        p.morph(1, null);
        p.advance(150);
        await settle();
        assert.equal(p.container.cleared, 1);
        assert.deepEqual(p.affirm.calls, []);
    });

    it('renders a server-rendered object without asking again', async () => {
        const p = page();
        p.root.dataset.inlineVersion = '4';
        p.root.dataset.inlineCheckout = JSON.stringify(inline(400));
        p.mount();
        await p.method().initialize();
        p.advance(150);
        await settle();
        assert.deepEqual(p.magewire.calls, []);
        assert.equal(p.affirm.calls.filter((call) => call[0] === 'inline').length, 1);
    });

    it('does nothing when the widget is off', async () => {
        const p = page();
        p.root.dataset.edu = '0';
        p.mount();
        await p.method().initialize();
        assert.equal(p.observers.length, 0);
        assert.deepEqual(p.magewire.calls, []);
    });

    it('stops observing and clears the widget and the stored token when Affirm is deselected', async () => {
        const p = page();
        p.mount();
        await p.method().initialize();
        await p.method().uninitialize();

        assert.equal(p.observers[0].connected, false);
        assert.equal(p.container.cleared, 1);
        assert.deepEqual(p.storage.slice(-2), [['checkout_token', null, 'payment'], ['attempt_id', null, 'payment']]);
        p.morph(5, inline(500));
        p.advance(150);
        await settle();
        assert.deepEqual(p.affirm.calls, []);
    });

    it('stops when Alpine destroys the component', async () => {
        const p = page();
        const component = p.mount();
        await p.method().initialize();
        component.destroy();
        assert.equal(p.observers[0].connected, false);
    });
});
