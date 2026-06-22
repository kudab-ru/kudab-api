<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Sources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация PUT конфига источника Я.Афиша (admin, суперадмин).
 *
 * authorize() = defense-in-depth: помимо route-middleware role:superadmin
 * ещё раз проверяем роль (на случай ошибки в группировке роутов).
 *
 * Slug разделов суперадмин может задавать СВОИ (на случай новых разделов Я.Афиши),
 * но формат строго ограничен (SSRF-guard): slug подставляется в URL listing'а
 * https://afisha.yandex.ru/{city}/{slug}, поэтому только нижний регистр латиницы,
 * цифры и дефис — без слешей/точек/пробелов (иначе path-traversal/инъекция).
 * KNOWN_SECTIONS остаются ПОДСКАЗКАМИ для UI (known_sections в ответе), не жёстким
 * whitelist'ом валидации. Город — тоже kebab-slug.
 */
class UpdateYandexAfishaConfigRequest extends FormRequest
{
    /** Известные slug'и разделов Я.Афиши (verified 2026-06-22 + резерв) — подсказки UI. */
    public const KNOWN_SECTIONS = [
        'concert', 'theatre', 'art', 'kids', 'standup',
        'show', 'quest', 'cinema', 'sport',
    ];

    /** Допустимый формат slug'а: kebab-латиница, до 40 символов, без слешей/точек. */
    public const SLUG_REGEX = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('superadmin');
    }

    public function rules(): array
    {
        return [
            'city_slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'enabled' => ['sometimes', 'boolean'],
            'json_ld_bypass_enabled' => ['sometimes', 'boolean'],
            'listing_limit_per_run' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'listing_limit_per_section' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'sections' => ['sometimes', 'array', 'max:30'],
            'sections.*.slug' => ['required_with:sections', 'string', 'regex:'.self::SLUG_REGEX],
            'sections.*.enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sections.*.slug.regex' => 'Slug раздела: латиница в нижнем регистре, цифры и дефис (напр. theatre, art, stand-up). Без слешей, точек и пробелов.',
        ];
    }
}
