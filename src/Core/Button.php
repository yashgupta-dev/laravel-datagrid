<?php

namespace CodeCorner\Datagrid\Core;

use CodeCorner\Datagrid\Support\RouteLink;
use Illuminate\Support\Str;

class Button
{
    /** @var list<string> */
    public const ICONS = ['plus', 'pencil', 'trash', 'mail', 'check'];

    /** @var list<string> */
    public const COLORS = ['primary', 'neutral', 'danger'];

    protected string $label;

    protected ?string $routeName = null;

    /** @var array<string, mixed> */
    protected array $routeParams = [];

    protected ?string $icon = null;

    protected string $color = 'neutral';

    protected mixed $ability = null;

    /** @var list<self>|null */
    protected ?array $items = null;

    final public function __construct(protected string $key)
    {
        $this->label = Str::headline($key);
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    /** @param  list<self>  $items */
    public static function dropdown(string $key, string $label, array $items): static
    {
        $button = new static($key);
        $button->label = $label;
        $button->items = array_values(array_filter(
            $items,
            fn ($item) => $item instanceof self && ! $item->isDropdown()
        ));

        return $button;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @param  array<string, mixed>  $params */
    public function route(string $name, array $params = []): static
    {
        $this->routeName = $name;
        $this->routeParams = $params;

        return $this;
    }

    public function icon(?string $icon): static
    {
        $this->icon = in_array($icon, self::ICONS, true) ? $icon : null;

        return $this;
    }

    public function color(string $color): static
    {
        if (in_array($color, self::COLORS, true)) {
            $this->color = $color;
        }

        return $this;
    }

    /** Permission: ability string, enum, or Closure(actor, AuthContext): bool. */
    public function can(mixed $ability): static
    {
        $this->ability = $ability;

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

    public function getAbility(): mixed
    {
        return $this->ability;
    }

    public function isDropdown(): bool
    {
        return $this->items !== null;
    }

    /** @return list<self> */
    public function items(): array
    {
        return $this->items ?? [];
    }

    /** @param  list<self>  $items */
    public function replaceItems(array $items): static
    {
        $this->items = array_values($items);

        return $this;
    }

    public function iconName(): ?string
    {
        return $this->icon;
    }

    public function colorName(): string
    {
        return $this->color;
    }

    /** @return array<string, mixed>|null */
    public function toSchema(): ?array
    {
        if ($this->isDropdown()) {
            $items = array_values(array_filter(array_map(
                fn (self $item) => $item->toSchema(),
                $this->items()
            )));
            if ($items === []) {
                return null;
            }

            return [
                'key' => $this->key,
                'label' => $this->label,
                'icon' => $this->icon,
                'color' => $this->color,
                'items' => $items,
            ];
        }

        $href = RouteLink::href($this->routeName, $this->routeParams);
        if ($href === null) {
            return null;
        }

        return [
            'key' => $this->key,
            'label' => $this->label,
            'href' => $href,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
