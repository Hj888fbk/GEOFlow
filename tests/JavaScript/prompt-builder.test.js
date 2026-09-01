import assert from 'node:assert/strict';
import test from 'node:test';

import {
    applyAiSuggestion,
    collectBuilderConfig,
    initPromptBuilder,
} from '../../resources/js/admin/prompt-builder.js';

class FakeClassList {
    constructor() {
        this.values = new Set();
    }

    toggle(name, force) {
        if (force) this.values.add(name);
        else this.values.delete(name);
    }

    contains(name) {
        return this.values.has(name);
    }
}

class FakeElement {
    constructor({ name = '', value = '' } = {}) {
        this.name = name;
        this.value = value;
        this.disabled = false;
        this.required = false;
        this.readOnly = false;
        this.textContent = '';
        this.dataset = {};
        this.classList = new FakeClassList();
        this.listeners = new Map();
        this.selectors = new Map();
        this.selectorLists = new Map();
        this.dispatched = [];
    }

    querySelector(selector) {
        return this.selectors.get(selector) ?? null;
    }

    querySelectorAll(selector) {
        return this.selectorLists.get(selector) ?? [];
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatch(type) {
        const event = { target: this, preventDefault() {} };
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));
    }

    dispatchEvent(event) {
        this.dispatched.push(event.type);
        this.dispatch(event.type);
        return true;
    }

    closest(selector) {
        return this.selectors.get(`closest:${selector}`) ?? null;
    }
}

test('builder config collection only includes enabled guided business fields', () => {
    const root = new FakeElement();
    const product = new FakeElement({ name: 'builder_config[product_line]', value: ' KXT 橡胶软接头 ' });
    const questions = new FakeElement({ name: 'builder_config[buyer_questions]', value: '口径怎么选？\n需要哪些工况？' });
    const disabled = new FakeElement({ name: 'builder_config[forbidden_claims]', value: 'disabled' });
    disabled.disabled = true;
    const unrelated = new FakeElement({ name: 'content', value: 'ignored' });
    root.selectorLists.set('[data-builder-input]', [product, questions, disabled, unrelated]);

    assert.deepEqual(collectBuilderConfig(root), {
        product_line: 'KXT 橡胶软接头',
        buyer_questions: '口径怎么选？\n需要哪些工况？',
    });
});

test('guided and traditional modes switch fields without exposing two writable prompt bodies', () => {
    const view = fixture();

    initPromptBuilder(view.root);
    assert.equal(view.guided.classList.contains('hidden'), false);
    assert.equal(view.traditional.classList.contains('hidden'), true);
    assert.equal(view.builderInput.disabled, false);
    assert.equal(view.content.disabled, true);
    assert.equal(view.content.required, false);

    view.mode.value = 'traditional';
    view.mode.dispatch('change');
    assert.equal(view.guided.classList.contains('hidden'), true);
    assert.equal(view.traditional.classList.contains('hidden'), false);
    assert.equal(view.builderInput.disabled, true);
    assert.equal(view.content.disabled, false);
    assert.equal(view.content.required, true);

    view.promptType.value = 'quality_check';
    view.promptType.dispatch('change');
    assert.equal(view.mode.disabled, true);
    assert.equal(view.modeField.classList.contains('hidden'), true);
});

test('AI suggestion stays separate until the user explicitly adopts it', () => {
    const root = new FakeElement();
    const accepted = new FakeElement({ name: 'builder_config[accepted_ai_notes]' });
    root.selectors.set('[data-builder-accepted-notes]', accepted);

    assert.equal(accepted.value, '');
    assert.equal(applyAiSuggestion(root, ' 请补充法兰标准与检测报告。 '), true);
    assert.equal(accepted.value, '请补充法兰标准与检测报告。');
    assert.deepEqual(accepted.dispatched, ['input']);
});

function fixture() {
    const root = new FakeElement();
    root.dataset.suggestionUrl = '/geo_admin/ai-prompts/builder/suggest';
    const promptType = new FakeElement({ name: 'type', value: 'content' });
    const mode = new FakeElement({ name: 'builder_mode', value: 'guided' });
    const modeField = new FakeElement();
    const guided = new FakeElement();
    const traditional = new FakeElement();
    const content = new FakeElement({ name: 'content', value: '' });
    const builderInput = new FakeElement({ name: 'builder_config[product_line]', value: 'KXT' });

    Object.entries({
        '[data-prompt-type]': promptType,
        '[data-builder-mode]': mode,
        '[data-builder-mode-field]': modeField,
        '[data-builder-guided]': guided,
        '[data-builder-traditional]': traditional,
        '[name="content"]': content,
    }).forEach(([selector, element]) => root.selectors.set(selector, element));
    root.selectorLists.set('[data-builder-input]', [builderInput]);

    return { root, promptType, mode, modeField, guided, traditional, content, builderInput };
}
