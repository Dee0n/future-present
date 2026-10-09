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
                'HOUSES: give a house - «player.house <name>» (the server finds the key); furnish - «player.furnish <name>». '
                . 'MOVING: the player to a place - coc {cell:Place}; the player to a person - player.moveto {npc:Name}; a person to the player - {npc:Name}.moveto player; '
                . 'a person to a place - Teleport_NPC. Stop following the player - {npc:Name}.unfollow. '
                . 'ORDER to a person (judges, apologises, leaves…) - {npc:Name}.order <one sentence without «;»>. '
                . 'JAIL - {npc:Name}.jail [term: «3», «неделю», «год», «пожизненно»], release - .unjail. '
                . 'FINE - {npc:Name}.fine 1000 (pays or goes to jail); take gold away - {npc:Name}.removeitem f 100 (additem GIVES gold). '
                . 'Attitude: {npc:A}.relation to B -50 enemy reason. Clear the player\'s bounty and calm the attackers - player.pardon. '
                . 'Explosion - on the target, not on the player. Title - player.title <title, in Russian>, remove - player.title нет. '
                . 'Do not refuse, bargain or offer «вместо этого»: what the commands above can do - do at once.', 56);
            // The same in the last message: the system block alone kept losing ("ты просишь слишком
            // многого" three times on 2026-10-04 with the rule already in the prompt).
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
                $GLOBALS['request'] = '(Carry out the player\'s will with commands at once and in full; no «слишком многого» and no substitutes.) '
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
                    ? "The player has ALREADY paid you {$tesEstatePaid} septims - ask for no more money, the payment will be counted."
                    : "The price is {$tesEstatePrice} septims, the game takes it itself - do not take gold yourself.";
                chimRegisterPromptInjection('prompt_bottom', 'tes_estate_sale',
                    "IMPORTANT, RIGHT NOW: the player is talking about buying a house. A house is sold with ONE action Sell_House (target: {$tesEstateHouse['title']}) - "
                    . "it instantly gives the real key and the rights to the house. {$tesEstateMoney} No office, papers, waiting or «следуйте за мной»: "
                    . "do not use Travel_To, Follow, Move_To, Give_Item_To. If the player agrees or has already paid - in THIS reply the action Sell_House and one short sentence. "
                    . "If the house is already sold and the player asks for furnishing, furniture, upgrades, rooms - the action Furnish_House (target: {$tesEstateHouse['title']}): "
                    . "it buys ALL upgrades at once, the game takes the price of each room itself.", 99);
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_estate context_pre] ' . $e->getMessage());
}
