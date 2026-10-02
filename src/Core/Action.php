<?php

namespace CodeCorner\Datagrid\Core;

use Closure;
use CodeCorner\Datagrid\Support\RouteLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Action
{
    protected string $label;

    protected bool $row = false;

    protected bool $bulk = false;

    protected bool $danger = false;

    protected ?string $confirm = null;

    protected mixed $ability = null;

    protected ?Closure $handler = null;

    protected ?string $routeName = null;

    /** @var array<string, mixed> */
    protected array $routeParams = [];

    protected ?string $icon = null;

    protected ?string $color = null;

    final public function __construct(protected string $key)
    {
        $this->label = Str::headline($key);
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** Show this action on a single row. */
    public function row(bool $row = true): static
    {
        $this->row = $row;

        return $this;
    }

    /** Show this action when one or more rows are selected. */
    public function bulk(bool $bulk = true): static
    {
        $this->bulk = $bulk;

        return $this;
    }

    public function danger(bool $danger = true): static
    {
        $this->danger = $danger;

        return $this;
    }

    /** Client asks for confirmation with this message before posting. */
    public function confirm(?string $message): static
    {
        $this->confirm = $message;

        return $this;
    }

    /** Permission: ability string, enum, or Closure(actor, AuthContext): bool. */
    public function can(mixed $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * @param  Closure(Collection, mixed): (string|array{affected?: int, message?: string}|null)  $handler
     */
    public function handle(Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Navigate instead of posting. Use "{id}" in a parameter to insert the row key.
     *
     * @param  array<string, mixed>  $params
     */
    public function route(string $name, array $params = []): static
    {
        $this->routeName = $name;
        $this->routeParams = $params;

        return $this;
    }

    public function icon(?string $icon): static
    {
        $this->icon = in_array($icon, Button::ICONS, true) ? $icon : null;

        return $this;
    }

    public function color(string $color): static
    {
        if (in_array($color, Button::COLORS, true)) {
            $this->color = $color;
        }

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isRow(): bool
    {
        return $this->row;
    }

    public function isBulk(): bool
    {
        return $this->bulk;
    }

    public function isDanger(): bool
    {
        return $this->danger;
    }

    public function confirmMessage(): ?string
    {
        return $this->confirm;
    }

    public function getAbility(): mixed
    {
        return $this->ability;
    }

    public function handler(): ?Closure
    {
        return $this->handler;
    }

    /** @return array<string, mixed> */
    public function toSchema(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'row' => $this->row,
            'bulk' => $this->bulk,
            'confirm' => $this->confirm,
            'danger' => $this->danger,
            'href' => RouteLink::href($this->routeName, $this->routeParams),
            'icon' => $this->icon,
            'color' => $this->color ?? ($this->danger ? 'danger' : 'neutral'),
        ];
    }
}
