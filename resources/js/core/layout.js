/* Below Tailwind's `lg` the sidebar is an overlay above the page, not a column beside it. There it starts
 * closed on every page and its state is not kept: otherwise a tap on a menu link landed on a page hidden
 * behind the menu, every time. Only the desktop's open/collapsed choice is remembered. */
const wideScreen = () => window.matchMedia('(min-width: 1024px)').matches;

export function registerLayoutHandler(Alpine) {
    Alpine.data('layoutHandler', () => ({
        sidebarOpen: wideScreen() && localStorage.getItem('sidebarOpen') !== 'false',
        darkMode: localStorage.getItem('darkMode') === 'true',
        /* The sidebar is an overlay (below lg): while it is open, the page behind it is out of the keyboard's reach. */
        narrow: !wideScreen(),

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
                // Opened over the page: the keyboard goes into it, at its first link, as into any dialog.
                if (val && this.narrow) this.$nextTick(() => document.querySelector('#main-sidebar nav a')?.focus());
            });

            // Narrowed (a tablet turned, a window made small): the menu becomes an overlay, and starts closed as it does on
            // a phone — never left open over a page made inert behind it.
            window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
                this.narrow = !event.matches;
                if (this.narrow) this.sidebarOpen = false;
            });
        },

        /* Escape shuts the overlay and puts the keyboard back on the button that opened it. */
        closeSidebarOnEscape() {
            // A dialog open above it answers Escape itself (core/modal.js marks the page while one is open).
            if (!this.sidebarOpen || !this.narrow || document.body.classList.contains('overflow-y-hidden')) return;

            this.sidebarOpen = false;
            this.$nextTick(() => document.querySelector('[aria-controls="main-sidebar"]')?.focus());
        },

        toggleDark() { this.darkMode = !this.darkMode; },
        toggleSidebar() { this.sidebarOpen = !this.sidebarOpen; }
    }));
}
