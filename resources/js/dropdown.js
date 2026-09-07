export function registerDropdown(Alpine) {
    Alpine.data('dropdown', () => ({
        open: false,

        toggle() {
            this.open = !this.open
        },

        close() {
            this.open = false
        }
    }))
}