<?php
/*
 * tes_world: drink for sale. Live 22:58-23:00: the owner gave Огман 150 gold for "150 бутылок эля", Огман had no ale
 * and handed over nothing ("А где Эль?"). What the owner asks an NPC to bring or sell - "дай/давай/продай/принеси/
 * налей N бутылок эля/вина/мёда" - he gets: the bottles come into his bag (the price is whatever he handed over).
 * And whoever the owner makes the tavern-keeper ("ты сегодня хозяин таверны", "продавай эль") gets a stock to sell.
 */

if (!function_exists('tesDrinksSpoken')) {
    // Skyrim.esm: Ale 00034C5E, Red wine 0003133C (seen in this very log as 0x34C5E / 0x3133C), Nord mead 00034C5D
    function tesDrinksKind(string $t): array
    {
        if (preg_match('/(?<![\p{L}])(вин[оау]|винц\p{L}*)(?![\p{L}])/u', $t)) {
            return ['0003133C', 'red wine'];
        }
        if (preg_match('/(?<![\p{L}])(м[её]д\p{L}*|медовух\p{L}*)(?![\p{L}])/u', $t)) {
            return ['00034C5D', 'mead'];
        }
        return ['00034C5E', 'ale'];
    }

    function tesDrinksSpoken(string $line, string $to): string
    {
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        $drink = '(?:эл[ьяюеи]|эл[ьи]|пив[оау]|вин[оау]|м[её]д\p{L}*|медовух\p{L}*|алкогол\p{L}*|выпивк\p{L}*|бутыл\p{L}*|кружк\p{L}*|бочк\p{L}*)';
        if (!preg_match('/(?<![\p{L}])' . $drink . '(?![\p{L}])/u', $t)) {
            return '';
        }
        $isOwnerAsking = preg_match('/(?<![\p{L}])(дай|дайте|давай|давайте|принеси\p{L}*|неси|продай\p{L}*|продавай|налей\p{L}*|наливай|тащи|доставай|подай\p{L}*|организуй)(?![\p{L}])/u', $t)
            && !preg_match('/(?<![\p{L}])(не\s+(?:\p{L}+\s+)?(?:давай|неси|наливай)|нельзя)(?![\p{L}])/u', $t);
        if (!$isOwnerAsking || preg_match('/(?<![\p{L}])(дай|давай)\s+(?:мне\s+)?(?:\p{L}+\s+)?(?:золото|денег|деньги)/u', $t)) {
            return '';
        }
        [$formid, $name] = tesDrinksKind($t);
        // how many: digits ("150 бутылок") or words ("сто бутылок")
        $n = 0;
        if (preg_match('/(?<![\p{L}\d])(\d{1,3})(?![\d\p{L}])\s*(?:бутыл\p{L}*|кружк\p{L}*|эл|вин|м[её]д|штук)/u', $t, $m) || preg_match('/(?<![\p{L}\d])(\d{1,3})(?![\d\p{L}])/u', $t, $m)) {
            $n = intval($m[1]);
        } elseif (function_exists('tesWorldSpokenAmount')) {
            $n = intval(tesWorldSpokenAmount($t));
        }
        if ($n <= 0 || $n > 300) {
            $n = $n > 300 ? 300 : 10;
        }
        // "хозяин таверны / торговец / продавай" said to an NPC: he gets the stock to sell (no delivery to the player)
        if (preg_match('/(?<![\p{L}])(продавай|торгуй|хозяин\p{L}*\s+таверн\p{L}*|торгов(?:ец|цем))(?![\p{L}])/u', $t) && $to !== '' && stripos($to, 'Narrator') === false && !preg_match('/(?<![\p{L}])(дай|давай|принеси)/u', $t)) {
            $ref = tesWorldRefOf($to);
            if ($ref !== '') {
                tesWorldQueue(['prid ' . $ref, 'additem 00034C5E 60', 'additem 0003133C 30', 'additem 00034C5D 30']);
                return " *{$to} got a stock: ale, wine, mead - he sells it; already done*";
            }
        }
        tesWorldQueue(['player.additem ' . $formid . ' ' . $n]);
        return " *{$n} bottles of {$name} are already in the player's bag - you were asked and brought them; done*";
    }
}
