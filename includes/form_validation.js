(() => {
    const controlSelector = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), select, textarea';
    const indicatorClass = 'required-field-indicator';

    function removeTrailingAsterisk(label) {
        const walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
        const textNodes = [];
        let node;

        while ((node = walker.nextNode())) {
            if (!node.parentElement.closest(`.${indicatorClass}`)) {
                textNodes.push(node);
            }
        }

        for (let index = textNodes.length - 1; index >= 0; index -= 1) {
            const textNode = textNodes[index];
            if (textNode.textContent.trim() === '') {
                continue;
            }
            textNode.textContent = textNode.textContent.replace(/\s*\*+\s*$/, '');
            break;
        }
    }

    function synchronizeRequiredIndicators() {
        document.querySelectorAll('label').forEach((label) => {
            removeTrailingAsterisk(label);
            const control = label.control;
            if (!control) {
                return;
            }

            const existingIndicators = label.querySelectorAll(`.${indicatorClass}`);
            if (!control.required) {
                existingIndicators.forEach((indicator) => indicator.remove());
                return;
            }
            if (existingIndicators.length > 0) {
                return;
            }

            const indicator = document.createElement('span');
            indicator.className = indicatorClass;
            indicator.setAttribute('aria-hidden', 'true');
            indicator.textContent = '*';
            const nestedControl = label.querySelector(controlSelector);
            if (nestedControl) {
                label.insertBefore(indicator, nestedControl);
            } else {
                label.append(indicator);
            }
        });
    }

    synchronizeRequiredIndicators();

    const observer = new MutationObserver(synchronizeRequiredIndicators);
    observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['required'],
        childList: true,
        subtree: true,
    });
})();
