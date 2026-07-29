document.addEventListener('alpine:init', () => {
    Alpine.data('journalEntryPicker', (items, wireModel, initialLabel) => ({
        items,
        query: initialLabel ?? '',
        selectedLabel: initialLabel ?? '',
        open: false,

        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (q === '') {
                return [];
            }
            return this.items
                .filter((item) => item.label.toLowerCase().includes(q))
                .slice(0, 15);
        },

        select(item) {
            this.query = item.label;
            this.selectedLabel = item.label;
            this.open = false;
            this.$wire.set(wireModel, item.id);
        },

        onInput() {
            this.open = true;
            if (this.selectedLabel !== '' && this.query !== this.selectedLabel) {
                this.selectedLabel = '';
                this.$wire.set(wireModel, '', false);
            }
        },
    }));
});
