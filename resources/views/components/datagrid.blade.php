<link rel="stylesheet" href="{{ $stylesheet() }}">
<div id="{{ $id }}"></div>
<script type="module">
    import { mountDatagrid } from @js(asset('vendor/datagrid/datagrid.js'));
    mountDatagrid(document.getElementById(@js($id)), @js($config()));
</script>
