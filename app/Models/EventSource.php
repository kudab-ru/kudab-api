<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $event_id
 * @property int $social_link_id
 * @property int|null $context_post_id
 * @property string $source
 * @property string $post_external_id
 * @property string|null $external_url
 * @property \Carbon\CarbonInterface|null $published_at
 * @property array|null $images
 * @property array|null $meta
 * @property string|null $generated_link
 */
class EventSource extends Model
{
    protected $fillable = [
        'event_id','social_link_id','context_post_id',
        'source','post_external_id','external_url','published_at',
        'images','meta','generated_link',
    ];

    protected $casts = [
        'images' => 'array',
        'meta'   => 'array',
        'published_at' => 'datetime',
    ];

    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function socialLink(): BelongsTo { return $this->belongsTo(CommunitySocialLink::class, 'social_link_id'); }

    protected static function booted(): void
    {
        $compute = function (EventSource $es): void {
            $link = $es->socialLink;
            if (!$link || !$link->social_network_id) { $es->generated_link = null; return; }

            $sn = $link->socialNetwork?->name; // 'vk' | 'tg' | 'site' и т.п.
            $service = $sn ? SocialMediaApiFactory::getService($sn) : null;

            if ($service && method_exists($service, 'generateEventLink')) {
                $es->generated_link = $service->generateEventLink($es->post_external_id, $link->external_community_id);
            } else {
                $es->generated_link = null;
            }
        };

        static::creating($compute);
        static::updating($compute);
    }
}
