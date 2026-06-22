<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Sources;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация PUT конфига источника Я.Афиша (admin, суперадмин).
 *
 * authorize() = defense-in-depth: помимо route-middleware role:superadmin
 * ещё раз проверяем роль (на случай ошибки в группировке роутов).
 *
 * Slug разделов — строго из whitelist (KNOWN_SECTIONS): slug подставляется в
 * URL listing'а Я.Афиши (https://afisha.yandex.ru/{city}/{slug}), произвольная
 * строка = SSRF-подобный риск. Город — kebab-slug.
 */
class UpdateYandexAfishaConfigRequest extends FormRequest
{
    /** Известные slug'и разделов Я.Афиши (verified 2026-06-22 + резерв). */
    public const KNOWN_SECTIONS = [
        'concert', 'theatre', 'art', 'kids', 'standup',
        'show', 'quest', 'cinema', 'sport',
    ];

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
            'sections.*.slug' => ['required_with:sections', 'string', Rule::in(self::KNOWN_SECTIONS)],
            'sections.*.enabled' => ['sometimes', 'boolean'],
        ];
    }
}
