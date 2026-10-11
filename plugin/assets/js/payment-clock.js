(function () {
    'use strict';
    if (window.zbmPaymentClockStarted) { return; }
    window.zbmPaymentClockStarted = true;
    var loadedAt = performance.now();
    var serverTime = Number((window.zbmPaymentClock || {}).now) * 1000;
    if (!Number.isFinite(serverTime) || serverTime <= 0) { serverTime = Date.now(); }
    var digits = new Intl.NumberFormat('fa-IR', { useGrouping: false, minimumIntegerDigits: 2 });
    function tick() {
        var now = serverTime + (performance.now() - loadedAt);
        document.querySelectorAll('.zbm-countdown[data-deadline]').forEach(function (node) {
            var deadline = Date.parse(node.getAttribute('data-deadline'));
            if (!Number.isFinite(deadline)) { return; }
            var remaining = Math.max(0, Math.ceil((deadline - now) / 1000));
            var hours = Math.floor(remaining / 3600);
            var minutes = Math.floor((remaining % 3600) / 60);
            var seconds = remaining % 60;
            node.setAttribute('dir', 'rtl');
            node.textContent = remaining > 0
                ? 'زمان باقی‌مانده: ' + digits.format(hours) + ':' + digits.format(minutes) + ':' + digits.format(seconds)
                : 'مهلت این مرحله پایان یافته است؛ نتیجه پس از بررسی سرور نمایش داده می‌شود.';
            node.classList.toggle('is-expired', remaining === 0);
            // Display only. Expiry, payment permission and money are always server-owned.
        });
    }
    tick();
    window.setInterval(tick, 1000);
    document.addEventListener('visibilitychange', tick);
})();
