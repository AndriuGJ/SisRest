// La navegación Livewire crea un control nuevo por pantalla; no se persiste
// el estado del POS en los demás módulos.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('sidebarControl', () => ({
        collapsed: false,
        observer: null,
        sidebar: null,
        frame: null,

        init() {
            this.sidebar = this.$el.closest('[data-flux-sidebar]');
            const sync = () => {
                this.collapsed = this.sidebar.hasAttribute('data-flux-sidebar-collapsed-desktop');
            };
            this.observer = new MutationObserver(sync);
            this.observer.observe(this.sidebar, {
                attributes: true,
                attributeFilter: ['data-flux-sidebar-collapsed-desktop'],
            });
            sync();
            this.frame = requestAnimationFrame(() => {
                if (document.body.classList.contains('pos-palette')
                    && window.matchMedia('(min-width: 1024px)').matches
                    && !this.sidebar.hasAttribute('data-flux-sidebar-collapsed-desktop')) {
                    this.$dispatch('flux-sidebar-toggle');
                }
            });
        },

        destroy() {
            this.observer?.disconnect();
            cancelAnimationFrame(this.frame);
        },
    }));
});
