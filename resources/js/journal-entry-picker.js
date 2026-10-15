document.addEventListener('alpine:init', () => {
    Alpine.data('journalEntryPicker', (items, wireModel, initialLabel) => ({
        items,
        query: initialLabel ?? '',
        selectedLabel: initialLabel ?? '',
        open: false,
        active: 0,

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
            this.$wire.set(wireModel, item.id);
        },

        onInput() {
            this.open = true;
            this.active = 0;
            if (this.selectedLabel !== '' && this.query !== this.selectedLabel) {
                this.selectedLabel = '';
                this.$wire.set(wireModel, '', false);
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
