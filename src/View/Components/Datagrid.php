<?php

namespace CodeCorner\Datagrid\View\Components;

use Illuminate\Support\Str;
use Illuminate\View\Component;
use Illuminate\View\View;

class Datagrid extends Component
{
    public string $id;

    /**
     * @param  string  $grid  registered grid key, e.g. "users"
     * @param  string|null  $url  override the base URL (defaults to the package routes)
     */
    public function __construct(
        public string $grid,
        public ?string $url = null,
        public int $perPage = 25,
        public ?string $title = null,
        public ?string $css = null,
    ) {
        $this->id = 'dg-'.Str::random(8);
    }

    /** Published stylesheet. Override with the css attribute after you customize the file. */
    public function stylesheet(): string
    {
        return $this->css ?? asset('vendor/datagrid/datagrid.css');
    }

    /** Options handed to mountDatagrid(). */
    public function config(): array
    {
        return [
            'url' => $this->url ?? url(trim(config('datagrid.routes.prefix', 'datagrid'), '/').'/'.$this->grid),
            'perPage' => $this->perPage,
            'csrf' => csrf_token(),
            'title' => $this->title,
        ];
    }

    public function render(): View
    {
        return view('datagrid::components.datagrid');
    }
}
