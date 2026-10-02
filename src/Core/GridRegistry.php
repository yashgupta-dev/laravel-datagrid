<?php

namespace CodeCorner\Datagrid\Core;

use Illuminate\Contracts\Container\Container;

class GridRegistry
{
    /** @var array<string, class-string<Grid>> */
    private array $grids = [];

    public function __construct(private Container $app, array $configured = [])
    {
        foreach ($configured as $key => $class) {
            $this->register($class, is_string($key) ? $key : null);
        }
    }

    /** @param class-string<Grid> $class */
    public function register(string $class, ?string $key = null): static
    {
        $this->grids[$key ?? $this->app->make($class)->key()] = $class;

        return $this;
    }

    public function find(string $key): ?Grid
    {
        return isset($this->grids[$key]) ? $this->app->make($this->grids[$key]) : null;
    }

    /** @return array<string, class-string<Grid>> */
    public function all(): array
    {
        return $this->grids;
    }
}
