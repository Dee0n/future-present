scriptname RealNamesExtended Hidden

{}


function LoadFromJson(RealNamesChange RNChange, Actor akTarget, String folderName, String racePath, String categoryPath) global
{
  Loads names from json files according to race and category (usually gender) 
  Also requires a path to the race-specific settings, as a workaround to Skyrim's
  bizarre string caching
  e.g. LoadFromJson(target, "Nord", ".Nord", ".male")
}
  Debug.Trace("RealNamesExtended: Loading .json files in " + "../RealNamesExtended/" + folderName)
  string[] jsonFiles = JsonUtil.JsonInFolder("../RealNamesExtended/" + folderName)
  string[] firstNames
  string[] lastNames

  int i = 0
  while (i < jsonFiles.Length)
    Debug.Trace("RealNamesExtended: Loading file " + "../RealNamesExtended/" + folderName + "/" +jsonFiles[i] + "...", 0)
    string[] newFirstNames = JsonUtil.PathStringElements("../RealNamesExtended/" + folderName + "/" +jsonFiles[i], categoryPath+".first")
    firstNames = PapyrusUtil.MergeStringArray(firstNames, newFirstNames)
    newFirstNames = JsonUtil.PathStringElements("../RealNamesExtended/" + folderName + "/" +jsonFiles[i], ".all.first")
    firstNames = PapyrusUtil.MergeStringArray(firstNames, newFirstNames)
    string[] newLastNames = JsonUtil.PathStringElements("../RealNamesExtended/" + folderName + "/" +jsonFiles[i], categoryPath+".last")
    lastNames = PapyrusUtil.MergeStringArray(lastNames, newLastNames)
    newLastNames = JsonUtil.PathStringElements("../RealNamesExtended/" + folderName + "/" +jsonFiles[i], ".all.last")
    lastNames = PapyrusUtil.MergeStringArray(lastNames, newLastNames)
    i += 1
  endWhile
  Debug.Trace("RealNamesExtended: Loaded " + firstNames.Length + " first names and " + lastNames.Length + " last names in total.", 0)

  ; TES: the same NPC always gets the same name (live 2026-10-04: Whiterun guard 000D0FF4 had five names -
  ; a name rolled after the last save was lost on reload and rolled again). The pick comes from the FormID.
  int seed = Math.LogicalAnd(akTarget.GetFormID(), 0x7FFFFFFF)
  String newFirstName = ""
  String newLastName = ""
  if (firstNames.Length > 0)
    Int j = seed % firstNames.Length
    newFirstName = firstNames[j]
  endIf

  int l = (seed / 7) % 100
  int lastNameChance = JsonUtil.GetPathIntValue("../RealNamesExtended/Settings.json", racePath+".lastnameChance", 100)
  Debug.Trace("RealNamesExtended: Loading lastnameChance at path: " + racePath+".lastnameChance")
  Debug.Trace("RealNamesExtended: Rolled " + l + " for last name against a chance of " + lastNameChance)

  if (lastNames.Length > 0 && l < lastNameChance) 
    Int k = (seed / 131) % lastNames.Length
    newLastName = lastNames[k]
  endIf

  if (newFirstName != "") ; We don't care if the last name is empty, but if there's no first name then don't even try to change it.
    Debug.Trace("RealNamesExtended: Picked name: " + newFirstName + " " + newLastName + ".", 0)
    RNChange.ChangeName(akTarget, newFirstName, newLastName)
  endif
EndFunction
