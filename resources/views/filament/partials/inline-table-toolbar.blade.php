{{-- 带 nf-ta-inline-toolbar 类的表格：页眉标题与搜索、筛选工具栏排在同一行，只保留一条分隔线；窄屏自动换行。 --}}
<style>
    .nf-ta-inline-toolbar .fi-ta-header-ctn { display: flex; flex-wrap: wrap; align-items: center; border-bottom: 1px solid var(--gray-200); }
    .nf-ta-inline-toolbar .fi-ta-header { flex: 1 1 auto; border-bottom: 0; }
    .nf-ta-inline-toolbar .fi-ta-header-toolbar { flex: 0 1 auto; margin-inline-start: auto; border-bottom: 0; }
    .dark .nf-ta-inline-toolbar .fi-ta-header-ctn { border-bottom-color: rgb(255 255 255 / .1); }
</style>
