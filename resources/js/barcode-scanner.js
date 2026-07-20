import { BrowserMultiFormatReader } from '@zxing/browser';

document.addEventListener('alpine:init', () => {
    Alpine.data('barcodeScanner', () => ({
        scanning: false,
        reader: null,
        controls: null,

        async start() {
            this.scanning = true;
            this.reader = new BrowserMultiFormatReader();

            // A barcode can already be in view on the very first captured
            // frame, which means the decode callback below can fire before
            // `this.controls = await ...` has finished assigning. In that
            // case `controls` (the callback's own third argument) is the
            // only handle we have to stop this specific scan session -
            // relying on the outer `this.controls` would silently no-op.
            let handled = false;

            try {
                this.controls = await this.reader.decodeFromVideoDevice(
                    undefined,
                    this.$refs.video,
                    (result, error, controls) => {
                        if (!result || handled) {
                            return;
                        }

                        // Guard against the loop's already-scheduled next
                        // iteration firing again before the stream actually
                        // tears down.
                        handled = true;

                        this.$wire.call('lookupByCode', result.getText());
                        controls.stop();
                        this.scanning = false;
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
