export function registerLayoutHandler(Alpine) {
    Alpine.data('layoutHandler', () => ({
        sidebarOpen: localStorage.getItem('sidebarOpen') !== 'false',
        darkMode: localStorage.getItem('darkMode') === 'true',

        init() {
            document.documentElement.classList.toggle('dark', this.darkMode);
            /* Alpine now owns the sidebar's open/closed state — the pre-Alpine
             * flash-prevention class (see layouts/app.blade.php) is no longer needed. */
            document.documentElement.classList.remove('sidebar-closed');

            this.$watch('darkMode', val => {
                localStorage.setItem('darkMode', val);
                document.documentElement.classList.toggle('dark', val);
            });

            this.$watch('sidebarOpen', val => {
                localStorage.setItem('sidebarOpen', val);
            });
        },

        toggleDark() { this.darkMode = !this.darkMode; },
        toggleSidebar() { this.sidebarOpen = !this.sidebarOpen; }
    }));
}