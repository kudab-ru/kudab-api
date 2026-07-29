<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Активная модель текст-движка по назначению — одна строка (key='default').
 *
 * @property int $id
 * @property string $key
 * @property array<string, string>|null $models карта назначение → id модели
 * @property int|null $updated_by
 */
class LlmTextConfig extends Model
{
    protected $table = 'llm_text_configs';

    protected $fillable = ['key', 'models', 'updated_by'];

    protected $casts = [
        'models' => 'array',
    ];
}
