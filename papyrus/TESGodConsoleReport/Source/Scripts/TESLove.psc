Scriptname TESLove Hidden

; TES-Speech-Adapter: the only script of this mod that touches OStim. Kept apart from the
; bridge on purpose: if OStim is ever removed, this script fails to load and the bridge -
; console, jail, orders - goes on working; only "teslove" reports that it could not start.

; The OStim thread both actors are in right now, or -1.
int Function ThreadOf(Actor first, Actor second) Global
    int[] ids = OThread.GetAllThreadIDs()
    int i = 0
    while i < ids.Length
        Actor[] inside = OThread.GetActors(ids[i])
        if inside.Find(first) >= 0 && (second == None || inside.Find(second) >= 0)
            return ids[i]
        endif
        i += 1
    endwhile
    return -1
EndFunction

; Start an OStim scene for two actors, or switch the one they are already in.
; tags: OStim action / scene tags, comma separated ("vaginalsex", "blowjob", "cowgirl" ...) -
; what kind of scene was asked for; "" = OStim's own start. Returns the thread id, or -1.
int Function Start(Actor first, Actor second, String tags = "") Global
    ; OStim's scenes are written "male, female": the man first (OActorUtil.Sort does the same,
    ; but that script cannot be compiled here - it drags in SkyUI's and JContainers' sources)
    Actor[] actors = new Actor[2]
    if first.GetLeveledActorBase().GetSex() == 1 && second.GetLeveledActorBase().GetSex() == 0
        actors[0] = second
        actors[1] = first
    else
        actors[0] = first
        actors[1] = second
    endif
    String sceneId = ""
    if tags != ""
        sceneId = OLibrary.GetRandomSceneWithAnyActionCSV(actors, tags)
        if sceneId == ""
            sceneId = OLibrary.GetRandomSceneWithAnySceneTagCSV(actors, tags)
        endif
    endif
    int running = ThreadOf(first, second)
    if running >= 0
        if sceneId != ""
            OThread.WarpTo(running, sceneId, true)
        endif
        return running
    endif
    return OThread.QuickStart(actors, sceneId)
EndFunction

; End the scene the actor is in. Returns true when there was one.
bool Function Stop(Actor who) Global
    int running = ThreadOf(who, None)
    if running < 0
        return false
    endif
    OThread.Stop(running)
    return true
EndFunction

; Name of the scene a thread is playing (for the report).
String Function SceneOf(int thread) Global
    return OThread.GetScene(thread)
EndFunction
