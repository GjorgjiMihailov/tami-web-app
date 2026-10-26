/*
 * Бројач за износите на таблите: бројката се качува од 0 до вистинската.
 *
 *   <p x-data="countUp" data-count="1254920.00">1.254.920,00 ден</p>
 *
 * Серверот секогаш го запишува вистинскиот текст, па без JS, со „намали
 * движење" или при брзо излегување од екранот се гледа точниот износ. На крајот
 * текстот се враќа точно на оригиналот — заокружувањето во меѓучекорите никогаш
 * не смее да остане на екран.
 *
 * Се врти само додека <main> го има прекинувачот `motion-in` (види
 * layouts/app.blade.php), т.е. при вистинско отворање на екранот, а не при
 * секое освежување на Livewire.
 */
document.addEventListener('alpine:init', () => {
    const DURATION = 900;

    // Македонски запис: точка за илјади, запирка за децимали.
    const format = (value, decimals) => {
        const [whole, fraction] = Math.abs(value).toFixed(decimals).split('.');
        const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        return (value < 0 ? '-' : '') + grouped + (fraction !== undefined ? ',' + fraction : '');
    };

    Alpine.data('countUp', () => ({
        init() {
            const el = this.$el;
            const target = parseFloat(el.dataset.count);
            const original = el.textContent;

            if (
                Number.isNaN(target)
                || target === 0
                || window.matchMedia('(prefers-reduced-motion: reduce)').matches
                || ! el.closest('.motion-in')
            ) {
                return;
            }

            // Заради децималите и ознаката на валутата („ден") го земаме обликот од оригиналот.
            const decimals = (original.match(/,(\d+)/) || [, ''])[1].length;
            const suffix = (original.match(/\s*[^\d.,\s-].*$/) || [''])[0];
            const delay = parseInt(el.dataset.countDelay || '0', 10);
            let startedAt = null;
            let done = false;

            el.textContent = format(0, decimals) + suffix;

            const step = (now) => {
                if (done) {
                    return;
                }

                startedAt ??= now;
                const t = Math.min(1, Math.max(0, (now - startedAt - delay) / DURATION));
                const eased = 1 - Math.pow(1 - t, 3);

                done = t >= 1;
                el.textContent = done ? original : format(target * eased, decimals) + suffix;

                if (! done) {
                    requestAnimationFrame(step);
                }
            };

            // requestAnimationFrame стои во скриен јазиче; износот никогаш не смее
            // да остане на 0 ако јазичето се отвори позади.
            setTimeout(() => {
                done = true;
                el.textContent = original;
            }, DURATION + delay + 400);

            requestAnimationFrame(step);
        },
    }));
});
