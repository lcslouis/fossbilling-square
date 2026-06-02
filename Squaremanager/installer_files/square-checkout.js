/**
 * Square Checkout Integration Script
 *
 * Responsibilities:
 * - Initialize the Square Web Payments SDK
 * - Attach card input UI to the DOM
 * - Tokenize card details securely
 * - Submit the token directly to FOSSBilling's guest invoice payment API
 *
 * Important:
 * - This script NEVER handles raw card data directly
 * - Square SDK handles PCI compliance and secure tokenization
 * - Backend receives only a token (source_token), not card details
 *
 * Requirements from getHtml():
 * - window.squareConfig must contain:
 *     - applicationId
 *     - locationId
 *     - invoiceId
 *     - gatewayId
 *     - environment
 * - HTML must contain:
 *     - #square-checkout-root
 *     - #square-card-container
 *     - #square-pay-button
 *     - #square-payment-status
 */

(function () {
    function setStatus(root, message) {
        const el = root.querySelector('#square-payment-status');
        if (el) {
            el.textContent = message || '';
        }
    }

    async function processPayment(token) {
        if (!window.squareConfig) {
            throw new Error('window.squareConfig is missing');
        }

        if (!window.squareConfig.invoiceId) {
            throw new Error('invoiceId is missing from window.squareConfig');
        }

        if (!window.squareConfig.gatewayId) {
            throw new Error('gatewayId is missing from window.squareConfig');
        }

        const payload = {
            invoice_id: window.squareConfig.invoiceId,
            gateway_id: window.squareConfig.gatewayId,
            source_token: token
        };

        console.log('[Square] processPayment payload', payload);

        const response = await fetch('/index.php?_url=/api/guest/invoice/process_payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });

        let data = null;
        const rawText = await response.text();

        try {
            data = rawText ? JSON.parse(rawText) : null;
        } catch (err) {
            console.error('[Square] Failed to parse API response as JSON', err, rawText);
            throw new Error('Invalid API response from server');
        }

        console.log('[Square] API response', data);

        if (!response.ok) {
            const message =
                (data && data.error && (data.error.message || data.error)) ||
                ('HTTP ' + response.status);
            throw new Error(message);
        }

        if (data && data.error) {
            throw new Error(data.error.message || 'Payment API returned an error');
        }

        return data;
    }

    function initRoot(root) {
        if (!root || root.dataset.squareInitialized === '1') return;
        root.dataset.squareInitialized = '1';

        console.log('[Square] initRoot called', root);

        const appId = root.getAttribute('data-square-application-id') || (window.squareConfig && window.squareConfig.applicationId);
        const locationId = root.getAttribute('data-square-location-id') || (window.squareConfig && window.squareConfig.locationId);

        const button = root.querySelector('#square-pay-button');
        const cardContainer = root.querySelector('#square-card-container');

        console.log('[Square] config', {
            appId,
            locationId,
            squareConfig: window.squareConfig || null
        });

        console.log('[Square] elements', {
            button,
            cardContainer
        });

        if (!button || !cardContainer) {
            console.error('[Square] Missing required payment elements');
            return;
        }

        if (!window.Square || typeof window.Square.payments !== 'function') {
            console.error('[Square] window.Square missing or invalid');
            setStatus(root, 'Square SDK unavailable');
            return;
        }

        if (!appId || !locationId) {
            console.error('[Square] Missing applicationId or locationId');
            setStatus(root, 'Square configuration is incomplete');
            return;
        }

        (async function () {
            try {
                const payments = window.Square.payments(appId, locationId);
                const card = await payments.card();
                await card.attach(cardContainer);

                console.log('[Square] card attached');

                button.addEventListener('click', async function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    setStatus(root, '');
                    button.disabled = true;

                    try {
                        const result = await card.tokenize();
                        console.log('[Square] tokenize result', result);

                        if (result.status !== 'OK') {
                            let message = 'Unable to tokenize card details.';

                            if (result.errors && result.errors.length) {
                                message += ' ' + result.errors.map(function (e) {
                                    return e.message;
                                }).join('; ');
                            }

                            setStatus(root, message);
                            button.disabled = false;
                            return;
                        }

                        const apiResult = await processPayment(result.token);

                        console.log('[Square] Payment success', apiResult);
                        setStatus(root, 'Payment processed successfully.');

                        // Reload to refresh invoice/order state after payment
                        window.location.reload();

                    } catch (err) {
                        console.error('[Square] Payment form error', err);
                        setStatus(
                            root,
                            'Payment failed: ' + (err && err.message ? err.message : 'Unknown error')
                        );
                        button.disabled = false;
                    }
                });

            } catch (err) {
                console.error('[Square] init failed', err);
                setStatus(root, 'Square initialization failed.');
            }
        })();
    }

    function initExisting() {
        document.querySelectorAll('#square-checkout-root').forEach(initRoot);
    }

    initExisting();

    const observer = new MutationObserver(function (mutations) {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (!(node instanceof HTMLElement)) continue;

                if (node.id === 'square-checkout-root') {
                    initRoot(node);
                }

                const nested = node.querySelector && node.querySelector('#square-checkout-root');
                if (nested) {
                    initRoot(nested);
                }
            }
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true
    });
})();