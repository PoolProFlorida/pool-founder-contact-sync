/* Local-only dashboard enhancement. No remote scripts or network requests. */
(() => {
    'use strict';

    const status = document.getElementById('cron-copy-status');
    const buttons = Array.from(document.querySelectorAll('[data-copy-target]'));

    for (const button of buttons) {
        button.addEventListener('click', async () => {
            const target = button.getAttribute('data-copy-target');
            const input = target ? document.getElementById(target) : null;
            if (!(input instanceof HTMLInputElement)) return;

            try {
                if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                    throw new Error('Clipboard API unavailable');
                }
                await navigator.clipboard.writeText(input.value);
                for (const other of buttons) other.textContent = 'Copy';
                button.textContent = 'Copied';
                if (status) status.textContent = 'Copied to clipboard.';
            } catch (_) {
                input.focus();
                input.select();
                if (status) status.textContent = 'Text selected. Copy it manually with Ctrl+C or Command+C.';
            }
        });
    }
})();
