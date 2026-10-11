(function () {
    'use strict';

    var root;
    var PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    var colors = { deposit: '#7c3aed', balance: '#4f46e5', requests: '#a855f7' };
    var labels = { deposit: 'پیش‌پرداخت', balance: 'پرداخت مانده', requests: 'درخواست' };

    function persianDigits(value) {
        return String(value).replace(/[0-9]/g, function (digit) { return PERSIAN_DIGITS[Number(digit)]; });
    }

    function svgElement(name, attributes) {
        var element = document.createElementNS('http://www.w3.org/2000/svg', name);
        Object.keys(attributes || {}).forEach(function (key) { element.setAttribute(key, attributes[key]); });
        return element;
    }

    function compactNumber(value) {
        if (value >= 1000000000) { return persianDigits(value / 1000000000) + ' میلیارد'; }
        if (value >= 1000000) { return persianDigits(value / 1000000) + ' میلیون'; }
        return persianDigits(value);
    }

    function formatMetric(value) {
        return Number(value || 0).toLocaleString('fa-IR');
    }

    // Five positive tick marks plus the zero baseline, shared by both amount series.
    function axisForRows(rows) {
        var amountPeak = 0;
        var requestPeak = 0;
        rows.forEach(function (row) {
            amountPeak = Math.max(amountPeak, Number(row.deposit) || 0, Number(row.balance) || 0);
            requestPeak = Math.max(requestPeak, Number(row.requests) || 0);
        });
        var amountStep = 20000000 * Math.max(1, Math.ceil(amountPeak / 100000000));
        var requestStep = 5 * Math.max(1, Math.ceil(requestPeak / 25));
        return {
            amountMax: amountStep * 5,
            requestMax: requestStep * 5,
            amountTicks: [0, 1, 2, 3, 4, 5].map(function (tick) { return tick * amountStep; }),
            requestTicks: [0, 1, 2, 3, 4, 5].map(function (tick) { return tick * requestStep; })
        };
    }

    function positionForValue(value, max) {
        return Math.max(0, Math.min(1, (Number(value) || 0) / max));
    }

    function initChart() {
        var chart = root.querySelector('[data-zbm-report-chart]');
        if (!chart) { return; }
        var data;
        try { data = JSON.parse(chart.getAttribute('data-chart') || '{}'); } catch (error) { data = {}; }
        var rows = Array.isArray(data.rows) ? data.rows : [];
        var svg = chart.querySelector('.zbm-reports-chart-svg');
        var grid = chart.querySelector('[data-chart-grid]');
        var axis = chart.querySelector('[data-chart-axis]');
        var seriesGroup = chart.querySelector('[data-chart-series]');
        var pointsGroup = chart.querySelector('[data-chart-points]');
        var tooltip = chart.querySelector('[data-chart-tooltip]');
        if (!svg || !grid || !axis || !seriesGroup || !pointsGroup || !tooltip) { return; }
        var metrics = root.querySelectorAll('.zbm-report-metric[data-series]');
        var active = { deposit: true, balance: true, requests: true };
        var width = 960;
        var height = 360;
        var margin = { top: 18, right: 58, bottom: 48, left: 72 };
        var plotWidth = width - margin.left - margin.right;
        var plotHeight = height - margin.top - margin.bottom;
        var axes = axisForRows(rows);
        var scales = { deposit: axes.amountMax, balance: axes.amountMax, requests: axes.requestMax };

        function xFor(index) { return margin.left + (rows.length <= 1 ? plotWidth / 2 : (index / (rows.length - 1)) * plotWidth); }
        function yFor(series, value) { return margin.top + plotHeight * (1 - positionForValue(value, scales[series])); }
        function pathFor(series) {
            return rows.map(function (row, index) {
                return (index ? 'L' : 'M') + ' ' + xFor(index).toFixed(2) + ' ' + yFor(series, row[series]).toFixed(2);
            }).join(' ');
        }

        for (var gridIndex = 0; gridIndex <= 5; gridIndex += 1) {
            var tickIndex = 5 - gridIndex;
            var gridY = margin.top + (plotHeight / 5) * gridIndex;
            grid.appendChild(svgElement('line', { x1: margin.left, x2: width - margin.right, y1: gridY, y2: gridY }));
            var amountLabel = svgElement('text', { x: margin.left - 12, y: gridY + 4, 'text-anchor': 'end' });
            amountLabel.textContent = compactNumber(axes.amountTicks[tickIndex]);
            axis.appendChild(amountLabel);
            var requestLabel = svgElement('text', { x: width - margin.right + 12, y: gridY + 4, 'text-anchor': 'start' });
            requestLabel.textContent = persianDigits(axes.requestTicks[tickIndex]);
            axis.appendChild(requestLabel);
        }

        var step = Math.max(1, Math.ceil(rows.length / 7));
        rows.forEach(function (row, index) {
            if (index % step !== 0 && index !== rows.length - 1) { return; }
            var label = svgElement('text', { x: xFor(index), y: height - 14, 'text-anchor': index === 0 ? 'start' : (index === rows.length - 1 ? 'end' : 'middle') });
            label.textContent = String(row.date || '').split('/').slice(-2).join('/');
            axis.appendChild(label);
        });
        axis.appendChild(svgElement('line', { x1: margin.left, x2: width - margin.right, y1: margin.top + plotHeight, y2: margin.top + plotHeight }));
        ['deposit', 'balance', 'requests'].forEach(function (series) {
            var path = svgElement('path', { 'data-series': series, d: pathFor(series), stroke: colors[series], 'aria-label': labels[series] });
            seriesGroup.appendChild(path);
        });

        function showTooltip(index) {
            if (!rows[index]) { return; }
            pointsGroup.textContent = '';
            tooltip.textContent = '';
            var row = rows[index];
            pointsGroup.appendChild(svgElement('line', { x1: xFor(index), x2: xFor(index), y1: margin.top, y2: margin.top + plotHeight, stroke: '#cbd5e1', 'stroke-dasharray': '4 4', 'vector-effect': 'non-scaling-stroke' }));
            var title = document.createElement('strong');
            title.textContent = String(row.date || '');
            tooltip.appendChild(title);
            ['deposit', 'balance', 'requests'].forEach(function (series) {
                if (!active[series]) { return; }
                var value = Number(row[series]) || 0;
                pointsGroup.appendChild(svgElement('circle', { cx: xFor(index), cy: yFor(series, value), r: 5, fill: colors[series] }));
                var line = document.createElement('div');
                var dot = document.createElement('span');
                dot.style.color = colors[series];
                dot.textContent = '● ';
                line.appendChild(dot);
                line.appendChild(document.createTextNode(labels[series] + ': ' + formatMetric(value) + (series === 'requests' ? '' : ' تومان')));
                tooltip.appendChild(line);
            });
            tooltip.hidden = false;
            var plot = chart.querySelector('.zbm-reports-chart-plot');
            var rect = plot.getBoundingClientRect();
            var left = (xFor(index) / width) * rect.width;
            tooltip.style.left = Math.max(8, Math.min(rect.width - 160, left - 72)) + 'px';
            tooltip.style.top = Math.max(4, ((margin.top + 8) / height) * rect.height) + 'px';
        }

        svg.addEventListener('pointermove', function (event) {
            if (!rows.length) { return; }
            var rect = svg.getBoundingClientRect();
            var localX = (event.clientX - rect.left) / rect.width * width;
            var index = Math.round((localX - margin.left) / plotWidth * (rows.length - 1));
            showTooltip(Math.max(0, Math.min(rows.length - 1, index)));
        });
        svg.addEventListener('pointerleave', function () { tooltip.hidden = true; pointsGroup.textContent = ''; });
        metrics.forEach(function (metric) {
            metric.addEventListener('click', function () {
                var series = metric.getAttribute('data-series');
                if (!Object.prototype.hasOwnProperty.call(active, series)) { return; }
                active[series] = !active[series];
                metric.setAttribute('aria-pressed', active[series] ? 'true' : 'false');
                metric.classList.toggle('is-active', active[series]);
                var path = seriesGroup.querySelector('[data-series="' + series + '"]');
                if (path) {
                    if (active[series]) { path.removeAttribute('hidden'); }
                    else { path.setAttribute('hidden', ''); }
                }
                pointsGroup.textContent = '';
                tooltip.hidden = true;
            });
        });
    }

    function init() {
        root = document.querySelector('.zbm-vendor-reports');
        if (root) { initChart(); }
    }

    window.ZarbalmanVendorReportChart104 = {
        axisForRows: axisForRows,
        positionForValue: positionForValue
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
