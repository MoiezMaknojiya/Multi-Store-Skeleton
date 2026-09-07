export function registerPasswordToggle(Alpine) {
    Alpine.data('passwordToggle', () => ({
        show: false,

        toggle() {
            this.show = !this.show;
        }
    }));
}