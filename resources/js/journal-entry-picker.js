document.addEventListener('alpine:init', () => {
    Alpine.data('journalEntryPicker', (items, wireModel, initialLabel) => ({
        items,
        query: initialLabel ?? '',
        selectedLabel: initialLabel ?? '',
        open: false,
        active: 0,

        // Патеката до полето во Livewire. Ако елементот носи data-wire-model, се чита
        // од него при секој избор: редовите се префрлаат кога се брише ставка, а
        // почетната патека од x-data тогаш би покажувала на погрешен ред.
        path() {
            return this.$root.dataset.wireModel || wireModel;
        },

        // Текстот се усогласува кога серверот ќе го смени избраниот запис
        // (data-label), освен додека корисникот пишува во полето.
        init() {
            if (this.$root.dataset.label === undefined) {
                return;
            }
            new MutationObserver(() => {
                const label = this.$root.dataset.label ?? '';
                const typing = this.$root.contains(document.activeElement) && this.query !== this.selectedLabel;
                if (label !== this.selectedLabel && ! typing) {
                    this.query = label;
                    this.selectedLabel = label;
                }
            }).observe(this.$root, { attributes: true, attributeFilter: ['data-label'] });
        },

        // Најнапред шифри што почнуваат со впишаното (пишување бројки), потоа
        // останатите што го содржат во шифрата или името.
        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (q === '') {
                return [];
            }
            const starts = [];
            const rest = [];
            for (const item of this.items) {
                const label = item.label.toLowerCase();
                if (label.startsWith(q)) {
                    starts.push(item);
                } else if (label.includes(q)) {
                    rest.push(item);
                }
            }
            return starts.concat(rest).slice(0, 15);
        },

        select(item) {
            this.query = item.label;
            this.selectedLabel = item.label;
            this.open = false;
            this.$wire.set(this.path(), item.id);
        },

        onInput() {
            this.open = true;
            this.active = 0;
            if (this.selectedLabel !== '' && this.query !== this.selectedLabel) {
                this.selectedLabel = '';
                this.$wire.set(this.path(), '', false);
            }
        },

        // Работа само со тастатура: стрелки за листа, Enter или Tab го
        // прифаќа означениот предлог (првиот е означен од почеток).
        onKeydown(event) {
            const list = this.filtered;
            const pending = this.query.trim() !== '' && this.query !== this.selectedLabel;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (list.length === 0) {
                    return;
                }
                event.preventDefault();
                this.open = true;
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.active = (this.active + step + list.length) % list.length;
            } else if (event.key === 'Enter') {
                if (this.open && list.length > 0 && pending) {
                    event.preventDefault();
                    this.select(list[this.active] ?? list[0]);
                }
            } else if (event.key === 'Tab') {
                if (list.length > 0 && pending) {
                    this.select(list[this.active] ?? list[0]);
                }
            } else if (event.key === 'Escape') {
                this.open = false;
            }
        },
    }));
});
