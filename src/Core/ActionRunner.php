<?php

namespace CodeCorner\Datagrid\Core;

use CodeCorner\Datagrid\Exceptions\InvalidGridRequest;
use Illuminate\Support\Collection;
use LogicException;

class ActionRunner
{
    /**
     * Load the requested rows through the grid query (and row scope) and run the action.
     * If any id is missing from that query, nothing runs.
     *
     * @param  list<int|string>  $ids
     * @return array{affected: int, message: string}
     */
    public function run(Grid $grid, Action $action, mixed $actor, array $ids): array
    {
        $handler = $action->handler();
        if ($handler === null) {
            throw new LogicException("Action [{$action->key()}] has no handler.");
        }

        $wanted = collect($ids)->map(fn ($id) => (string) $id)->unique()->values();

        /** @var Collection $models */
        $models = $grid->scopedQuery($actor)->whereKey($wanted->all())->get();
        $found = $models->map(fn ($model) => (string) $model->getKey())->unique();

        if ($found->count() !== $wanted->count()) {
            throw new InvalidGridRequest('One or more rows are outside this grid.');
        }

        $result = $handler($models, $actor);
        $affected = $models->count();
        $message = $action->getLabel().' completed.';

        if (is_string($result) && $result !== '') {
            $message = $result;
        } elseif (is_array($result)) {
            if (array_key_exists('message', $result) && $result['message'] !== null && $result['message'] !== '') {
                $message = (string) $result['message'];
            }
            if (array_key_exists('affected', $result)) {
                $affected = (int) $result['affected'];
            }
        }

        return ['affected' => $affected, 'message' => $message];
    }
}
