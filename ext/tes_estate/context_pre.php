<?php
/*
 * tes_estate prompt lines.
 *  - Narrator: how to give houses and move people (verbs handled by tes_god_guard).
 *  - A steward/jarl the player is asking about a house: use Sell_House NOW. Live 2026-10-03:
 *    Proventus had the action in his list for an hour and kept saying "follow me to my
 *    office" - the 80k prompt full of his own earlier lines outweighed the action text.
 */

try {
    if (function_exists('chimRegisterPromptInjection')) {
        $tesEstateSpeaker = strval($GLOBALS['HERIKA_NAME'] ?? '');
        $tesEstateNarrator = in_array(strval($GLOBALS['gameRequest'][0] ?? ''), ['narrator_inputtext', 'narration', 'narrator_welcome', 'narrator_quest_comment'], true)
            || strval($_GET['profile'] ?? '') === md5('The Narrator')
            || $tesEstateSpeaker === 'The Narrator';
        if ($tesEstateNarrator) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_estate',
                // compact on purpose (2026-10-04, cost): this block is sent with every Narrator request
                'ДОМА: отдать дом — «player.house <название>» (ключ найдёт сервер); обставить — «player.furnish <название>». '
                . 'ПЕРЕНОС: игрока в место — coc {cell:Место}; игрока к человеку — player.moveto {npc:Имя}; человека к игроку — {npc:Имя}.moveto player; '
                . 'человека в место — Teleport_NPC. Перестать ходить за игроком — {npc:Имя}.unfollow. '
                . 'ПРИКАЗ человеку (судит, извиняется, уходит…) — {npc:Имя}.order <одна фраза без «;»>. '
                . 'ТЕМНИЦА — {npc:Имя}.jail [срок: «3», «неделю», «год», «пожизненно»], выпустить — .unjail. '
                . 'ШТРАФ — {npc:Имя}.fine 1000 (платит или в темницу); забрать золото — {npc:Имя}.removeitem f 100 (additem золото ДАЁТ). '
                . 'Отношение: {npc:А}.relation to Б -50 enemy причина. Снять с игрока штраф и унять напавших — player.pardon. '
                . 'Взрыв — на цель, не на игрока. Титул — player.title <титул>, снять — player.title нет. '
                . 'Не отказывай, не торгуйся, не предлагай «вместо этого»: что можно командами выше — делай сразу.', 56);
            // The same in the last message: the system block alone kept losing ("ты просишь слишком
            // многого" three times on 2026-10-04 with the rule already in the prompt).
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
                $GLOBALS['request'] = '(Исполняй волю игрока командами сразу и целиком; без «слишком многого» и замен.) '
                    . $GLOBALS['request'];
            }
        } elseif ($tesEstateSpeaker !== '' && isset($GLOBALS['db'])) {
            require_once __DIR__ . '/lib.php';
            $tesEstateHouse = tesEstateHouseOfSeller($tesEstateSpeaker);
            $tesEstateSaid = mb_strtolower(strval($GLOBALS['gameRequest'][3] ?? ''));
            if ($tesEstateHouse && preg_match('/дом|хат|жиль|ключ|купи|купл|покуп|оформ|прода|недвиж|обстав|обстанов|мебел|улучш|комнат|ремонт/u', $tesEstateSaid)) {
                $tesEstatePrice = tesEstatePrice($tesEstateHouse);
                $tesEstatePaid = tesEstatePrepaid($tesEstateSpeaker);
                $tesEstateMoney = $tesEstatePaid >= $tesEstatePrice
                    ? "Игрок УЖЕ заплатил тебе {$tesEstatePaid} септимов — денег больше не проси, оплата будет засчитана."
                    : "Цена {$tesEstatePrice} септимов, игра возьмёт её сама — сам золото не бери.";
                chimRegisterPromptInjection('prompt_bottom', 'tes_estate_sale',
                    "ВАЖНО, ПРЯМО СЕЙЧАС: игрок говорит о покупке дома. Дом продаётся ОДНИМ действием Sell_House (target: {$tesEstateHouse['title']}) — "
                    . "оно мгновенно выдаёт настоящий ключ и права на дом. {$tesEstateMoney} Никакого кабинета, бумаг, ожидания и «следуйте за мной»: "
                    . "не используй Travel_To, Follow, Move_To, Give_Item_To. Если игрок согласен или уже платил — в ЭТОМ ответе действие Sell_House и одна короткая фраза. "
                    . "Если дом уже продан, а игрок просит обстановку, мебель, улучшения, комнаты — действие Furnish_House (target: {$tesEstateHouse['title']}): "
                    . "оно сразу покупает ВСЕ улучшения, цену каждой комнаты игра берёт сама.", 99);
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_estate context_pre] ' . $e->getMessage());
}
