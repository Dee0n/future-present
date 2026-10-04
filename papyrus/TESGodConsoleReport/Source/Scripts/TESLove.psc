Scriptname TESLove Hidden

; TES-Speech-Adapter: the only script of this mod that touches OStim. Kept apart from the
; bridge on purpose: if OStim is ever removed, this script fails to load and the bridge -
; console, jail, orders - goes on working; only "teslove" reports that it could not start.

; Start an OStim scene for two actors. Returns the OStim thread id, or -1.
int Function Start(Actor first, Actor second) Global
    Actor[] actors = new Actor[2]
    actors[0] = first
    actors[1] = second
    return OThread.QuickStart(actors)
EndFunction
