{{-- 子导航“更多”分组：首次访问默认收起；当前页面在该分组内时自动展开，避免看不到当前位置。 --}}
<script>
    (() => {
        const label = 'sub_navigation_更多';
        const apply = () => {
            const store = window.Alpine?.store('sidebar');
            const group = document.querySelector('.fi-page-sub-navigation-sidebar [data-nf-more]');
            if (! store || ! group) {
                return;
            }
            if (! Array.isArray(store.collapsedGroups)) {
                store.collapsedGroups = [];
            }
            if (group.classList.contains('fi-active')) {
                if (store.groupIsCollapsed(label)) {
                    store.toggleCollapsedGroup(label);
                }

                return;
            }
            try {
                if (localStorage.getItem('novel-forge:sub-nav-more-collapsed') === null) {
                    localStorage.setItem('novel-forge:sub-nav-more-collapsed', '1');
                    store.collapseGroup(label);
                }
            } catch (error) {
                store.collapseGroup(label);
            }
        };
        window.Alpine ? apply() : document.addEventListener('alpine:initialized', apply, { once: true });
    })();
</script>
