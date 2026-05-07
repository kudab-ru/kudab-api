<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommunitiesTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Маппинг "как в сидере написано" -> slug в таблице cities
        // (если у тебя slug другой — поменяй тут один раз)
        $citySlugByName = [
            'Воронеж' => 'voronezh',
        ];

        // Резолвим city_id (сначала по slug, потом по name)
        $cityIdByName = [];
        foreach ($citySlugByName as $cityName => $slug) {
            $id = DB::table('cities')->where('slug', $slug)->value('id');
            if (!$id) {
                $id = DB::table('cities')->where('name', $cityName)->value('id');
            }
            $cityIdByName[$cityName] = $id;

            if (!$id && $this->command) {
                $this->command->warn("City not found: {$cityName} (slug={$slug}) → communities.city_id останется NULL");
            }
        }

        $communities = [
            [
                'id' => 1,
                'name' => 'Куда пойти в Воронеже | Афиша мероприятий',
                'description' => 'Присылайте в "Предложить новость" информацию о предстоящих мероприятиях в Воронеже. Это будет размещено на стене группы. По вопросам сотрудничества обращаться через сообщения нашему сообществу.',
                'city' => 'Воронеж',
                'street' => null,
                'house' => null,
                'avatar_url' => null,
                'image_url' => null,
                'last_checked_at' => null,
                'verification_status' => 'approved',
                'is_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'name' => 'Не Школа Вокала Воронеж',
                'description' => 'Не Школа - это твои вокальные навыки, эмоции, возможность выступить на сцене с любимой песней, и, конечно же, результат! https://vk.com/app6013442_-219904664?form_id=13#form_..

🏆 ТОПОВАЯ СЕТЬ музыкальных школ в России и странах СНГ.

● Обучение вокалу по авторской методике ДЛЯ ВЗРОСЛЫХ независимо от исходных вокальных данных;
● Лучшие преподаватели ВОРОНЕЖА;
● Самые громкие ПАТИ, квартирники, отчетные концерты и профессиональные мастер-классы;
● Расположение в удобном районе города;
● Гибкий график занятий (можно заниматься утром, днем и вечером, в будни и выходные).

Оставляй заявку: https://vk.com/app6013442_-219904664?form_id=13#form_..',
                'city' => 'Воронеж',
                'street' => null,
                'house' => null,
                'avatar_url' => null,
                'image_url' => null,
                'last_checked_at' => null,
                'verification_status' => 'approved',
                'is_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'name' => 'Новый театр | Воронеж',
                'description' => 'Независимый профессиональный театр города Воронежа. В репертуаре - спектакли самой разной направленности: современная драматургия, документальный театр, актуальная классика, детские, музыкальные и пластические спектакли.

Инстаграм театра:
https://www.instagram.com/newteatrvrn

Телеграм театра:
https://t.me/newteatr',
                'city' => 'Воронеж',
                'street' => null,
                'house' => null,
                'avatar_url' => null,
                'image_url' => null,
                'last_checked_at' => null,
                'verification_status' => 'approved',
                'is_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'name' => 'Настольные игры | ПараDice | Воронеж',
                'description' => 'Сообщество ПараDice - место, предоставляющее опции:
- Прокат настольных игр
- Проведение игровечеров
- Помощь в разборе правил

Находимся по адресу: Фридриха Энгельса, 24б, офис 50, домофон 50., жилой подъезд 2 этаж, правая дверь.

Телефон +7 (900) 299-12-93',
                'city' => 'Воронеж',
                'street' => null,
                'house' => null,
                'avatar_url' => null,
                'image_url' => null,
                'last_checked_at' => null,
                'verification_status' => 'approved',
                'is_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 5,
                'name' => 'Никитинский театр',
                'description' => 'Независимый театр под руководством Бориса Алексеева.
Фойе театра - территория выставок и спецпроектов.

Воронеж, ул. Бакунина, 2а

Как нас найти: https://nikitincenter.ru/#contacts

тел. +7 473 228 73 82

Анкета зрителя: goo.gl/41tufE
Заявки на прослушивание: goo.gl/Ac4Xmp

Сайт: nikitincenter.ru

Билеты:
http://nikitincenter.ru',
                'city' => 'Воронеж',
                'street' => null,
                'house' => null,
                'avatar_url' => null,
                'image_url' => null,
                'last_checked_at' => null,
                'verification_status' => 'approved',
                'is_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        // Проставляем city_id всем, у кого есть city
        foreach ($communities as &$community) {
            $cityName = $community['city'] ?? null;
            $community['city_id'] = $cityName ? ($cityIdByName[$cityName] ?? null) : null;
        }
        unset($community);

        $communitySocialLinks = [
            [
                'community_id' => 1,
                'social_network_id' => 1, // VK
                'external_community_id' => '22312958',
                'url' => 'https://vk.com/mtvgo',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'community_id' => 1,
                'social_network_id' => 2, // Telegram
                'external_community_id' => '-1002151070939',
                'url' => 'https://t.me/mtv36go',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'community_id' => 2,
                'social_network_id' => 1,
                'external_community_id' => '219904664',
                'url' => 'https://vk.com/vokal_voronezh',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'community_id' => 2,
                'social_network_id' => 2,
                'external_community_id' => '-1002389406953',
                'url' => 'https://t.me/neschoolbuteroff',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'community_id' => 3,
                'social_network_id' => 1,
                'external_community_id' => '111403571',
                'url' => 'https://vk.com/newteatr',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'community_id' => 3,
                'social_network_id' => 2,
                'external_community_id' => '-1001618158821',
                'url' => 'https://t.me/newteatr',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'community_id' => 4,
                'social_network_id' => 1,
                'external_community_id' => '208711281',
                'url' => 'https://vk.com/para_dice_vrn',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'community_id' => 5,
                'social_network_id' => 1,
                'external_community_id' => '30245777',
                'url' => 'https://vk.com/nikitincenter',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        // DEV fixture из prod-like активных источников Воронежа.
        // Нужны для полноценного reindex/smoke на 300+ постов и проверки guards.
        $prodLikeCommunities = [
            [65, 'Культурный регион Воронеж', '227249561', 'https://vk.com/culturalregion36'],
            [54, 'ЗАповедныйВОРОНЕЖ', '204682632', 'https://vk.com/zavrn_guide'],
            [13, 'Министерство культуры Воронежской области', '131045020', 'https://vk.com/kulturavo'],
            [7, 'Воронежский государственный театр оперы и балета', '2375140', 'https://vk.com/operavrn'],
            [84, 'Центр Креативных Индустрий «Матрёшка» | Воронеж', '214264347', 'https://vk.com/creativecentre_vrn'],
            [12, 'Правительство Воронежской области', '182585808', 'https://vk.com/govvrn36'],
            [79, 'Smart - парк "Дельфин"', '195572091', 'https://vk.com/dolphinvoronezh'],
            [37, 'Воронежский областной краеведческий музей', '135394155', 'https://vk.com/kraeved_muz'],
            [106, 'ВИНЗАВОД', '170377932', 'https://vk.com/vinzavodpro'],
            [28, 'Отражение | Медиа ВГПУ', '39342464', 'https://vk.com/otrazhenie_vspu'],
            [64, 'Центр развития творчества детей и юношества', '211864594', 'https://vk.com/center_tvorchestva_vrn'],
            [70, 'Сити-парк «Град»', '18349255', 'https://vk.com/cityparkgrad'],
            [15, 'VRN Guide', '47196432', 'https://vk.com/vrn.guide'],
            [52, 'Театр на подушках | Воронеж | 0+', '209530726', 'https://vk.com/na_podushkah_teatr'],
            [8, 'Театр кукол имени В.А. Вольховского', '23380395', 'https://vk.com/teatrkukolshut'],
            [68, 'Платоновский фестиваль искусств', '24834832', 'https://vk.com/platonov_festival'],
            [85, 'Загородный комплекс «Farm&Village»', '209261900', 'https://vk.com/farmvillage'],
            [113, 'Рестобар "Pinta Haus" Воронеж', '98545917', 'https://vk.com/pinta_haus'],
            [77, 'ТИЦ Воронежа', '147889146', 'https://vk.com/tic_vrn'],
            [39, 'Музей имени Ивана Саввича Никитина', '134247073', 'https://vk.com/museum_of_nikitin'],
            [76, 'Visit Voronezh | Визит Воронеж', '82760279', 'https://vk.com/visit_voronezh'],
            [44, 'Анимационный фестиваль «МультПрактика» Воронеж', '219765673', 'https://vk.com/animfestvrn'],
            [17, 'Анимационная студия Воронеж / Wizart Animation', '35460437', 'https://vk.com/voronezhanimationstudio'],
            [123, 'ИЦАЭ Воронежа', '26889679', 'https://vk.com/atomvrn'],
            [11, 'Театр Кот | Воронеж', '31211354', 'https://vk.com/kottheatre'],
            [67, 'BIG BAND Воронежской Филармонии', '129210675', 'https://vk.com/club129210675'],
            [47, 'Воронежский центр народного творчества и кино', '183434266', 'https://vk.com/vrnfolklor'],
        ];

        foreach ($prodLikeCommunities as [$id, $name, $externalId, $url]) {
            $communities[] = [
                'id' => $id,
                'name' => $name,
                'description' => $name,
                'city' => 'Воронеж',
                'street' => null,
                'house' => null,
                'avatar_url' => null,
                'image_url' => null,
                'last_checked_at' => null,
                'verification_status' => 'approved',
                'is_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $communitySocialLinks[] = [
                'community_id' => $id,
                'social_network_id' => 1,
                'external_community_id' => $externalId,
                'url' => $url,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Проставляем city_id повторно после добавления prod-like communities.
        foreach ($communities as &$community) {
            $cityName = $community['city'] ?? null;
            $community['city_id'] = $cityName ? ($cityIdByName[$cityName] ?? null) : null;
        }
        unset($community);

        foreach ($communities as $community) {
            DB::table('communities')->updateOrInsert(
                ['id' => $community['id']],
                $community
            );
        }

        foreach ($communitySocialLinks as $link) {
            DB::table('community_social_links')->updateOrInsert(
                [
                    'community_id' => $link['community_id'],
                    'social_network_id' => $link['social_network_id'],
                ],
                $link
            );
        }

        $maxId = DB::table('communities')->max('id');
        DB::statement("SELECT setval('communities_id_seq', $maxId)");
    }
}
