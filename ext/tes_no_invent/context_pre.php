<?php
/*
 * tes_no_invent: a small, constant anti-hallucination instruction added to EVERY
 * character's prompt (Narrator and regular NPCs alike), not just the Narrator's own
 * turns like ext/tes_god_journal's hook.
 *
 * Why this exists (see docs/applied-log.md, 2026-09-29): Лилит Ткачиха confidently
 * invented a whole shared backstory ("мы предложили десять миллионов", "старушка
 * Фрида у фонтана") that never happened, and insisted it was real when challenged.
 * This is a known trait of fast/cheap ("flash"-tier) models trading groundedness for
 * speed - not something a code fix removes, but a short, explicit instruction is the
 * standard mitigation and costs only a couple dozen tokens per turn.
 *
 * Deliberately NOT gated on tesGodJournalIsNarratorTurn() (or any narrator check) -
 * this must apply to ordinary NPC dialogue too, which is where the actual incident
 * happened.
 */

if (isset($GLOBALS["db"]) && function_exists('chimRegisterPromptInjection')) {
    chimRegisterPromptInjection(
        'prompt_bottom',
        'tes_no_invent',
        'Do not invent events that never happened. If unsure something really happened between you and the other speaker, do not state it as fact or cite nonexistent shared memories - rely only on the real conversation history and memory actually given to you. Lines under «Happened Recently» and «Moments Ago» were said minutes ago, in this same scene - do not treat them as from yesterday or long ago.',
        60
    );
}
