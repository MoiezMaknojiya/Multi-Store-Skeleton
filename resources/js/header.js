export function registerHeader(Alpine) {
    Alpine.data('header', () => ({
        userMenu: false,

        toggle() {
            this.userMenu = !this.userMenu;
        },

        close() {
            this.userMenu = false;
        }
    }));
}