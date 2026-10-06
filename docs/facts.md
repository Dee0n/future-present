# Справка: факты о CHIM и Skyrim для разработки

Вынесено из старого ROADMAP (2026-09-28). Теги: `[код]` — из исходников, `[док]` — из документации, `[не проверено]` — сведений нет.

## 1. Подтверждённые факты о CHIM `[код]`

**Действия и результат**
- `console_command` выполняется через `ConsoleUtil.ExecuteCommand`; клиент отвечает серверу «успех» **в момент отправки**, результат не читается. Для квестовых действий клиент считывает стадию до и после и ставит флаг `verified` (`BuildQuestProgressionQuestState`).
- `ConsoleUtil.ReadMessage()` уже используется в CHIM (например, для `ShowFullQuestLog`): чтение вывода консоли возможно.
- Механизм `funcret`: результат действия возвращается на сервер и снова вызывает LLM. Доки предупреждают, что примеры устарели: сигнатуры сверять с текущим кодом.
- **ScriptProxy:** 135 команд по `cmdID` (`PlaceAtMe`, `MoveTo`, `AddItem`, `AddSpell`, `SetRace`, `SetOutfit`, `Resurrect`, `SetFactionRank`, `SetEnemy`, `SetActorOwner`, `SetCrimeGold`, `SendAssaultAlarm`, `SendPlayerToJail`, `SpawnDoor` …). Отправка: `rolecommand|ScriptProxy@{json}`. Команды `Get*` только пишут `Debug.Trace` и **ничего не возвращают серверу**.
- **Мост `ExtCmd<Скрипт>_<Действие>`** вызывает статическую функцию `DispatchExternalCommand` в собственном Papyrus-скрипте (документирован Dwemer).
- Papyrus → сервер: `AIAgentFunctions.logMessageForActor(...)`.
- `SpawnDoor` создаёт уменьшенную дверь-портал к существующей ссылке (PO3), новых пространств не создаёт.

**События и мир**
- Клиент ловит около 35 типов игровых событий (удар, смерть, бой, контейнер, замок, сон, ожидание, книга, сцена, стадия квеста и др.). События Story Manager (кражи, преступления) **клиент не слушает**.
- Слух, шёпот, линия видимости с учётом дверей: `SpatialAwareness`.
- Клиент перехватывает `DialogueMenu` (хуки), умеет скрывать его; есть роутер разговоров и событие `TESTopicInfoEvent`.
- CHIM уже имеет действия `AddBounty`, `PayBounty`, `ArrestPlayer`, `ForgiveCrime` (решаются ИИ стражника), Background Life (мысли, письма `SendLetter`, слухи, работа, торговля, путешествия), `Create_New_NPC` (`rolemaster/cmd/spawncharacter.php`), дневники NPC как читаемые книги (`DynamicDiaryBook`).
- **Нарратор в CHIM один:** хранится как пары «ключ → значение» в `core_narrator`. Готового механизма нескольких «голосов без тела» нет. В SkyrimNet такой механизм есть (виртуальные NPC).
- Плагинная система: `HerikaServer/ext/<name>/` с хуками (`json_response_custom.php` и др.), пакеты `.dwpkg`, API данных плагина для NPC (`getPluginData/setPluginData`). В upstream уже есть `godmode.php`, который пересылает off-stage запрос в rolemaster-воркер: возможен конфликт с твоим God mode.
- **Откат при загрузке старого сейва:** сервер откатывает выбранные таблицы. Таблицы плагинов в откат автоматически **не входят**: их надо явно объявить глобальными или привязанными к прохождению; для памяти NPC — использовать API данных плагина.

**Наблюдения из практики (проверено владельцем и агентом в игре, 2026-09)**
- Плагин отвечает `applied` **в момент отправки**, а не по факту выполнения.
- Запись `ID.resurrect` молча не срабатывает. `prid` отдельной записью в очереди теряется до следующей команды. Работает только `prid ID` плюс команда **одной последовательностью** (`console_command_sequence`).
- `disable` и `enable` ломают модель NPC (NPC улетел, потом стал невидимым). Лечится `recycleactor` плюс `moveto player`. Внести в промпт нарратора и в валидатор.
- Баг нарратора: строки, начинающиеся с `{` (`{npc:Имя}.resurrect`), обработчик принимал за JSON и выбрасывал. Исправлено (строка, не разобранная как JSON, считается командой), нарратору запрещено «воскрешать» созданием нового NPC (`Create_New_NPC`). **Исправление в игре не проверено.**
- **Живой статус NPC уже хранится:** `core_npc_master.metadata.activity_status` (`is_dead`, `is_in_combat`, `current_action`), игра обновляет его сама, **пока не стоит пауза**. Значит, данные могут быть старыми: при проверке сверять время записи. Это готовый дешёвый способ проверить «жив или мёртв» без нового Papyrus.
- Версия игры: **Skyrim SE 1.5.97** (MO2, профиль RFAD_SE).
- **Nether's Follower Framework не установлен**; CHIM без него только добавляет NPC фракции спутника.

**CHIM-MCP** (сервер MCP по SSE, порт по умолчанию 3100, **только чтение**): `query_eventlog`, `get_npc`, `list_npcs`, `search_oghma`, `get_diaries`, `get_memories`, `get_memory_summaries`, `get_quests`, `get_speech_log`, `get_config`, `list_connectors`, `list_profiles`, `read_file`, `list_files`, `search_files`, `run_query` (произвольный SQL на БД `dwemer` или `stobe`). Это чтение базы и файлов сервера, **не живое состояние Papyrus и не запись в игру**.

---

## 2. Подтверждённые факты об игре `[док]`

- **Версия игры 1.5.97 (SE):** всё, что зависит от SKSE, PO3, Address Library, DPF и сборки C++-клиента, нужно проверить на совместимость именно с этой версией `[не проверено]`.
- **Владение:** `Cell.SetActorOwner/SetFactionOwner`, `ObjectReference.SetActorOwner/SetFactionOwner`, `Cell.GetActorOwner`. Объекты со своим владельцем не меняются автоматически.
- **Услуги без диалога:** `Actor.ShowBarterMenu()` (актёр должен быть загружен, условия торговой фракции выполнены), `Actor.ShowGiftMenu`, `Game.ShowTrainingMenu(akSpeaker)`. Аренду и найм воспроизводят скрипты.
- **`ObjectReference.Say`:** нужен SEQ-файл; **если актёр в этот момент сам начнёт обычный диалог (случайное приветствие), игра вылетает.** Ванильные приветствия надо глушить на время реплик.
- **Преступления:** награда возникает **только при свидетеле**; убийство всех свидетелей до доноса снимает награду; награды считаются по холдам. Свидетелями бывают и животные.
- **Story Manager:** `OnStoryCrimeGold(akVictim, akCriminal, akFaction, aiGoldAmount, aiCrime)` (0 кража, 1 карманная кража, 2 проникновение, 3 нападение, 4 убийство), также `OnStoryDiscoverDeadBody`, `OnStoryKillActor`, `OnStoryPickLock`, `OnStoryAssaultActor`, `OnStoryArrest/Jail/ServedTime/PayFine/EscapeJail`, `OnStoryHello`, `OnStoryDialogue`, `OnStoryBribeNPC/Flatter/Intimidate`. Квест запускается **примерно через 20 секунд** после преступления. Нужен квест на узле Story Manager (правка ESP: Creation Kit или Spriggit).
- **Функции преступлений:** `Faction.SetCrimeGold/ModCrimeGold/PlayerPayCrimeGold/SendPlayerToJail`, `Actor.SendAssaultAlarm/SendTrespassAlarm`, `ObjectReference.SendStealAlarm`, `Game.ServeTime/ClearPrison`, `Game.SetPlayerReportCrime` (описана как выключатель того, что преступления игрока докладываются; проверена только страница Fallout 4 — **не проверено для Skyrim**).
- **Состояние читается:** `Quest.GetCurrentStageID/IsStageDone/IsObjectiveCompleted`, `ObjectReference.GetItemCount`, `Actor.IsDead/IsInCombat/GetFactionRank/GetRelationshipRank/HasLOS/IsDetectedBy`, `GetCurrentLocation`, `Faction.GetCrimeGold`.
- **Значения ИИ:** `Aggression` 0-3 (0 — не начинает бой), `Confidence` 0-4, `Morality` 0-3 (согласится ли последователь на преступление), `SetRelationshipRank` -4…4.
- **Внешность и имена:** `Form.SetName`, `ActorBase.SetFacePreset/SetHairColor/SetHeight/SetWeight/SetNthHeadPart`, `Actor.ChangeHeadPart/RegenerateHead/QueueNiNodeUpdate/SetRace/SetOutfit`, PO3 `ReplaceFaceTextureSet/ReplaceSkinTextureSet/SetSkinColor`. По описанию PO3 большинство `Set*` для актёров живёт **одну игровую сессию**: применять заново при загрузке сейва (`OnPlayerLoadGame`; в клиенте CHIM есть такой обработчик).
- **Новые формы:** `Form.TempClone` живёт только в сессии и работает не для всех типов. Мод **DPF (Dynamic Persistent Forms)** создаёт и сохраняет в сейве: Spell, MagicEffect, Enchantment, Potion, Scroll, Armor, Weapon, Book (том заклинания), Ingredient, MiscObject, Ammo, SoulGem, Flora, LeveledItem. **Актёров нет.** Нужен свой ESP; не безопасно менять список модов посреди прохождения; последнее обновление 25.04.2024.
- **Экономика:** `Form.SetGoldValue`, `Game.SetGameSetting*`, `Faction.GetBuySellList`, `GetVendorFactionContainer`, `AddItem/RemoveItem`. Динамические цены делают моды Trade Routes и Trade & Barter; риск утечки цен между сейвами в одной сессии.
- **Papyrus-скрипты:** загружаются при первом запуске; обновлять посреди прохождения можно; `reloadscript` капризна (возможен вылет) `[форум]`.
- **Hearthfire:** стадии `BYOHHouseFalkreath/Hjaalmarch/Pale` 30–120 = покупка земли, приезд, чертёжный стол, фундамент. Стадии 130 и 1010–1120 **пустые**. Комнаты строит верстак и квест `BYOHHouseBuilding`. `setstage` дом не достраивает. Предпосылки: 9-й уровень, письмо, одобрение ярла и др.
- **Солстхейм:** основная линия — 7 квестов (Dragonborn, The Temple of Miraak, The Fate of the Skaal, Cleansing the Stones, The Path of Knowledge, The Gardener of Men, At the Summit of Apocrypha). `setstage DLC2MQ01 10` стартует линию `[форум]`; у финала при принудительных стадиях бывает застрявший боевой режим. Для компенсации шаутов: `Game.UnlockWord/TeachWord`.
- **Ярлы:** у 8 холдов из 9 есть замена (у Haafingar её нет); оба актёра существуют в мире одновременно, свергнутый уходит в изгнание, не удаляется. Новый ярл получает приставку «Jarl» и переезжает в резиденцию. Ключевые NPC квестов «посажены» на эти места. Существуют моды «Jarl of Solitude», «Become a Jarl», «Be a Jarl».

| Холд | Первый ярл | Замена | Резиденция |
|---|---|---|---|
| The Pale (Dawnstar) | Skald | Brina Merilis | The White Hall |
| Falkreath Hold (Falkreath) | Siddgeir | Dengeir of Stuhn | Jarl's Longhouse |
| The Reach (Markarth) | Igmund | Thongvor Silver-Blood | Understone Keep |
| Hjaalmarch (Morthal) | Idgrod Ravencrone | Sorli the Builder | Highmoon Hall |
| The Rift (Riften) | Laila Law-Giver | Maven Black-Briar | Mistveil Keep |
| Whiterun Hold (Whiterun) | Balgruuf the Greater | Vignar Gray-Mane | Dragonsreach |
| Eastmarch (Windhelm) | Ulfric Stormcloak | Brunwulf Free-Winter | Palace of the Kings |
| Winterhold (Winterhold) | Korir | Kraldar | Jarl's Longhouse |
| Haafingar (Solitude) | Elisif the Fair | нет замены | Blue Palace |

---

## 3. Пределы: что нельзя и чем заменить

| # | Нельзя | Причина | Вместо этого |
|---|---|---|---|
| 1 | Новые локации и геометрия | `SpawnDoor` даёт только дверь-портал к существующей ссылке | Маршруты и сцены из существующих ячеек, смена названий и владельцев |
| 2 | Достроить Hearthfire через `setstage` | Стадии комнат пустые, строит верстак | Выдать материалы и строить через верстак; править квестовые переменные (`[не проверено]`) |
| 3 | Новый NPC с нуля | Нет создания ActorBase; `TempClone` на сессию; DPF актёров не поддерживает | Шаблон + имя, внешность, наряд; применять заново при каждой загрузке |
| 4 | Отключить ванильный диалог целиком | Торговля, обучение, аренда и часть квестов — темы диалога | Заменить вход (E → голос); услуги через `ShowBarterMenu` и `ShowTrainingMenu` |
| 5 | Мгновенная реакция через Story Manager | Квест стартует примерно через 20 секунд | Видимость и слух для реакции на месте; Story Manager для итогов |
| 6 | Любая линия квестов через `setstage` без потерь | Не воспроизводит награды и скрипты | Пошагово со сверкой; награды и шауты отдельно |
| 7 | Безграничность нарратора | Предел — консоль, 135 команд, БД CHIM, Papyrus-мосты | Расширять мосты при разработке |
