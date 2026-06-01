/**
 * Square Checkout Integration Script
 *
 * Responsibilities:
 * - Initialize the Square Web Payments SDK
 * - Attach card input UI to the DOM
 * - Tokenize card details securely
 * - Submit the token (source_id) to backend form
 *
 * Important:
 * - This script NEVER handles raw card data directly
 * - Square SDK handles PCI compliance and secure tokenization
 * - Backend receives only a token (source_id), not card details
 *
 * Flow:
 * 1. Detect payment container(s) on page
 * 2. Initialize Square SDK using application + location IDs
 * 3. Attach card UI form
 * 4. On button click:
 *      → tokenize card
 *      → populate hidden form field
 *      → submit form to backend
 */

(function () {

    /**
     * Display an error or status message in the UI
     *
     * @param {HTMLElement} root Root payment container
     * @param {string} message Message to display
     */
    function setStatus(root, message) {
        const el = root.querySelector('#square-payment-status');
        if (el) el.textContent = message || '';
    }

    /**
     * Initialize a single Square payment container
     *
     * This method:
     * - reads configuration from data attributes
     * - initializes the Square Web Payments SDK
     * - attaches card input UI
     * - sets up click handler to tokenize and submit form
     *
     * @param {HTMLElement} root Container element
     */
    function initRoot(root) {

        // Prevent multiple initialization on same element
        if (!root || root.dataset.squareInitialized === '1') return;
        root.dataset.squareInitialized = '1';

        // Read config from HTML data attributes
        const appId = root.getAttribute('data-square-application-id');
        const locationId = root.getAttribute('data-square-location-id');
        const action = root.getAttribute('data-square-action');

        // Required DOM elements
        const form = root.querySelector('#square-payment-form');
        const button = root.querySelector('#square-pay-button');
        const sourceInput = root.querySelector('#square_source_id');
        const actionInput = root.querySelector('#square_action');
        const cardContainer = root.querySelector('#square-card-container');

        // Ensure required elements exist before proceeding
        if (!form || !button || !sourceInput || !actionInput || !cardContainer) return;

        // Ensure Square SDK loaded successfully
        if (!window.Square) {
            setStatus(root, 'Square SDK unavailable');
            return;
        }

        // Async IIFE to use async/await cleanly
        (async function () {
            try {
                /**
                 * Initialize Square payments instance
                 *
                 * This binds the application ID and location ID
                 * to create a payment session.
                 */
                const payments = window.Square.payments(appId, locationId);

                /**
                 * Create card element and attach to DOM
                 *
                 * Square renders secure iframe inputs here
                 * for card number, CVV, expiration, etc.
                 */
                const card = await payments.card();
                await card.attach(cardContainer);

                // Ensure correct backend action value is set
                actionInput.value = action;

                /**
                 * Payment button click handler
                 *
                 * Flow:
                 * 1. Tokenize card
                 * 2. If success → store token
                 * 3. Submit form to backend
                 */
                button.addEventListener('click', async function () {

                    setStatus(root, '');
                    button.disabled = true;

                    try {
                        const result = await card.tokenize();

                        /**
                         * Handle tokenization failure
                         *
                         * Possible reasons:
                         * - invalid card details
                         * - incomplete fields
                         * - network errors
                         */
                        if (result.status !== 'OK') {

                            let message = 'Unable to tokenize card details.';

                            if (result.errors && result.errors.length) {
                                message += ' ' + result.errors.map(e => e.message).join('; ');
                            }

                            setStatus(root, message);
                            button.disabled = false;
                            return;
                        }

                        /**
                         * Tokenization success
                         *
                         * Store token (source_id) into hidden input
                         * This token is what backend sends to Square API.
                         */
                        sourceInput.value = result.token;

                        // Submit form to backend for processing
                        form.submit();

                    } catch (err) {

                        setStatus(
                            root,
                            'Payment form error: ' +
                            (err && err.message ? err.message : 'Unknown error')
                        );

                        button.disabled = false;
                    }
                });

            } catch (err) {
                console.error('Square init failed:', err);
                setStatus(root, 'Square initialization failed.');
            }
        })();
    }

    /**
     * Initialize all existing payment containers on page
     *
     * This supports:
     * - initial page load
     * - static HTML renders
     */
    function initExisting() {
        document.querySelectorAll('#square-checkout-root').forEach(initRoot);
    }

    // Run initial scan
    initExisting();

    /**
     * Observe DOM changes for dynamically added payment forms
     *
     * This ensures compatibility with:
     * - AJAX-loaded pages
     * - SPA-style navigation
     * - dynamic modal or widget rendering
     */
    const observer = new MutationObserver(function (mutations) {

        for (const mutation of mutations) {

            for (const node of mutation.addedNodes) {

                if (!(node instanceof HTMLElement)) continue;

                // Direct match
                if (node.id === 'square-checkout-root') {
                    initRoot(node);
                }

                // Nested match
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