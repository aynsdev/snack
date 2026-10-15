<?php

namespace App\Ai\Tools;

use App\Enums\MagnificModel;
use App\Enums\MediaKind;

/**
 * The model menu the generation tools show Bite.
 */
trait DescribesModels
{
    private function modelList(MediaKind $kind): string
    {
        return collect(MagnificModel::forKind($kind))
            ->map(function (MagnificModel $model) use ($kind): string {
                $durations = $kind === MediaKind::Video
                    ? ' — '.collect($model->durations())->implode('/').'s'
                    : '';

                return "- {$model->value}: {$model->label()}, {$model->summary()}{$durations}";
            })
            ->implode("\n");
    }

    /**
     * @return array<int, string>
     */
    private function modelIds(MediaKind $kind): array
    {
        return array_map(fn (MagnificModel $model): string => $model->value, MagnificModel::forKind($kind));
    }
}
