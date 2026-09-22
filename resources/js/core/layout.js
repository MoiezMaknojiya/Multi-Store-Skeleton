/* Below Tailwind's `lg` the sidebar is an overlay above the page, not a column beside it. There it starts
 * closed on every page and its state is not kept: otherwise a tap on a menu link landed on a page hidden
 * behind the menu, every time. Only the desktop's open/collapsed choice is remembered. */
const wideScreen = () => window.matchMedia('(min-width: 1024px)').matches;

export function registerLayoutHandler(Alpine) {
    Alpine.data('layoutHandler', () => ({
        sidebarOpen: wideScreen() && localStorage.getItem('sidebarOpen') !== 'false',
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
                if (wideScreen()) localStorage.setItem('sidebarOpen', val);
            });
        },

        toggleDark() { this.darkMode = !this.darkMode; },
        toggleSidebar() { this.sidebarOpen = !this.sidebarOpen; }
    }));
}