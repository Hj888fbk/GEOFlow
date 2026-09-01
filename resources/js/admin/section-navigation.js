const revealCurrentSection = (navigation) => {
    const current = navigation.querySelector('[aria-current="page"]');
    if (!(current instanceof HTMLElement)) return;

    const navigationRect = navigation.getBoundingClientRect();
    const currentRect = current.getBoundingClientRect();
    const isFullyVisible = currentRect.left >= navigationRect.left
        && currentRect.right <= navigationRect.right;
    if (isFullyVisible) return;

    const centeredOffset = currentRect.left
        - navigationRect.left
        - ((navigationRect.width - currentRect.width) / 2);
    navigation.scrollTo({
        left: Math.max(0, navigation.scrollLeft + centeredOffset),
        behavior: 'auto',
    });
};

const initializeSectionNavigation = () => {
    document.querySelectorAll('[data-section-navigation]').forEach((navigation) => {
        window.requestAnimationFrame(() => revealCurrentSection(navigation));
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSectionNavigation, { once: true });
} else {
    initializeSectionNavigation();
}
