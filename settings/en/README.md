# English prompt texts in the database (prepared 2026-10-09, NOT applied)

**Кратко для владельца.** Здесь лежит подготовленный, но не применённый перевод на английский
инструкций для модели, которые хранятся в базе `dwemer`: шапка NPC, шапка и профиль
Рассказчика, 61 промпт из менеджера промптов и инструкция дневника.
NPC и Рассказчик по-прежнему отвечают по-русски: правило языка стоит в начале и в конце каждой
шапки, русские образцы речи («опять двадцать пять», «сто золотых») оставлены русскими.
Применение — один файл `01_prompts_en.sql` после вашего «да», откат — `01_prompts_en_rollback.sql`.
Экономия по оценке: около 163 токенов на реплику NPC (3,3 %) и около 48 на запрос Рассказчика
(0,6 %); ещё около 400 на запрос Рассказчика даёт описание действия God_Command, его возвращает
соседний файл `02_data_en.sql`. Один служебный запрос станет дороже примерно на 140 токенов:
оценка отношений (`rel_llm_evaluation`) получает актуальный набор правил ядра вместо устаревшего
русского перевода — см. «Changed in review».

## What goes to the model today

Confirmed on the last entry of `/var/www/html/HerikaServer/log/context_sent_to_llm.log`
(NPC «Джон [Стражник Вайтрана]», 2026-10-06) and on the only Narrator entry in that log.

| Stored text | Where | Reaches the model as |
|---|---|---|
| NPC prompt head, 4515 chars | `general_settings.PROMPT_HEAD` | `<roleplay_instructions>` of every NPC request (`main.php:2654`); also the system text of NPC diaries, the player diary and the player speech rewrite |
| Narrator prompt head, 2495 chars | `core_narrator.prompt_head` | replaces `PROMPT_HEAD` in Narrator requests (`lib/core/narrator.class.php:453`) |
| Narrator background, personality, speechstyle, goals | `core_narrator` | `<basic_summary>`, `<personality>`, `<speech_style>`, `<goals>` of every Narrator request |
| 61 translated prompts | `prompts.custom_prompt` | `dialogue_line_response` is the cue of every NPC and Narrator request; the rest are service calls (director, memory, relationships, profiles, rechat) |
| Diary instruction, 250 chars | `core_profiles.metadata.DIARY_PROMPT` (id 1) | last user message of a diary request |
| `God_Command` description, 6530 chars; `Write_Document`, 355 chars | `core_action.description` | action list of every Narrator request / of NPC requests that offer the action. **Not in this file:** `02_data_en.sql` restores both from `tes_backup_core_action_en` |

Not touched, because they are data and not instructions: `conf_opts` (`aiagent_nsfw_settings`
match terms and moan sounds, `aiagent_nsfw_runtime_state`, `chim_renamenpc`), `core_player`
(inventory, equipment, name), NPC profiles in `core_npc_master`. `core_npc_master.prompt_head`
is NULL for all 242 NPCs, `core_profiles.prompt` is empty, `custom_context` and `custom_actions`
are empty.

## How the reply language is enforced

1. The prompt head. Russian head: section «### ЯЗЫК — Живой разговорный русский». English head:
   a `LANGUAGE:` line right after the first line and again as the last line. The line covers
   names as well («names included (Whiterun is said «Вайтран»)»): 75 of the 122 NPC biographies
   are English and name places in Latin letters, and the reply is read by a Russian TTS voice.
2. `LANG_LLM_XTTS = true` in the profile: `functions/json_response.php:305-317` takes the TTS
   language, `:365`/`:389` put `"lang":"ru"` into the answer template and `:537` writes
   `Language to use. Must be ru` into the JSON schema. Unchanged.
3. `lib/core/npc_master.class.php:1170-1172` adds «In Russian always use masculine/feminine
   grammatical forms about yourself (я сказал, я пришёл…)». Already English with Russian examples.
4. `CORE_LANG` is `en` (there is no `lang/ru` folder), so the core's own cues are English already.
5. The context itself (names, history, memory) is Russian.

## Files

| File | Is |
|---|---|
| `prompt_head_en.txt` | NPC prompt head |
| `narrator_prompt_head_en.txt`, `narrator_background_en.txt`, `narrator_personality_en.txt`, `narrator_speechstyle_en.txt`, `narrator_goals_en.txt` | Narrator |
| `diary_prompt_en.txt` | diary instruction |
| `prompts/<prompt_key>.txt` (15 files) | English `custom_prompt` texts that keep a behaviour |
| `build_sql.php` | builds the two SQL files from the texts above; no database access |
| `01_prompts_en.sql` | the apply file (generated) |
| `01_prompts_en_rollback.sql` | the rollback file (generated) |

`02_data_en.sql` and `README_data.md` in this directory are a separate piece of work (Oghma,
item descriptions, the two action descriptions). The two apply files touch different tables and
can run in either order.

To change a text: edit the `.txt`, run `php settings/en/build_sql.php`, review the SQL diff.
A line break at the end of a `.txt` file is not part of the text; trailing spaces are
(`prompts/ai_vision_appearance.txt` ends with «— » on purpose).

## Apply

```bash
psql -U dwemer -d dwemer -v ON_ERROR_STOP=1 -f settings/en/01_prompts_en.sql
```

One transaction. Steps inside the file:

| Step | Does | Rows |
|---|---|---|
| 1 | `CREATE TABLE IF NOT EXISTS tes_backup_ru_prompts` (all prompts), `…_general_settings` (PROMPT_HEAD), `…_core_narrator`, `…_core_profiles` (DIARY_PROMPT) | 62, 1, 5, 1 rows saved |
| 2 | Guard: stops with an error if `PROMPT_HEAD` or the Narrator `prompt_head` is no longer the Russian text the translation was made from (md5 `3d04488a…`, `9d3f228d…`), or if one of the 61 prompt keys is gone | 0 |
| 3 | `prompts.custom_prompt = NULL` where the Russian text was a plain translation | 46 |
| 4 | `prompts.custom_prompt` = English text from `prompts/<key>.txt` | 15 |
| 5 | `general_settings.PROMPT_HEAD` = `prompt_head_en.txt` | 1 |
| 6 | `core_narrator`: `prompt_head`, `background`, `personality`, `speechstyle`, `goals` | 5 |
| 7 | `core_profiles.metadata.DIARY_PROMPT` = `diary_prompt_en.txt` (other metadata keys untouched) | 1 |

The file ends with a check query. Expected output after a successful apply:

```
prompts: custom rows / with Cyrillic letters   | 15 / 2      (custom_oghma and rel_llm_analysis keep Russian examples)
PROMPT_HEAD: chars / Cyrillic letters          | 4846 / 180
narrator prompt_head: chars / Cyrillic letters | 2934 / 40
narrator background | 158 / 0    personality | 144 / 0    speechstyle | 107 / 0    goals | 112 / 0
DIARY_PROMPT: chars / Cyrillic letters         | 263 / 0
```

Settings are read from the database on each request (`lib/settings.php:974`); no cache was found,
so no restart should be needed. Not verified in the game.

## Rollback

```bash
psql -U dwemer -d dwemer -v ON_ERROR_STOP=1 -f settings/en/01_prompts_en_rollback.sql
```

Copies every value back from the `tes_backup_ru_*` tables (61 prompts, PROMPT_HEAD, 5 Narrator
rows, DIARY_PROMPT). The backup tables stay, so apply and rollback can be repeated.

## The 61 prompts

All 61 Russian texts were made by `tools/translate_ru.php` on 2026-10-04 as translations of
`default_prompt`; none has a rule the English default lacks. The split is by what the request
looks like, because a request without the prompt head answers in the language of its instruction.

**46 keys set to NULL** (English default restored):

- cue of an NPC or Narrator request, which carries the prompt head: `dialogue_line_response`,
  `dialogue_line_inline_response`, `dialogue_line_inline_response_narrator`,
  `dialogue_line_inline_response_npc`, `inline_narration_prompt`, `inline_narration_prompt_narrator`,
  `inline_narration_prompt_npc`, `rechat_listener_prompt_relaxed`, `rechat_listener_prompt_strict`,
  `rechat_response_prompt_relaxed_1..3`, `rechat_response_prompt_strict_1..3`, `narrator_bored_prompt`,
  `narrator_welcome_prompt`, `random_narration_prompt`, `quest_comment_prompt`, `book_summary_prompt`;
- requests whose system text includes `PROMPT_HEAD`: `player_diary_prompt`
  (`lib/dynamic_update_util.php:364`), `player_respeech_output_prompt`,
  `player_respeech_output_strip_prompt`, `player_respeech_rewrite_prompt`,
  `player_respeech_rewrite_strip_prompt` (`player_rewrite.php:151`);
- director: `directorSuggestionSystem`, `director_bored_event_rules`,
  `director_bored_event_system_prompt`, `director_examples_prompt`, `director_instruction_rules`,
  `director_system_prompt`. Its output is an instruction for an NPC model, so English is wanted;
- `memory_subsystem_summary` (gets its language rule through `{SUMMARY_PROMPT}`),
  `middleterm_narrative_summarizer` (the rule is in `middleterm_narrative_request`);
- `background_life_innerthought`, `background_life_letter`: the database lookup is switched off in
  `service/processors/backgroundlife/cmd/helpers.php:36`, the text is not sent at all;
- 11 `player_mood_*_prompt` phrases («(говорит сердитым тоном.)» → `(speaks in an angry tone.)`).

**15 keys get an English text** (default + what the Russian version did):

| Key | Kept behaviour |
|---|---|
| `dynamic_prompt_goals`, `_occupation`, `_personality`, `_relationships`, `_skills`, `_speechstyle` | system text is «You are an assistant…» without the prompt head (`lib/dynamic_update_util.php:1302`); the result is stored in the NPC profile, shown in the web UI and sent back to the NPC model. One sentence added: write it in Russian, keep names |
| `character_profile_generation` | same, for new NPC profiles (`ui/cmd/ai_profile_generation_service.php:293`) |
| `player_speech_style_prompt` | describes how the player speaks Russian |
| `ai_vision_appearance` | description in Russian, starts with «{HERIKA_NAME} — » as the Russian version did |
| `middleterm_narrative_request` | bullet text in Russian; headings stay as the core asks for them (`service/processors/middleterm/cmd/generate.php:164`) |
| `summary_prompt` | memory summaries in Russian (they are searched with the player's Russian speech) |
| `rel_llm_npc_to_npc` | `reason` in Russian (it is shown in the NPC context: «Шаман: -2 (Neutral, Neutral) - …») |
| `rel_llm_evaluation` | `reason` in Russian. The base text is NOT `default_prompt` but the text the core really sends for an empty `custom_prompt`: `ext/relationship_system/relationship_llm.php:1349` replaces the seeded default («only for defining moments», no `"type"`) with its built-in rule set (`:1305-1343`, exact list of `type` values). A custom text that merely mentions `"type"` switches that replacement off, so the file holds the built-in rule set plus the language sentence |
| `rel_llm_analysis` | `note` in Russian and the Russian in-game names for faction, race and occupation targets («Братья Бури», «Имперский», «Стражник», «Серебряная Рука», «Каджит») |
| `custom_oghma` | English rules, Russian examples («драконы→дракон», «Седобородыми → Седобородый») |

If stored data may turn English as well, these 15 can also be set to NULL:
`UPDATE public.prompts SET custom_prompt = NULL WHERE prompt_key IN (…)`.

Three defects of the Russian translations disappear with the English defaults:
`director_instruction_rules` offered the action «ПростоПоговорить» instead of `JustTalk`;
`rel_llm_analysis` told the model to store the player as «Игрок» although the code looks for
`Player` (`ext/relationship_system/relationship_llm.php:923`) and listed the relationship types in
Russian next to an English example; `middleterm_narrative_request` asked for Russian headings while
the core appends «Begin your answer with `### Notable Events in Chronological Order`».

## Measured sizes

Characters and Cyrillic letters are counted from the live database (before) and from the files
here (after). Tokens are an estimate: 3.54 characters per token for Russian prose, 4.36 for the
rest; a Russian stretch is counted from its first to its last Cyrillic letter. No tokenizer was run.

| Text | Before: chars / Cyrillic / ~tokens | After: chars / Cyrillic / ~tokens | Saved |
|---|---|---|---|
| NPC prompt head | 4515 / 3413 / 1265 | 4846 / 180 / 1125 | 140 (11 %) |
| Narrator prompt head | 2495 / 1686 / 683 | 2934 / 40 / 675 | 8 (1 %) |
| Narrator background + personality + speechstyle + goals | 490 / 388 / 138 | 521 / 0 / 120 | 18 |
| `DIARY_PROMPT` | 250 / 167 / 68 | 263 / 0 / 60 | 8 |
| `dialogue_line_response` | 283 / 220 / 78 | 243 / 0 / 56 | 23 (29 %) |
| 46 prompts set to NULL | 15264 / 11357 / 4203 | 14173 / 0 / 3251 | 952 (23 %) |
| 15 prompts with English text | 11428 / 8476 / 3150 | 13103 / 177 / 3015 | 135 (4 %); `rel_llm_evaluation` alone grows by 847 chars, ~194 tokens |
| All 61 prompts | 26692 / 19833 / 7352 | 27276 / 177 / 6266 | 1086 (15 %) |
| `God_Command` description (changed by `02_data_en.sql`, shown for the Narrator total) | 6530 / 4145 / 1752 | 5814 / 305 / 1352 | 400 (23 %) |

The heads save less than the 32 % measured for plain instruction text for three reasons: the
two `LANGUAGE` lines are new (about 75 tokens in the NPC head; 65 in the Narrator head, plus 26
for its rule about Russian command arguments), the Russian speech samples stay Russian, and the
Russian originals are terse, so the English of the same rules is about as long in characters.

Per request:

| Request | Saved | Share of the logged request |
|---|---|---|
| NPC reply (head + cue) | ~163 tokens | 3.3 % of ~4893 |
| NPC rechat turn (head + cue + rechat cue + listener hint) | ~180 tokens | |
| Narrator (head + 4 fields + cue) | ~48 tokens | 0.6 % of ~7559 |
| Narrator, together with the `God_Command` description of `02_data_en.sql` | ~448 tokens | 5.9 % of ~7559 |
| NPC diary (head + diary instruction) | ~148 tokens | |
| Relationship evaluation call | costs ~140 tokens MORE than the Russian text (the Russian translation was made from the outdated default; the English text is the core's current rule set, ~27 tokens above what the core sends for an empty `custom_prompt`) | |
| Middle-term summary call | ~140 tokens | |

## Risks

- Reply language now rests on the two `LANGUAGE` lines, `"lang":"ru"` and the Russian context.
  Not tested against the model (no LLM call was allowed while preparing this). Test before a long
  session: one NPC reply, one rechat turn, one Narrator command, one diary.
- Speech register. The Russian head taught colloquial Russian by being written in it. The English
  head keeps the samples in Russian («ты», «окей», «опять двадцать пять», «мозгов как у грязевого
  краба», «сейчас принесу», «конечно, вот что я скажу», the trigger phrases «иду», «пойдём»…), but
  replies may get a little more bookish or drift to «вы».
- Swearing. «матерятся свободно» became «swear freely (мат)». A model may be more reserved with
  an English instruction.
- Narrator command arguments. The Russian head showed Russian placeholders («новый характер», «за
  что», «что случилось»). The English head says «Free text inside a command … is written in
  Russian»; if the model ignores it, rewritten personalities and memories become English.
- Director instructions and scene notes will probably come out in English. They are instructions
  for the NPC model, but they are also stored in the event log.
- `player_mood_*`: the phrase is appended to the player's line in the history, so an English
  «(speaks in an angry tone.)» will sit inside a Russian line. Used only when a mood is picked in
  Prisma Chat.
- The English texts of step 4 change what service calls return only if the model disobeys the
  added sentence; the existing data is mixed already (87 of 170 memory summaries and 55 of 242 NPC
  goals are Russian, the rest English).
- `tools/translate_ru.php prompts` (or `all`) translates every prompt whose `custom_prompt` is
  empty: it would undo step 3.
- `tools/apply_fixes_2026-10-04b.sh` inserts the Russian rule «Сказал — сделал» after the line
  «### ДЕЙСТВИЯ» when the head does not contain it. That line is gone in the English head, so a
  re-run changes nothing, but the script no longer describes the live head.
- The settings preset «Gemini» (`global_settings_presets` id 1) holds a Russian `PROMPT_HEAD` of
  4103 chars. Loading that preset in the CHIM UI brings the old Russian head back.
- The CHIM web UI will show these texts in English (Prompts Manager, general settings, Narrator
  page). The Russian originals stay readable in `tes_backup_ru_*`, `settings/prompt_head_ru.txt`
  (one rule behind the live head: «Сказал — сделал») and `settings/narrator_ru.sql`.
- Stored data stays Russian by choice, and that is the owner's call. The added sentences of step 4
  and the Narrator rule «Free text inside a command … is written in Russian» make the model WRITE
  Russian into NPC profiles (goals, personality), memory summaries and relationship reasons. That
  text is sent back to the model on later requests at the Russian token price. Under «English for
  the model, Russian only on display» these would be English; they were kept Russian because memory
  search runs on the player's Russian speech and no display translation exists yet for the core
  pages (`README_data.md`, section 6).
- The guard of step 2 covers the two heads only. A `custom_prompt`, a Narrator profile field or
  `DIARY_PROMPT` edited by hand before the apply is replaced without a stop (the old text stays in
  `tes_backup_ru_*`). On 2026-10-09 every `custom_prompt` still carried the translation timestamp
  (2026-10-04 13:15:12 … 13:17:52).
- `prompts/rel_llm_evaluation.txt` is a copy of the core's built-in rule set. A later core update
  of that rule set will not reach the game while this `custom_prompt` is set.
- The schema `chim_profile_dragon_break_26th_of_sun_s_dusk_4e_201_1` has its own `prompts`,
  `core_narrator`, `core_profiles` and a `general_settings.PROMPT_HEAD` of 3561 chars. This file
  writes to `public` only. The six `chim_profile_save_*` snapshots hold no `PROMPT_HEAD`
  (`chim_meta.is_global_setting('general_settings','PROMPT_HEAD')` is true), so loading a save
  does not bring the Russian head back.

## Changed in review (2026-10-09)

- Heads: the sentence «Keep names exactly as written in the context» is gone from both `LANGUAGE`
  lines. It contradicted «Cyrillic only» whenever the context gives a name in Latin letters
  (English biographies, English Oghma articles after `02_data_en.sql`) and would have put
  «Whiterun» into a line read by the Russian voice. Now: «names included (Whiterun is said
  «Вайтран»)». The Russian head never had a names rule.
- `rel_llm_evaluation`: rebuilt from the core's built-in rule set (see the table above).
- Checked read-only on the live database: every UPDATE and the final SELECT of the apply file
  parse (`PREPARE` in a `READ ONLY` transaction, 24 statements), the guard block runs and passes,
  the WHERE clauses match 46 / 15×1 / 1 / 5 / 1 rows, the four backup SELECTs return 62 / 1 / 5 / 1
  rows, and the four rollback UPDATEs parse against sub-selects of the same shape as the backup
  tables. No write, no LLM call.
