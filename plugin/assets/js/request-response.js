(function () {
    'use strict';

    // This response helper never changes request state and never resubmits a POST.
    var privateView = document.querySelector('.zbm-dashboard, .zbm-open-request[data-zbm-authenticated="1"], .zbm-request-contact-required, [data-zbm-request-feedback]');
    if (!privateView) { return; }

    var feedback = document.querySelector('[data-zbm-request-feedback]');
    if (feedback) {
        feedback.focus({ preventScroll: true });
        feedback.scrollIntoView({ block: 'start', behavior: 'auto' });
    }

    var refreshing = false;
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted || refreshing) { return; }
        refreshing = true;
        var url = new URL(window.location.href);
        // Display freshness only: neither an auth token nor an idempotency key.
        var marker = window.crypto && typeof window.crypto.randomUUID === 'function'
            ? window.crypto.randomUUID()
            : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        url.searchParams.set('_zbm_response', marker);
        window.location.replace(url.href);
    });
})();
