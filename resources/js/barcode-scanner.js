import { BrowserMultiFormatReader } from '@zxing/browser';

document.addEventListener('alpine:init', () => {
    Alpine.data('barcodeScanner', () => ({
        scanning: false,
        reader: null,
        controls: null,

        async start() {
            this.scanning = true;
            this.reader = new BrowserMultiFormatReader();

            try {
                this.controls = await this.reader.decodeFromVideoDevice(
                    undefined,
                    this.$refs.video,
                    (result) => {
                        if (result) {
                            this.$wire.call('lookupByCode', result.getText());
                            this.stop();
                        }
                    }
                );
            } catch (error) {
                this.scanning = false;
            }
        },

        stop() {
            this.controls?.stop();
            this.scanning = false;
        },
    }));
});
