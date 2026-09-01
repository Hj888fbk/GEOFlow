export const collectBuilderConfig = (root) => {
    const config = {};
    root.querySelectorAll('[data-builder-input]').forEach((input) => {
        if (input.disabled) return;
        const match = String(input.name || '').match(/^builder_config\[([^\]]+)\]$/);
        if (!match) return;
        config[match[1]] = String(input.value || '').trim();
    });

    return config;
};

export const applyAiSuggestion = (root, suggestion) => {
    const target = root.querySelector('[data-builder-accepted-notes]');
    if (!target) return false;
    target.value = String(suggestion || '').trim();
    target.dispatchEvent(new Event('input', { bubbles: true }));

    return target.value !== '';
};

const errorMessage = async (response) => {
    try {
        const payload = await response.json();
        const errors = payload?.errors || {};
        const first = Object.values(errors).flat().find(Boolean);
        return first || payload?.message || 'AI 建议请求失败，请稍后重试。';
    } catch {
        return 'AI 建议请求失败，请稍后重试。';
    }
};

export const initPromptBuilder = (root) => {
    if (!root || root.dataset.promptBuilderReady === 'true') return;
    root.dataset.promptBuilderReady = 'true';

    const promptType = root.querySelector('[data-prompt-type]') || root.closest('form')?.querySelector('[name="type"]');
    const mode = root.querySelector('[data-builder-mode]');
    const modeField = root.querySelector('[data-builder-mode-field]');
    const guidedSection = root.querySelector('[data-builder-guided]');
    const traditionalSection = root.querySelector('[data-builder-traditional]');
    const contentField = root.querySelector('[name="content"]');

    const refreshMode = () => {
        const isContent = String(promptType?.value || 'content') === 'content';
        const useGuided = isContent && String(mode?.value || 'traditional') === 'guided';
        guidedSection?.classList.toggle('hidden', !useGuided);
        traditionalSection?.classList.toggle('hidden', useGuided);
        modeField?.classList.toggle('hidden', !isContent);
        if (mode) mode.disabled = !isContent;
        root.querySelectorAll('[data-builder-input]').forEach((input) => {
            input.disabled = !useGuided;
        });
        if (contentField && !contentField.readOnly) {
            contentField.disabled = useGuided;
            contentField.required = !useGuided;
        }
    };

    promptType?.addEventListener('change', refreshMode);
    mode?.addEventListener('change', refreshMode);
    refreshMode();

    const suggestButton = root.querySelector('[data-builder-suggest]');
    const modelField = root.querySelector('[data-builder-ai-model]');
    const error = root.querySelector('[data-builder-error]');
    const result = root.querySelector('[data-builder-suggestion-result]');
    const suggestionText = root.querySelector('[data-builder-suggestion-text]');
    const notice = root.querySelector('[data-builder-notice]');
    const adoptButton = root.querySelector('[data-builder-adopt]');

    suggestButton?.addEventListener('click', async () => {
        const modelId = Number(modelField?.value || 0);
        if (!modelId) {
            if (error) {
                error.textContent = '请先选择一个已启用的模型。';
                error.classList.remove('hidden');
            }
            return;
        }

        error?.classList.add('hidden');
        result?.classList.add('hidden');
        suggestButton.disabled = true;
        suggestButton.textContent = '正在检查…';
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch(root.dataset.suggestionUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    ai_model_id: modelId,
                    builder_config: collectBuilderConfig(root),
                }),
            });
            if (!response.ok) throw new Error(await errorMessage(response));
            const payload = await response.json();
            if (suggestionText) suggestionText.value = String(payload.suggestions || '').trim();
            if (notice) notice.textContent = String(payload.notice || '建议尚未采用。');
            result?.classList.remove('hidden');
        } catch (requestError) {
            if (error) {
                error.textContent = requestError instanceof Error ? requestError.message : 'AI 建议请求失败，请稍后重试。';
                error.classList.remove('hidden');
            }
        } finally {
            suggestButton.disabled = false;
            suggestButton.textContent = '检查遗漏问题';
        }
    });

    adoptButton?.addEventListener('click', () => {
        if (applyAiSuggestion(root, suggestionText?.value || '') && notice) {
            notice.textContent = '已写入“已采纳的 AI 建议”，保存候选后才会生效。';
        }
    });
};

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-prompt-builder]').forEach(initPromptBuilder);
}
