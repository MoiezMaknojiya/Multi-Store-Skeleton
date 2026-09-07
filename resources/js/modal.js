// resources/js/modal.js
export function registerCustomModal(Alpine) {
    Alpine.data('customModal', (modalName, initialShow, isFocusable) => ({
        show: initialShow,

        init() {
            this.$watch('show', value => {
                if (value) {
                    document.body.classList.add('overflow-y-hidden');

                    if (isFocusable) {
                        setTimeout(() => this.focusables()[0]?.focus(), 100);
                    }
                } else {
                    document.body.classList.remove('overflow-y-hidden');
                }
            });
        },

        focusables() {
            let selector = 'a, button, input:not([type="hidden"]), textarea, select, details, [tabindex]:not([tabindex="-1"])';
            return [...this.$el.querySelectorAll(selector)]
                .filter(el => !el.hasAttribute('disabled'));
        },

        openEvent(event) {
            if (event.detail === modalName) this.show = true;
        },

        closeEvent(event) {
            if (event.detail === modalName) this.show = false;
        },

        closeMe() {
            this.show = false;
        }
    }));
}