<?php

it('renders the x-datagrid component with a mount point and config', function () {
    $html = (string) $this->blade('<x-datagrid grid="users" :per-page="10" />');

    expect($html)
        ->toContain('mountDatagrid')
        ->toContain('datagrid.js')
        ->toContain('datagrid.css')
        ->toContain('users')
        ->toContain('perPage');
});
