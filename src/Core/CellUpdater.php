<?php

namespace CodeCorner\Datagrid\Core;

use CodeCorner\Datagrid\Exceptions\InvalidGridRequest;
use CodeCorner\Datagrid\Support\EditRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class CellUpdater
{
    public function assign(Column $column, Model $model, mixed $value): void
    {
        if ($column->kind() !== 'direct' || $column->isHidden()) {
            throw new InvalidGridRequest("Field [{$column->key()}] cannot be edited.");
        }

        $attribute = $column->attributeName();
        if ($column->hasOptions()) {
            $value = $column->acceptedValue($value);
        }
        $rules = $column->editRules();
        if ($rules !== null) {
            $prepared = EditRules::ignoringCurrent($rules, $model->getKey(), $model->getKeyName());
            $validator = Validator::make(
                [$attribute => $value],
                [$attribute => $prepared]
            );
            if ($validator->fails()) {
                throw new InvalidGridRequest($validator->errors()->first());
            }
            $value = $validator->validated()[$attribute];
        }
        if ($column->hasOptions()) {
            $value = $column->acceptedValue($value);
        }

        $model->setAttribute($attribute, $value);
    }
}
