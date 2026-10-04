Scriptname AIAgentQuestProgressionBridge Hidden
{Static bridge used by the CHIM SKSE plugin to apply server-approved quest actions.}

Function SetQuestStage(int questFormId, int stage) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetStage(stage)
    endif
EndFunction

Function SetQuestObjectiveCompleted(int questFormId, int objectiveIndex, bool completed = true) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetObjectiveCompleted(objectiveIndex, completed)
    endif
EndFunction

Function SetQuestObjectiveDisplayed(int questFormId, int objectiveIndex, bool displayed = true, bool forceDisplayed = false) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetObjectiveDisplayed(objectiveIndex, displayed, forceDisplayed)
    endif
EndFunction

Function SetQuestStageObjective(int questFormId, int stage, int objectiveIndex) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetStage(stage)
        Utility.Wait(0.25)
        targetQuest.SetObjectiveDisplayed(objectiveIndex, true, true)
    endif
EndFunction

Function FailAllQuestObjectives(int questFormId) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.FailAllObjectives()
    endif
EndFunction

Function StartQuest(int questFormId) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.Start()
    endif
EndFunction

Function StartQuestStageObjective(int questFormId, int stage, int objectiveIndex) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        if !targetQuest.IsRunning()
            targetQuest.Start()
            Utility.Wait(0.25)
        endif
        targetQuest.SetStage(stage)
        Utility.Wait(0.25)
        targetQuest.SetObjectiveDisplayed(objectiveIndex, true, true)
    endif
EndFunction

; TES-Speech-Adapter: the AIAgent plugin dispatches several outbox rows without waiting for
; each other, so ExecuteConsoleCommand(Sequence) calls from DIFFERENT rows run concurrently
; as separate Papyrus call stacks. ConsoleUtil's selected reference and last message are one
; shared, global, native state: confirmed in tes_god_console_log 2026-09-28 16:10-16:12,
; where one command's OWN reported output was another, later row's "[tes] <command>" marker -
; PrintMessage does reach ReadMessage, but a concurrent row's prid/marker can land between
; this row's ExecuteCommand and its own ReadMessage call. Confirmed again at 17:54:33: two
; NPCs' prid calls interleaved seven times in under 0.1s, then five commands meant for one
; of them all reported the other's stale "Invalid actor value" error - impossible if rows
; ran one at a time with their own Utility.Wait(0.25) between steps.
; A spinlock (StorageUtil.AdjustIntValue is a single native, hence atomic, call) around the
; whole body of both functions below serializes every row through this bridge. StorageUtil
; values persist in the co-save, so a save made mid-sequence would otherwise leave the lock
; held forever after loading; TESLockAcquire force-takes it after a 10 s wait instead.
bool Function TESLockAcquire(float timeoutSeconds = 10.0) Global
    Actor player = Game.GetPlayer()
    float started = Utility.GetCurrentRealTime()
    while StorageUtil.AdjustIntValue(player, "TESConsoleLock", 1) != 1
        StorageUtil.AdjustIntValue(player, "TESConsoleLock", -1)
        if Utility.GetCurrentRealTime() - started > timeoutSeconds
            StorageUtil.SetIntValue(player, "TESConsoleLock", 1)
            return true
        endif
        Utility.Wait(0.05)
    endwhile
    return true
EndFunction

Function TESLockRelease() Global
    StorageUtil.SetIntValue(Game.GetPlayer(), "TESConsoleLock", 0)
EndFunction

Function ExecuteConsoleCommand(String command) Global
    TESLockAcquire()
    if command != ""
        TESRunAndReport(command)
    endif
    TESLockRelease()
EndFunction

Function ExecuteConsoleCommandSequence(String commands) Global
    TESLockAcquire()
    ; TES-Speech-Adapter: stop the sequence when a target selection fails - otherwise the
    ; next commands hit whatever the console had selected before (a stray disable once did).
    int splitIndex = StringUtil.Find(commands, "||")
    while splitIndex >= 0
        String command = StringUtil.Substring(commands, 0, splitIndex)
        if command != ""
            if !TESRunAndReport(command)
                AIAgentFunctions.logMessage(StringUtil.Substring(commands, splitIndex + 2) + "@@error: aborted, target not found", "tes_god_console")
                TESLockRelease()
                return
            endif
            Utility.Wait(0.25)
        endif
        commands = StringUtil.Substring(commands, splitIndex + 2)
        splitIndex = StringUtil.Find(commands, "||")
    endwhile

    if commands != ""
        TESRunAndReport(commands)
    endif
    TESLockRelease()
EndFunction

; Run one console command and send its real console output to the server
; (ext/tes_god_console), so the Narrator learns whether it worked. Reads the console before
; and after: unchanged means the command printed nothing (a genuine "no output", now that
; the lock above stops a concurrent row from writing to the same state in between).
bool Function TESRunAndReport(String command) Global
    if StringUtil.Find(command, "tesnear ") == 0
        return TESSelectNearby(StringUtil.Substring(command, 8))
    endif
    if command == "tesrussify"
        TESRussifyNames()
        return true
    endif
    if StringUtil.Find(command, "tesdress ") == 0
        TESDress(StringUtil.Substring(command, 9))
        return true
    endif
    if StringUtil.Find(command, "tesroutine ") == 0
        TESRoutine(StringUtil.Substring(command, 11))
        return true
    endif
    if command == "tesclone" || StringUtil.Find(command, "tesclone ") == 0
        ; a copy of the selected actor next to the player ("сделай вторую Айрилет", live
        ; 2026-10-04 14:44: placeatme with her RefID is "Invalid object" - it needs the base)
        Actor original = ConsoleUtil.GetSelectedReference() as Actor
        if !original || original == Game.GetPlayer() || original.IsChild()
            AIAgentFunctions.logMessage(command + "@@error: no actor selected", "tes_god_console")
            return true
        endif
        int copies = 1
        if StringUtil.GetLength(command) > 9
            copies = StringUtil.Substring(command, 9) as int
        endif
        if copies < 1
            copies = 1
        elseif copies > 5
            copies = 5
        endif
        int made = 0
        while made < copies
            Game.GetPlayer().PlaceActorAtMe(original.GetActorBase())
            made += 1
        endwhile
        AIAgentFunctions.logMessage(command + "@@copies of " + original.GetDisplayName() + " made: " + made, "tes_god_console")
        return true
    endif
    if StringUtil.Find(command, "tesimpunity") == 0
        ; "tesimpunity 1|0": the player's crimes are not reported (no bounty, guards do not turn on
        ; him) / are reported again. The flag is not kept by a save - the server puts it again.
        bool impunityOn = StringUtil.Substring(command, 12) != "0"
        Game.SetPlayerReportCrime(!impunityOn)
        AIAgentFunctions.logMessage(command + "@@crime reporting " + (!impunityOn), "tes_god_console")
        return true
    endif
    if command == "tesredress"
        ; the selected actor wears his own default outfit again (undo of "раздеть")
        Actor dressed = ConsoleUtil.GetSelectedReference() as Actor
        if !dressed || dressed == Game.GetPlayer()
            AIAgentFunctions.logMessage("tesredress@@error: no actor selected", "tes_god_console")
            return true
        endif
        Outfit own = dressed.GetActorBase().GetOutfit()
        if own
            dressed.SetOutfit(own, false)
        endif
        dressed.EvaluatePackage()
        AIAgentFunctions.logMessage("tesredress@@" + dressed.GetDisplayName() + " is dressed again", "tes_god_console")
        return true
    endif
    if command == "tesungive"
        ; the things the selected actor gave the player with "tesgive all" go back to him
        Actor taker = ConsoleUtil.GetSelectedReference() as Actor
        if !taker
            AIAgentFunctions.logMessage("tesungive@@error: no actor selected", "tes_god_console")
            return true
        endif
        int back = StorageUtil.FormListCount(taker, "TESGaveForms")
        int k = 0
        int moved = 0
        while k < back
            Form what = StorageUtil.FormListGet(taker, "TESGaveForms", k)
            int howMany = StorageUtil.IntListGet(taker, "TESGaveCounts", k)
            if what && howMany > 0
                int have = Game.GetPlayer().GetItemCount(what)
                if have < howMany
                    howMany = have
                endif
                if howMany > 0
                    Game.GetPlayer().RemoveItem(what, howMany, true, taker)
                    moved += 1
                endif
            endif
            k += 1
        endwhile
        StorageUtil.FormListClear(taker, "TESGaveForms")
        StorageUtil.IntListClear(taker, "TESGaveCounts")
        AIAgentFunctions.logMessage("tesungive@@" + taker.GetDisplayName() + ": " + moved + " kinds of items returned", "tes_god_console")
        return true
    endif
    if command == "tespeace"
        ; Whiterun's crime faction (its guards and citizens) becomes an ALLY of the player's faction
        ; both ways, and everyone in the cell stops fighting. The "bounty 0 + crime reporting off"
        ; of tesimpunity did not keep the guards of Dragonsreach off the player (live 2026-10-04
        ; 16:14-16:37: assault on a citizen makes his friends and the guards join in).
        Faction whiterunCrime = Game.GetForm(0x000267EA) as Faction
        Faction playerFac = Game.GetForm(0x00000013) as Faction
        if whiterunCrime && playerFac
            whiterunCrime.SetReaction(playerFac, 2)
            playerFac.SetReaction(whiterunCrime, 2)
        endif
        Actor peaceMe = Game.GetPlayer()
        peaceMe.StopCombat()
        Cell peaceCell = peaceMe.GetParentCell()
        int peaceN = 0
        if peaceCell
            int peaceRefs = peaceCell.GetNumRefs(43)
            int peaceI = 0
            while peaceI < peaceRefs
                Actor peaceActor = peaceCell.GetNthRef(peaceI, 43) as Actor
                if peaceActor && peaceActor != peaceMe && peaceActor.IsInCombat()
                    peaceActor.StopCombat()
                    peaceN += 1
                endif
                peaceI += 1
            endwhile
        endif
        AIAgentFunctions.logMessage("tespeace@@" + peaceN + " calmed", "tes_god_console")
        return true
    endif
    if command == "tesplace here"
        ; a persistent marker where the player stands (the court's place, "суд будет в зале ярла");
        ; the server takes the reference's FormID from the report and moves people to it
        ObjectReference placeMarker = Game.GetPlayer().PlaceAtMe(Game.GetForm(0x3B), 1, true, false)
        if placeMarker
            AIAgentFunctions.logMessage("tesplace here@@" + placeMarker.GetFormID(), "tes_god_console")
        else
            AIAgentFunctions.logMessage("tesplace here@@error: no marker", "tes_god_console")
        endif
        return true
    endif
    if command == "tesversion"
        ; the server asks which bridge the game runs: 2 = "tesroutine at", the strong teskill;
        ; 3 = also "teslove solo"; 4 = also "tesimpunity"; 5 = also "tesredress", "tesungive";
        ; 6 = also "tesplace here"; 7 = also "tespeace"
        AIAgentFunctions.logMessage("tesversion@@7", "tes_god_console")
        return true
    endif
    if command == "teskill"
        ; "kill" on an essential NPC only drops him to his knees. Essential/protected are flags
        ; of the actor BASE; clear them, then kill (no killer: nobody gets a bounty for it).
        Actor victim = ConsoleUtil.GetSelectedReference() as Actor
        if !victim || victim == Game.GetPlayer()
            AIAgentFunctions.logMessage("teskill@@error: no actor selected", "tes_god_console")
            return true
        endif
        TESMortal(victim, false)
        victim.Kill()
        Utility.Wait(1.0)
        if !victim.IsDead()
            ; live 2026-10-04 12:55: the Ebony Warrior stayed alive after Kill() - the flag sat on
            ; the leveled base. Both bases are cleared above; still alive -> health to zero.
            victim.DamageActorValue("Health", victim.GetActorValue("Health") + 100000.0)
            Utility.Wait(1.0)
        endif
        if !victim.IsDead() && !victim.IsChild()
            ; live 2026-10-04 16:04: the Ebony Warrior sat at health -30 and "dead: False" through every
            ; step above (a script of the mod keeps him up). Everything that can hold an actor
            ; up is taken off, a silent kill; and if he still stands, he is taken out of the world.
            victim.SetGhost(false)
            victim.SetUnconscious(false)
            victim.SetNoBleedoutRecovery(false)
            victim.StopCombat()
            victim.KillSilent()
            Utility.Wait(1.0)
            if !victim.IsDead()
                victim.Disable()
                victim.Delete()
                AIAgentFunctions.logMessage("teskill@@" + victim.GetDisplayName() + " would not die - removed from the world", "tes_god_console")
                return true
            endif
        endif
        if !victim.IsDead() && victim.IsChild()
            AIAgentFunctions.logMessage("teskill@@" + victim.GetDisplayName() + " is a child: the game does not let children die", "tes_god_console")
            return true
        endif
        AIAgentFunctions.logMessage("teskill@@" + victim.GetDisplayName() + " is dead: " + victim.IsDead(), "tes_god_console")
        return true
    endif
    if StringUtil.Find(command, "tesessential ") == 0
        ; "setessential" for a person: the flag is on the base, often a leveled one
        Actor mortal = ConsoleUtil.GetSelectedReference() as Actor
        if !mortal || mortal == Game.GetPlayer()
            AIAgentFunctions.logMessage(command + "@@error: no actor selected", "tes_god_console")
            return true
        endif
        TESMortal(mortal, StringUtil.Substring(command, 13) as int > 0)
        AIAgentFunctions.logMessage(command + "@@" + mortal.GetDisplayName() + " essential: " + mortal.IsEssential(), "tes_god_console")
        return true
    endif
    if StringUtil.Find(command, "tesfollow ") == 0
        TESFollowRef(StringUtil.Substring(command, 10))
        return true
    endif
    if StringUtil.Find(command, "tesescort ") == 0
        TESEscort(StringUtil.Substring(command, 10))
        return true
    endif
    if StringUtil.Find(command, "tesduel ") == 0
        ; an execution is a fight: the selected actor attacks the condemned, whose
        ; "essential"/"protected" flags are taken off first so that the fight can end
        Actor executioner = ConsoleUtil.GetSelectedReference() as Actor
        Actor condemned = Game.GetForm(StringUtil.Substring(command, 8) as int) as Actor
        if !executioner || !condemned || condemned == Game.GetPlayer()
            AIAgentFunctions.logMessage(command + "@@error: who or whom is missing", "tes_god_console")
            return true
        endif
        ActorBase condemnedBase = condemned.GetActorBase()
        if condemnedBase
            condemnedBase.SetEssential(false)
            condemnedBase.SetProtected(false)
        endif
        executioner.StartCombat(condemned)
        AIAgentFunctions.logMessage(command + "@@" + executioner.GetDisplayName() + " attacks " + condemned.GetDisplayName(), "tes_god_console")
        return true
    endif
    if StringUtil.Find(command, "tesjailbox ") == 0
        TESJailBox(StringUtil.Substring(command, 11))
        return true
    endif
    if StringUtil.Find(command, "teshold ") == 0
        TESHold(StringUtil.Substring(command, 8))
        return true
    endif
    if StringUtil.Find(command, "tesoutfit ") == 0
        TESOutfit(StringUtil.Substring(command, 10))
        return true
    endif
    if command == "tesremove"
        TESRemoveSelected()
        return true
    endif
    if StringUtil.Find(command, "tesgive ") == 0
        TESGive(StringUtil.Substring(command, 8))
        return true
    endif
    if command == "tesautosave"
        Game.RequestAutoSave()
        AIAgentFunctions.logMessage("tesautosave@@requested", "tes_god_console")
        return true
    endif
    if StringUtil.Find(command, "teslove ") == 0
        ; "teslove <partner FormID, decimal> [tags]": an OStim scene for the selected actor and
        ; the partner (20 = the player); tags = what kind ("vaginalsex", "blowjob,deepthroat"...),
        ; a running scene of the two is switched to it. "teslove stop" ends the actor's scene.
        ; Children are never part of it.
        Actor lover = ConsoleUtil.GetSelectedReference() as Actor
        String loveArgs = StringUtil.Substring(command, 8)
        if loveArgs == "stop"
            if lover && TESLove.Stop(lover)
                AIAgentFunctions.logMessage(command + "@@scene ended", "tes_god_console")
            else
                AIAgentFunctions.logMessage(command + "@@no scene to end", "tes_god_console")
            endif
            return true
        endif
        if StringUtil.Find(loveArgs, "solo") == 0
            ; "teslove solo [tags]": one actor alone (masturbation)
            if !lover || lover.IsChild() || lover.IsDead()
                AIAgentFunctions.logMessage(command + "@@error: refused or nobody selected", "tes_god_console")
                return true
            endif
            String soloTags = StringUtil.Substring(loveArgs, 4)
            if StringUtil.GetLength(soloTags) > 0 && StringUtil.GetNthChar(soloTags, 0) == " "
                soloTags = StringUtil.Substring(soloTags, 1)
            endif
            int soloThread = TESLove.StartSolo(lover, soloTags)
            if soloThread >= 0
                AIAgentFunctions.logMessage(command + "@@solo scene started: " + lover.GetDisplayName() + " [" + TESLove.SceneOf(soloThread) + "]", "tes_god_console")
            else
                AIAgentFunctions.logMessage(command + "@@error: OStim did not start a solo scene", "tes_god_console")
            endif
            return true
        endif
        String loveTags = ""
        int loveSplit = StringUtil.Find(loveArgs, " ")
        if loveSplit > 0
            loveTags = StringUtil.Substring(loveArgs, loveSplit + 1)
            loveArgs = StringUtil.Substring(loveArgs, 0, loveSplit)
        endif
        Actor partner = Game.GetForm(loveArgs as int) as Actor
        if !lover || !partner || lover == partner
            AIAgentFunctions.logMessage(command + "@@error: who or with whom is missing", "tes_god_console")
            return true
        endif
        if lover.IsChild() || partner.IsChild() || lover.IsDead() || partner.IsDead()
            AIAgentFunctions.logMessage(command + "@@error: refused (a child or a dead body)", "tes_god_console")
            return true
        endif
        int thread = TESLove.Start(partner, lover, loveTags)
        if thread >= 0
            AIAgentFunctions.logMessage(command + "@@scene started: " + lover.GetDisplayName() + " and " + partner.GetDisplayName() + " [" + TESLove.SceneOf(thread) + "]", "tes_god_console")
        else
            AIAgentFunctions.logMessage(command + "@@error: OStim did not start a scene", "tes_god_console")
        endif
        return true
    endif
    if StringUtil.Find(command, "tesperkpoints ") == 0
        ; the console has no command for perk points ("player.addperkpoints" does not exist)
        int points = StringUtil.Substring(command, 14) as int
        if points > 0 && points <= 250
            Game.AddPerkPoints(points)
        endif
        AIAgentFunctions.logMessage(command + "@@perk points now " + Game.GetPerkPoints(), "tes_god_console")
        return true
    endif
    if command == "tesheal"
        TESHeal()
        return true
    endif
    if command == "tesstate"
        TESState()
        return true
    endif
    if command == "tesinspect"
        TESInspect()
        return true
    endif
    if command == "tesclaim"
        TESClaim()
        return true
    endif
    if command == "tesunfollow"
        ; CHIM's FollowPlayer sets StorageUtil "CHIM_FollowPlayerActive" and a priority-100
        ; package override; AIAgentAIMind restores it after every other action, so an NPC that
        ; once followed the player keeps trailing them forever (live 2026-10-03: Proventus said
        ; "I am going to Dragonsreach" for an hour while walking behind the player). Only the
        ; follow flag and package are removed - a travel package just given stays.
        Actor follower = ConsoleUtil.GetSelectedReference() as Actor
        if !follower
            AIAgentFunctions.logMessage("tesunfollow@@error: no actor selected", "tes_god_console")
            return true
        endif
        Package followPlayer = Game.GetFormFromFile(0x2226d, "AIAgent.esp") as Package
        Package followSoft = Game.GetFormFromFile(0x0268b0, "AIAgent.esp") as Package
        StorageUtil.SetIntValue(follower, "CHIM_FollowPlayerActive", 0)
        if followPlayer
            ActorUtil.RemovePackageOverride(follower, followPlayer)
        endif
        if followSoft
            ActorUtil.RemovePackageOverride(follower, followSoft)
        endif
        follower.EvaluatePackage()
        AIAgentFunctions.logMessage("tesunfollow@@" + follower.GetDisplayName() + " no longer follows the player", "tes_god_console")
        return true
    endif
    if command == "tesbookvalue"
        ; CHIM's SpawnItem (AIAgentAIMind.psc) does itemToSpawnBase.SetGoldValue(10000) on the
        ; shared base of every note / diary / document, so each diary sold for 10000 gold.
        ; Its script cannot be rebuilt here (the author's PO3/AIAgent sources differ), so the
        ; server sends this after each spawned book. Silent: no report line.
        Form noteBase = Game.GetFormFromFile(0x022d30, "AIAgent.esp")
        Form diaryBase = Game.GetFormFromFile(0x045CEF, "AIAgent.esp")
        if noteBase
            noteBase.SetGoldValue(5)
        endif
        if diaryBase
            diaryBase.SetGoldValue(5)
        endif
        return true
    endif
    if StringUtil.Find(command, "tesbuyhouse ") == 0
        TESBuyHouse(StringUtil.Substring(command, 12))
        return true
    endif
    if StringUtil.Find(command, "tesfurnish ") == 0
        TESFurnish(StringUtil.Substring(command, 11))
        return true
    endif
    if StringUtil.Find(command, "tesownhouse ") == 0
        TESOwnHouse(StringUtil.Substring(command, 12))
        return true
    endif
    ; A marker line first: comparing with the PREVIOUS command's output lost every answer that
    ; happened to repeat it (live 2026-10-04: "getitemcount" twice in a row -> the second
    ; "GetItemCount >> 0.00" was reported as no output, and a fine waited for its answer forever).
    String before = "[tes] " + command
    ConsoleUtil.PrintMessage(before)
    ConsoleUtil.ExecuteCommand(command)
    String output = ConsoleUtil.ReadMessage()
    if output == before
        output = ""
    endif
    AIAgentFunctions.logMessage(command + "@@" + output, "tes_god_console")
    if StringUtil.Find(command, "prid ") == 0 && StringUtil.Find(output, "not found") >= 0
        ConsoleUtil.SetSelectedReference(None)
        return false
    endif
    return true
EndFunction

Function StopQuest(int questFormId) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.Stop()
    endif
EndFunction

Function StartScene(int sceneFormId) Global
    Scene targetScene = Game.GetForm(sceneFormId) as Scene
    if targetScene
        targetScene.Start()
    endif
EndFunction

Function SetActorValue(int actorFormId, string actorValue, float value) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    if targetActor
        targetActor.SetActorValue(actorValue, value)
    endif
EndFunction

Function SetActorGhost(int actorFormId, bool ghost) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    if targetActor
        targetActor.SetGhost(ghost)
    endif
EndFunction

Function EvaluateActorPackage(int actorFormId) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    if targetActor
        targetActor.EvaluatePackage()
    endif
EndFunction

Function RemoveItemFromPlayer(int itemFormId, int count = 1, bool silent = false) Global
    Form targetItem = Game.GetForm(itemFormId)
    Actor player = Game.GetPlayer()
    if targetItem && player
        player.RemoveItem(targetItem, count, silent)
    endif
EndFunction

Function AddItemToPlayer(int itemFormId, int count = 1, bool silent = false) Global
    Form targetItem = Game.GetForm(itemFormId)
    Actor player = Game.GetPlayer()
    if targetItem && player
        player.AddItem(targetItem, count, silent)
    endif
EndFunction

Function EnableReference(int refFormId, bool fadeIn = false) Global
    ObjectReference targetRef = Game.GetForm(refFormId) as ObjectReference
    if targetRef
        targetRef.Enable(fadeIn)
    endif
EndFunction

Function SetActorRelationshipToPlayer(int actorFormId, int rank) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    Actor player = Game.GetPlayer()
    if targetActor && player
        targetActor.SetRelationshipRank(player, rank)
    endif
EndFunction

; TES-Speech-Adapter: "tesnear <Display Name>" selects the NEAREST actor with that name
; (dead ones too, so resurrect works; nearest, so a generic name like "Horse" means the one next to the
; player) as the console reference for the following commands of the same sequence.
; Reports "selected" or which actors it saw instead.
bool Function TESSelectNearby(String actorName) Global
    Actor player = Game.GetPlayer()
    Actor[] actors = MiscUtil.ScanCellNPCs(player, 4096.0, None, false)
    Actor best = None
    float bestDistance = 0.0
    String seen = ""
    int i = 0
    while i < actors.Length
        Actor candidate = actors[i]
        if candidate && candidate != player
            if candidate.GetDisplayName() == actorName
                float distance = candidate.GetDistance(player)
                if !best || distance < bestDistance
                    best = candidate
                    bestDistance = distance
                endif
            elseif i < 8
                seen = seen + candidate.GetDisplayName() + "; "
            endif
        endif
        i += 1
    endwhile
    if best
        ConsoleUtil.SetSelectedReference(best)
        AIAgentFunctions.logMessage("tesnear " + actorName + "@@selected", "tes_god_console")
        return true
    else
        ConsoleUtil.SetSelectedReference(None)
        AIAgentFunctions.logMessage("tesnear " + actorName + "@@not found nearby, seen: " + seen, "tes_god_console")
    endif
    return false
EndFunction

; TES-Speech-Adapter: "tesgive all|around|house" - the selected console reference
; (set by a preceding "tesnear <Giver>") gives the player what it owns:
;   all    - everything the giver carries (worn too), owned by the player afterwards;
;   around - objects within 1500 units of the player owned by the giver or its factions
;            (chests, furniture, items); locked ones are unlocked;
;   house  - the player's current interior cell, if the giver or its faction owns it:
;            the cell and everything in it that the giver owned; locked doors and
;            containers inside are unlocked.
; Reports how many references changed owner.
Function TESGive(String mode) Global
    Actor player = Game.GetPlayer()
    ActorBase playerBase = player.GetActorBase()
    Actor giver = ConsoleUtil.GetSelectedReference() as Actor
    if !giver
        AIAgentFunctions.logMessage("tesgive " + mode + "@@error: the giver was not found nearby", "tes_god_console")
        return
    endif
    if mode == "all"
        ; what he carries is written down first - "tesungive" gives it back (live 2026-10-04: the
        ; smith's things went to the player by a mistaken order and there was no way back)
        StorageUtil.FormListClear(giver, "TESGaveForms")
        StorageUtil.IntListClear(giver, "TESGaveCounts")
        int kinds = giver.GetNumItems()
        int n = 0
        while n < kinds
            Form carried = giver.GetNthForm(n)
            if carried
                StorageUtil.FormListAdd(giver, "TESGaveForms", carried, false)
                StorageUtil.IntListAdd(giver, "TESGaveCounts", giver.GetItemCount(carried), false)
            endif
            n += 1
        endwhile
        giver.RemoveAllItems(player, false, false)
        AIAgentFunctions.logMessage("tesgive all@@" + giver.GetDisplayName() + " gave everything carried to the player", "tes_god_console")
        return
    endif
    Cell here = player.GetParentCell()
    bool house = mode == "house"
    int changed = 0
    if house
        if !here.IsInterior()
            AIAgentFunctions.logMessage("tesgive house@@error: the player is not inside a house", "tes_god_console")
            return
        endif
        if !TESOwnedBy(here.GetActorOwner(), here.GetFactionOwner(), giver)
            AIAgentFunctions.logMessage("tesgive house@@error: this place does not belong to " + giver.GetDisplayName(), "tes_god_console")
            return
        endif
        here.SetActorOwner(playerBase)
        changed = 1
    endif
    int count = here.GetNumRefs(0)
    if count > 5000
        count = 5000
    endif
    int i = 0
    while i < count
        ObjectReference ref = here.GetNthRef(i, 0)
        if ref && !(ref as Actor)
            if house || ref.GetDistance(player) <= 1500.0
                bool owned = TESOwnedBy(ref.GetActorOwner(), ref.GetFactionOwner(), giver)
                if owned
                    ref.SetActorOwner(playerBase)
                    changed += 1
                endif
                if (owned || house) && ref.IsLocked()
                    ref.Lock(false)
                endif
            endif
        endif
        i += 1
    endwhile
    AIAgentFunctions.logMessage("tesgive " + mode + "@@" + giver.GetDisplayName() + " gave " + changed + " references to the player", "tes_god_console")
EndFunction

; TES-Speech-Adapter (goal agent): "tesstate" - one report with the selected actor's build
; (the player when nothing is selected): level, health/magicka/stamina, base skills, gold,
; perk points, worn armour by slot and equipped weapons as "Name#FormID(decimal)".
; One call instead of ~25 console round trips (getav per skill).
Function TESState() Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    if !target
        target = Game.GetPlayer()
    endif
    String[] skills = new String[18]
    skills[0] = "OneHanded"
    skills[1] = "TwoHanded"
    skills[2] = "Marksman"
    skills[3] = "Block"
    skills[4] = "Smithing"
    skills[5] = "HeavyArmor"
    skills[6] = "LightArmor"
    skills[7] = "Pickpocket"
    skills[8] = "Lockpicking"
    skills[9] = "Sneak"
    skills[10] = "Alchemy"
    skills[11] = "Speechcraft"
    skills[12] = "Alteration"
    skills[13] = "Conjuration"
    skills[14] = "Destruction"
    skills[15] = "Illusion"
    skills[16] = "Restoration"
    skills[17] = "Enchanting"
    String out = target.GetDisplayName() + "; level " + target.GetLevel()
    out += "; hp " + (target.GetActorValue("Health") as int) + "/" + (target.GetBaseActorValue("Health") as int)
    out += "; mp " + (target.GetBaseActorValue("Magicka") as int) + "; sp " + (target.GetBaseActorValue("Stamina") as int)
    out += "; gold " + target.GetGoldAmount()
    if target == Game.GetPlayer()
        out += "; perkpoints " + Game.GetPerkPoints()
    endif
    out += "; skills"
    int i = 0
    while i < 18
        out += " " + skills[i] + "=" + (target.GetBaseActorValue(skills[i]) as int)
        i += 1
    endwhile
    int[] masks = new int[8]
    masks[0] = 0x00000001
    masks[1] = 0x00000004
    masks[2] = 0x00000008
    masks[3] = 0x00000080
    masks[4] = 0x00000020
    masks[5] = 0x00000040
    masks[6] = 0x00001000
    masks[7] = 0x00000200
    String[] slotNames = new String[8]
    slotNames[0] = "head"
    slotNames[1] = "body"
    slotNames[2] = "hands"
    slotNames[3] = "feet"
    slotNames[4] = "amulet"
    slotNames[5] = "ring"
    slotNames[6] = "circlet"
    slotNames[7] = "shield"
    out += "; worn"
    i = 0
    while i < 8
        Form worn = target.GetWornForm(masks[i])
        if worn
            out += " " + slotNames[i] + "=" + worn.GetName() + "#" + worn.GetFormID()
        endif
        i += 1
    endwhile
    Weapon right = target.GetEquippedWeapon(false)
    Weapon left = target.GetEquippedWeapon(true)
    if right
        out += "; right=" + right.GetName() + "#" + right.GetFormID()
    endif
    if left
        out += "; left=" + left.GetName() + "#" + left.GetFormID()
    endif
    AIAgentFunctions.logMessage("tesstate@@" + out, "tes_god_console")
EndFunction

; TES-Speech-Adapter (goal agent): "tesinspect" - what is in the player's current cell:
; its name and owner, then up to 40 doors/containers/actors with owner and lock state.
Function TESInspect() Global
    Actor player = Game.GetPlayer()
    Cell here = player.GetParentCell()
    String out = here.GetName() + "#" + here.GetFormID()
    if here.IsInterior()
        out += "; interior"
    else
        out += "; exterior"
    endif
    out += "; owner " + TESOwnerText(here.GetActorOwner(), here.GetFactionOwner())
    int count = here.GetNumRefs(0)
    if count > 3000
        count = 3000
    endif
    out += "; refs " + count
    int listed = 0
    int i = 0
    while i < count && listed < 40
        ObjectReference ref = here.GetNthRef(i, 0)
        if ref && !ref.IsDisabled()
            Form base = ref.GetBaseObject()
            int t = base.GetType()
            ; 28 container, 29 door, 43 NPC_ (placed actors)
            if t == 28 || t == 29 || t == 43
                String nm = ref.GetDisplayName()
                if nm != ""
                    out += " | " + nm + "#" + ref.GetFormID()
                    if t == 28
                        out += " container"
                    elseif t == 29
                        out += " door"
                    else
                        out += " actor"
                    endif
                    String owner = TESOwnerText(ref.GetActorOwner(), ref.GetFactionOwner())
                    if owner != "none"
                        out += " owner " + owner
                    endif
                    if ref.IsLocked()
                        out += " locked"
                    endif
                    listed += 1
                endif
            endif
        endif
        i += 1
    endwhile
    AIAgentFunctions.logMessage("tesinspect@@" + out, "tes_god_console")
EndFunction

String Function TESOwnerText(ActorBase ownerActor, Faction ownerFaction) Global
    if ownerActor
        return ownerActor.GetName()
    endif
    if ownerFaction
        return "faction " + ownerFaction.GetName() + "#" + ownerFaction.GetFormID()
    endif
    return "none"
EndFunction

; TES-Speech-Adapter (goal agent, god only): "tesclaim" - the player's current INTERIOR
; cell and every non-actor reference in it become the player's; locked ones are unlocked.
; Unlike "tesgive house" it needs no previous owner (the god takes, nobody gives).
Function TESClaim() Global
    Actor player = Game.GetPlayer()
    ActorBase playerBase = player.GetActorBase()
    Cell here = player.GetParentCell()
    if !here.IsInterior()
        AIAgentFunctions.logMessage("tesclaim@@error: the player is not inside a building", "tes_god_console")
        return
    endif
    here.SetActorOwner(playerBase)
    int count = here.GetNumRefs(0)
    if count > 5000
        count = 5000
    endif
    int changed = 0
    int i = 0
    while i < count
        ObjectReference ref = here.GetNthRef(i, 0)
        if ref && !(ref as Actor)
            ref.SetActorOwner(playerBase)
            if ref.IsLocked()
                ref.Lock(false)
            endif
            changed += 1
        endif
        i += 1
    endwhile
    AIAgentFunctions.logMessage("tesclaim@@" + here.GetName() + " and " + changed + " references now belong to the player", "tes_god_console")
EndFunction

; TES-Speech-Adapter (stewards sell houses): "tesbuyhouse <stage> <price global FormID, decimal>"
; - the vanilla purchase. HousePurchase (Skyrim.esm 0xA7B33) stage 10/20/30/40/50 runs
; HousePurchaseScript.PurchaseHouse (gold from the HP* global, key, decorating guide, cell
; owner, "Houses Owned") - but RemoveItem does not check the gold, so it is checked HERE:
; a house is never sold for less than its price. Reports sold / not enough gold / already owned.
Function TESBuyHouse(String args) Global
    ; "<stage> <price global> [prepaid]": prepaid = the seller already took the money from the
    ; player (CHIM TakeGoldFromPlayer) - the price is handed back first, so the vanilla stage
    ; takes it again and the player pays once.
    bool prepaid = StringUtil.Find(args, " prepaid") > 0
    String coreArgs = args
    if prepaid
        coreArgs = StringUtil.Substring(args, 0, StringUtil.Find(args, " prepaid"))
    endif
    int split = StringUtil.Find(coreArgs, " ")
    int stage = StringUtil.Substring(coreArgs, 0, split) as int
    GlobalVariable priceVar = Game.GetForm(StringUtil.Substring(coreArgs, split + 1) as int) as GlobalVariable
    Quest purchase = Game.GetForm(0x000A7B33) as Quest
    if !purchase || !priceVar || stage <= 0
        AIAgentFunctions.logMessage("tesbuyhouse " + args + "@@error: bad arguments", "tes_god_console")
        return
    endif
    if purchase.GetStageDone(stage)
        AIAgentFunctions.logMessage("tesbuyhouse " + args + "@@error: the player already owns this house", "tes_god_console")
        return
    endif
    Actor player = Game.GetPlayer()
    int price = priceVar.GetValueInt()
    if prepaid
        player.AddItem(Game.GetForm(0x0000000F), price, true)
    endif
    int gold = player.GetItemCount(Game.GetForm(0x0000000F))
    if gold < price
        AIAgentFunctions.logMessage("tesbuyhouse " + args + "@@error: not enough gold: has " + gold + ", price " + price, "tes_god_console")
        return
    endif
    purchase.SetStage(stage)
    Utility.Wait(1.0)
    if purchase.GetStageDone(stage)
        AIAgentFunctions.logMessage("tesbuyhouse " + args + "@@sold for " + price + ", gold left " + player.GetItemCount(Game.GetForm(0x0000000F)), "tes_god_console")
    else
        AIAgentFunctions.logMessage("tesbuyhouse " + args + "@@error: the purchase stage did not run", "tes_god_console")
    endif
EndFunction

; TES-Speech-Adapter (house furnishings, all at once): "tesfurnish <pay|free> <item>/<item>/..."
; item = "<price global>,<marker to enable>,<marker to disable or 0>", FormIDs decimal.
; The vanilla steward dialogue (TIF__000C6E12 etc.) does exactly this per room:
; RemoveItem(gold, HDxxx.value), DecorateMarker.Enable(), OldMarker.Disable(). A marker of -1
; is Whiterun's alchemy lab, enabled by the HousePurchase quest script function
; (BYOHRelationshipAdoptionHousePurchase.Whiterun_EnableChildBedroomAlternative, TIF__000F3921).
; Rooms already bought are skipped; in "pay" mode a room the player cannot afford is skipped.
Function TESFurnish(String args) Global
    int split = StringUtil.Find(args, " ")
    bool pay = StringUtil.Substring(args, 0, split) == "pay"
    String rest = StringUtil.Substring(args, split + 1)
    Actor player = Game.GetPlayer()
    Form gold = Game.GetForm(0x0000000F)
    int bought = 0
    int already = 0
    int poor = 0
    int spent = 0
    int guard = 0
    while rest != "" && guard < 12
        guard += 1
        String item = rest
        int cut = StringUtil.Find(rest, "/")
        if cut >= 0
            item = StringUtil.Substring(rest, 0, cut)
            rest = StringUtil.Substring(rest, cut + 1)
        else
            rest = ""
        endif
        int c1 = StringUtil.Find(item, ",")
        int c2 = StringUtil.Find(item, ",", c1 + 1)
        if c1 > 0 && c2 > c1
            GlobalVariable priceVar = Game.GetForm(StringUtil.Substring(item, 0, c1) as int) as GlobalVariable
            int enableId = StringUtil.Substring(item, c1 + 1, c2 - c1 - 1) as int
            int disableId = StringUtil.Substring(item, c2 + 1) as int
            ObjectReference onRef = None
            BYOHRelationshipAdoptionHousePurchase adoption = None
            bool have = false
            if enableId == -1
                adoption = Game.GetForm(0x000A7B33) as BYOHRelationshipAdoptionHousePurchase
                if adoption
                    have = !adoption.WhiterunPlayerHouseAlchemyLaboratory.IsDisabled()
                endif
            else
                onRef = Game.GetForm(enableId) as ObjectReference
                if onRef
                    have = !onRef.IsDisabled()
                endif
            endif
            if !priceVar || (!onRef && !adoption)
                ; unknown form: skip silently, the total shows fewer rooms
            elseif have
                already += 1
            else
                int price = priceVar.GetValueInt()
                if pay && player.GetItemCount(gold) < price
                    poor += 1
                else
                    if pay
                        player.RemoveItem(gold, price, true)
                        spent += price
                    endif
                    if adoption
                        adoption.Whiterun_EnableChildBedroomAlternative()
                    else
                        onRef.Enable()
                        ObjectReference offRef = Game.GetForm(disableId) as ObjectReference
                        if disableId > 0 && offRef
                            offRef.Disable()
                        endif
                    endif
                    bought += 1
                endif
            endif
        endif
    endwhile
    AIAgentFunctions.logMessage("tesfurnish@@furnished " + bought + " rooms for " + spent + " gold, already had " + already + ", could not afford " + poor + ", gold left " + player.GetItemCount(gold), "tes_god_console")
EndFunction

; TES-Speech-Adapter (god gives any house): "tesownhouse <cell FormID> <key FormID or 0>",
; decimal. The interior cell becomes the player's, the key is added; when the player is
; inside that cell right now, everything in it changes owner too and locks open
; (references of an unloaded cell cannot be enumerated - then it says so).
Function TESOwnHouse(String args) Global
    int split = StringUtil.Find(args, " ")
    Cell house = Game.GetForm(StringUtil.Substring(args, 0, split) as int) as Cell
    Form houseKey = Game.GetForm(StringUtil.Substring(args, split + 1) as int)
    Actor player = Game.GetPlayer()
    if !house || !house.IsInterior()
        AIAgentFunctions.logMessage("tesownhouse " + args + "@@error: not an interior cell", "tes_god_console")
        return
    endif
    house.SetActorOwner(player.GetActorBase())
    String out = house.GetName() + " now belongs to the player"
    if houseKey
        player.AddItem(houseKey, 1)
        out += "; key " + houseKey.GetName() + " given"
    endif
    if player.GetParentCell() == house
        int count = house.GetNumRefs(0)
        if count > 5000
            count = 5000
        endif
        int changed = 0
        int i = 0
        while i < count
            ObjectReference ref = house.GetNthRef(i, 0)
            if ref && !(ref as Actor)
                ref.SetActorOwner(player.GetActorBase())
                if ref.IsLocked()
                    ref.Lock(false)
                endif
                changed += 1
            endif
            i += 1
        endwhile
        out += "; " + changed + " things inside are the player's"
    else
        out += "; contents keep their old owner until the player is inside (then tesclaim)"
    endif
    AIAgentFunctions.logMessage("tesownhouse " + args + "@@" + out, "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesheal" - fully restore the selected actor: health/magicka/stamina
; to (well past) their base max via RestoreActorValue (the engine clamps to max, so a large
; amount just means "full" without needing GetBaseActorValue math); end unconsciousness
; (bleedout) if they are down; cure disease with the vanilla VampireCureDisease spell
; (Skyrim.esm 0xED0AA - its real job is "cure all diseases before changing", used by the
; vampire/werewolf transformation scripts, but it is a genuine, safe cure-all-diseases spell
; for anyone; verified in tes_game_index, not guessed).
Function TESHeal() Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    if !target
        AIAgentFunctions.logMessage("tesheal@@error: no actor selected", "tes_god_console")
        return
    endif
    target.RestoreActorValue("Health", 1000000.0)
    target.RestoreActorValue("Magicka", 1000000.0)
    target.RestoreActorValue("Stamina", 1000000.0)
    if target.IsUnconscious()
        target.SetUnconscious(false)
    endif
    Spell cureDisease = Game.GetForm(0xED0AA) as Spell
    if cureDisease
        cureDisease.Cast(target, target)
    endif
    AIAgentFunctions.logMessage("tesheal@@" + target.GetDisplayName() + " fully healed", "tes_god_console")
EndFunction

bool Function TESOwnedBy(ActorBase ownerBase, Faction ownerFaction, Actor giver) Global
    if ownerBase && (ownerBase == giver.GetActorBase() || ownerBase == giver.GetLeveledActorBase())
        return true
    endif
    return ownerFaction && giver.IsInFaction(ownerFaction)
EndFunction

; TES-Speech-Adapter: "tesremove" - disable and delete the selected console reference, but
; only if it was created during play (FormID FFxxxxxx, negative in Papyrus): god summons,
; clones. Anything from the game data is refused.
Function TESRemoveSelected() Global
    ObjectReference target = ConsoleUtil.GetSelectedReference()
    if !target
        AIAgentFunctions.logMessage("tesremove@@error: nothing selected (not found nearby)", "tes_god_console")
        return
    endif
    String targetName = target.GetDisplayName()
    if target.GetFormID() >= 0
        AIAgentFunctions.logMessage("tesremove@@refused: " + targetName + " is part of the game world, not a summon", "tes_god_console")
        return
    endif
    target.Disable()
    target.Delete()
    AIAgentFunctions.logMessage("tesremove@@removed " + targetName, "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesrussify" - NPCs around the player that Real Names Extended named
; before its Russian lists were installed keep Latin names in the save. Re-roll them with
; the mod's own "[RN] Rechange" spell (RealNamesExtended.esp 0x82C), which now picks from
; the Russian lists. Latin names not given by Real Names (mod NPCs) are only reported.
Function TESRussifyNames() Global
    Actor player = Game.GetPlayer()
    Spell rechange = Game.GetFormFromFile(0x82C, "RealNamesExtended.esp") as Spell
    if !rechange
        AIAgentFunctions.logMessage("tesrussify@@error: Real Names Extended rechange spell not found", "tes_god_console")
        return
    endif
    Actor[] actors = MiscUtil.ScanCellNPCs(player, 8192.0, None, true)
    int renamed = 0
    String others = ""
    int i = 0
    while i < actors.Length
        Actor candidate = actors[i]
        if candidate && candidate != player
            String shown = candidate.GetDisplayName()
            int first = StringUtil.AsOrd(StringUtil.GetNthChar(shown, 0))
            if (first >= 65 && first <= 90) || (first >= 97 && first <= 122)
                if StorageUtil.GetStringValue(candidate, "RNE_Name") != ""
                    rechange.Cast(player, candidate)
                    renamed += 1
                elseif StringUtil.GetLength(others) < 200
                    others = others + shown + "; "
                endif
            endif
        endif
        i += 1
    endwhile
    AIAgentFunctions.logMessage("tesrussify@@renamed " + renamed + "; not Real Names: " + others, "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesdress <runtime FormID as decimal>" - the selected actor gets the
; item and wears it for good: EquipItem with abPreventRemoval, so the NPC does not switch
; back to its outfit (console equipitem on NPCs does not stick).
Function TESDress(String formIdText) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    Form item = Game.GetForm(formIdText as int)
    if !target || !item
        AIAgentFunctions.logMessage("tesdress " + formIdText + "@@error: no actor selected or item not found", "tes_god_console")
        return
    endif
    if target.GetItemCount(item) < 1
        target.AddItem(item, 1, true)
    endif
    target.EquipItem(item, true, true)
    AIAgentFunctions.logMessage("tesdress " + formIdText + "@@" + target.GetDisplayName() + " now wears " + item.GetName(), "tes_god_console")
EndFunction

; TES-Speech-Adapter: an arrest on foot.
;   "tesfollow <actor FormID, decimal>|0" - the selected NPC (the guard) follows that actor:
;        CHIM's own Follow package, faction and linked-ref keyword; 0 takes them off.
;   "tesescort <reference FormID, decimal>" - the selected NPC (the arrested) walks to that
;        reference (the prison marker) with CHIM's TravelTo package; teshold replaces it there.
; essential/protected on both the base and the leveled base of the actor; off also takes ghost off
Function TESMortal(Actor who, bool essential) Global
    ActorBase base = who.GetActorBase()
    ActorBase leveled = who.GetLeveledActorBase()
    if base
        base.SetEssential(essential)
        base.SetProtected(false)
    endif
    if leveled && leveled != base
        leveled.SetEssential(essential)
        leveled.SetProtected(false)
    endif
    if !essential
        who.SetGhost(false)
    endif
EndFunction

Function TESFollowRef(String arg) Global
    Actor npc = ConsoleUtil.GetSelectedReference() as Actor
    Package followPackage = Game.GetFormFromFile(0x01BC25, "AIAgent.esp") as Package
    Faction followFaction = Game.GetFormFromFile(0x01BC24, "AIAgent.esp") as Faction
    Keyword moveTarget = Game.GetFormFromFile(0x021245, "AIAgent.esp") as Keyword
    if !npc || !followPackage || !followFaction
        AIAgentFunctions.logMessage("tesfollow " + arg + "@@error: no actor selected", "tes_god_console")
        return
    endif
    int id = arg as int
    if id <= 0
        ActorUtil.RemovePackageOverride(npc, followPackage)
        npc.RemoveFromFaction(followFaction)
        PO3_SKSEFunctions.SetLinkedRef(npc, None, moveTarget)
        npc.EvaluatePackage()
        AIAgentFunctions.logMessage("tesfollow 0@@" + npc.GetDisplayName() + " is back to his duties", "tes_god_console")
        return
    endif
    ObjectReference whom = Game.GetForm(id) as ObjectReference
    if !whom
        AIAgentFunctions.logMessage("tesfollow " + arg + "@@error: no such actor", "tes_god_console")
        return
    endif
    npc.SetFactionRank(followFaction, 1)
    PO3_SKSEFunctions.SetLinkedRef(npc, whom, moveTarget)
    ActorUtil.AddPackageOverride(npc, followPackage, 100, 0)
    npc.EvaluatePackage()
    AIAgentFunctions.logMessage("tesfollow " + arg + "@@" + npc.GetDisplayName() + " goes after " + whom.GetDisplayName(), "tes_god_console")
EndFunction

Function TESEscort(String arg) Global
    Actor npc = ConsoleUtil.GetSelectedReference() as Actor
    Package travelPackage = Game.GetFormFromFile(0x01ABFE, "AIAgent.esp") as Package
    Faction travelFaction = Game.GetFormFromFile(0x01A69C, "AIAgent.esp") as Faction
    ObjectReference place = Game.GetForm(arg as int) as ObjectReference
    if !npc || !travelPackage || !travelFaction || !place
        AIAgentFunctions.logMessage("tesescort " + arg + "@@error: who or where is missing", "tes_god_console")
        return
    endif
    npc.SetFactionRank(travelFaction, 1)
    PO3_SKSEFunctions.SetLinkedRef(npc, place)
    ActorUtil.AddPackageOverride(npc, travelPackage, 100, 0)
    npc.EvaluatePackage()
    AIAgentFunctions.logMessage("tesescort " + arg + "@@" + npc.GetDisplayName() + " is being led away", "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesjailbox in|out" - a prisoner's belongings.
;   in  - what the selected NPC wears is remembered, then EVERYTHING he carries (armour, weapons,
;         gold) goes into his own hidden chest (TreasChestSmallEMPTYNoRespawn 000F8478, placed
;         disabled where he stands). unequipall alone left it all in the inventory and the
;         prisoner dressed again within seconds.
;   out - the chest gives everything back, he puts on what he wore, the chest is deleted.
Function TESJailBox(String mode) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    if !target || target == Game.GetPlayer()
        AIAgentFunctions.logMessage("tesjailbox " + mode + "@@error: no actor selected", "tes_god_console")
        return
    endif
    ObjectReference box = StorageUtil.GetFormValue(target, "TESJailBox") as ObjectReference
    if mode == "in"
        if !box
            box = target.PlaceAtMe(Game.GetForm(0x000F8478), 1, true, true)
            if !box
                AIAgentFunctions.logMessage("tesjailbox in@@error: no chest", "tes_god_console")
                return
            endif
            StorageUtil.SetFormValue(target, "TESJailBox", box)
            StorageUtil.FormListClear(target, "TESJailWorn")
            int bit = 0
            while bit < 32
                Form worn = target.GetWornForm(Math.LeftShift(1, bit))
                if worn
                    StorageUtil.FormListAdd(target, "TESJailWorn", worn, false)
                endif
                bit += 1
            endwhile
            Form inRight = target.GetEquippedObject(1)
            if inRight
                StorageUtil.FormListAdd(target, "TESJailWorn", inRight, false)
            endif
            Form inLeft = target.GetEquippedObject(0)
            if inLeft
                StorageUtil.FormListAdd(target, "TESJailWorn", inLeft, false)
            endif
        endif
        target.UnequipAll()
        target.RemoveAllItems(box, true, true)
        AIAgentFunctions.logMessage("tesjailbox in@@" + target.GetDisplayName() + ": " + box.GetNumItems() + " kinds of items taken away", "tes_god_console")
        return
    endif
    if !box
        AIAgentFunctions.logMessage("tesjailbox out@@" + target.GetDisplayName() + ": nothing was taken", "tes_god_console")
        return
    endif
    int kinds = box.GetNumItems()
    box.RemoveAllItems(target, true, true)
    box.Disable()
    box.Delete()
    StorageUtil.UnsetFormValue(target, "TESJailBox")
    int n = StorageUtil.FormListCount(target, "TESJailWorn")
    int i = 0
    while i < n
        Form piece = StorageUtil.FormListGet(target, "TESJailWorn", i)
        if piece && target.GetItemCount(piece) > 0
            target.EquipItem(piece, false, true)
        endif
        i += 1
    endwhile
    StorageUtil.FormListClear(target, "TESJailWorn")
    AIAgentFunctions.logMessage("tesjailbox out@@" + target.GetDisplayName() + ": " + kinds + " kinds of items returned", "tes_god_console")
EndFunction

; TES-Speech-Adapter: "teshold <reference FormID, decimal>|0" - keep the selected NPC at an
; existing reference (a jail's PrisonMarker). Live 2026-10-04: Хеймскр, jailed with moveto +
; setrestrained, was back at the Talos statue every few minutes - once the jail cell unloads,
; the game moves an NPC along his own schedule whatever "restrained" says. CHIM's SandboxWork
; package (sandbox near the linked ref) at priority 100 makes the cell his schedule.
;   <id> - link to that reference and add the package;   0 - remove both (old schedule back).
; The reference is the game's own and is never deleted (unlike tesroutine's marker).
Function TESHold(String arg) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    if !target
        AIAgentFunctions.logMessage("teshold " + arg + "@@error: no actor selected", "tes_god_console")
        return
    endif
    Faction sandboxFaction = Game.GetFormFromFile(0x21246, "AIAgent.esp") as Faction
    Package sandboxWork = Game.GetFormFromFile(0x40BE6, "AIAgent.esp") as Package
    if !sandboxFaction || !sandboxWork
        AIAgentFunctions.logMessage("teshold " + arg + "@@error: CHIM sandbox package not found", "tes_god_console")
        return
    endif
    int id = arg as int
    if id <= 0
        if StorageUtil.GetIntValue(target, "TESHeld") == 1
            ActorUtil.RemovePackageOverride(target, sandboxWork)
            target.RemoveFromFaction(sandboxFaction)
            PO3_SKSEFunctions.SetLinkedRef(target, None)
            StorageUtil.UnsetIntValue(target, "TESHeld")
            target.SetDontMove(false)
            target.EvaluatePackage()
        endif
        AIAgentFunctions.logMessage("teshold 0@@" + target.GetDisplayName() + " is no longer held", "tes_god_console")
        return
    endif
    ObjectReference place = Game.GetForm(id) as ObjectReference
    if !place
        AIAgentFunctions.logMessage("teshold " + arg + "@@error: no such reference", "tes_god_console")
        return
    endif
    Package escortTravel = Game.GetFormFromFile(0x01ABFE, "AIAgent.esp") as Package
    Faction escortFaction = Game.GetFormFromFile(0x01A69C, "AIAgent.esp") as Faction
    if escortTravel
        ActorUtil.RemovePackageOverride(target, escortTravel)
    endif
    if escortFaction
        target.RemoveFromFaction(escortFaction)
    endif
    StorageUtil.SetIntValue(target, "TESHeld", 1)
    target.SetFactionRank(sandboxFaction, 1)
    PO3_SKSEFunctions.SetLinkedRef(target, place)
    ActorUtil.AddPackageOverride(target, sandboxWork, 100, 0)
    target.EvaluatePackage()
    ; the Whiterun cell has a way out and a sandboxing prisoner finds it: he does not move at all
    target.SetDontMove(true)
    AIAgentFunctions.logMessage("teshold " + arg + "@@" + target.GetDisplayName() + " is held there", "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesroutine here|reset" - a new daily life for the selected NPC.
;   here  - a persistent XMarker where the player stands; the NPC is linked to it and gets
;           CHIM's SandboxWork package (AIAgent.esp 0x40BE6, sandbox near the linked ref,
;           needs CHIM's sandbox faction 0x21246) at priority 90, above its own schedule;
;   reset - package, faction, link and marker removed: back to the old schedule.
Function TESRoutine(String mode) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    if !target
        AIAgentFunctions.logMessage("tesroutine " + mode + "@@error: no actor selected", "tes_god_console")
        return
    endif
    Faction sandboxFaction = Game.GetFormFromFile(0x21246, "AIAgent.esp") as Faction
    Package sandboxWork = Game.GetFormFromFile(0x40BE6, "AIAgent.esp") as Package
    ObjectReference oldMarker = StorageUtil.GetFormValue(target, "TESRoutineMarker") as ObjectReference
    if mode == "reset"
        ActorUtil.RemovePackageOverride(target, sandboxWork)
        target.RemoveFromFaction(sandboxFaction)
        PO3_SKSEFunctions.SetLinkedRef(target, None)
        if oldMarker
            oldMarker.Disable()
            oldMarker.Delete()
        endif
        StorageUtil.UnsetFormValue(target, "TESRoutineMarker")
        target.EvaluatePackage()
        AIAgentFunctions.logMessage("tesroutine reset@@" + target.GetDisplayName() + " is back to the old schedule", "tes_god_console")
        return
    endif
    if !sandboxFaction || !sandboxWork
        AIAgentFunctions.logMessage("tesroutine here@@error: CHIM sandbox package not found", "tes_god_console")
        return
    endif
    ; "tesroutine at <reference FormID, decimal>": the same life, but around an EXISTING place
    ; (the market, a beggar's corner) instead of where the player stands - "иди побирайся на
    ; улице": with "here" the ex-jarl stayed in Dragonsreach where the player was (live 2026-10-04)
    if StringUtil.Find(mode, "at ") == 0
        ObjectReference place = Game.GetForm(StringUtil.Substring(mode, 3) as int) as ObjectReference
        if !place
            AIAgentFunctions.logMessage("tesroutine " + mode + "@@error: place not found", "tes_god_console")
            return
        endif
        if oldMarker
            oldMarker.Disable()
            oldMarker.Delete()
        endif
        StorageUtil.UnsetFormValue(target, "TESRoutineMarker")
        target.SetFactionRank(sandboxFaction, 1)
        PO3_SKSEFunctions.SetLinkedRef(target, place)
        ActorUtil.AddPackageOverride(target, sandboxWork, 90, 0)
        target.EvaluatePackage()
        AIAgentFunctions.logMessage("tesroutine " + mode + "@@" + target.GetDisplayName() + " now lives around " + place.GetDisplayName(), "tes_god_console")
        return
    endif
    ObjectReference marker = Game.GetPlayer().PlaceAtMe(Game.GetForm(0x3B), 1, true, false)
    if oldMarker
        oldMarker.Disable()
        oldMarker.Delete()
    endif
    StorageUtil.SetFormValue(target, "TESRoutineMarker", marker)
    target.SetFactionRank(sandboxFaction, 1)
    PO3_SKSEFunctions.SetLinkedRef(target, marker)
    ActorUtil.AddPackageOverride(target, sandboxWork, 90, 0)
    target.EvaluatePackage()
    AIAgentFunctions.logMessage("tesroutine here@@" + target.GetDisplayName() + " now lives around this place", "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesoutfit <runtime FormID as decimal>" - change the selected NPC's
; default outfit (Actor.SetOutfit, the same call CHIM uses for its own characters). Unlike
; equipping items, the game itself puts this outfit on again after every reload of the
; NPC's 3D, so it does not get reset.
Function TESOutfit(String formIdText) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    Outfit wanted = Game.GetForm(formIdText as int) as Outfit
    if !target || !wanted
        AIAgentFunctions.logMessage("tesoutfit " + formIdText + "@@error: no actor selected or outfit not found", "tes_god_console")
        return
    endif
    target.SetOutfit(wanted, false)
    AIAgentFunctions.logMessage("tesoutfit " + formIdText + "@@" + target.GetDisplayName() + " now has a new default outfit", "tes_god_console")
EndFunction