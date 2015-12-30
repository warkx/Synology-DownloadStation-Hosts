' Auteur : warkx
' Version originale Developpé le : 28/04/2015
' Version : 1.0
' Description : Créé un fichier Host
 

Set oArgs = WScript.Arguments 
Set oShell = CreateObject("WScript.Shell")
Set oFSO = CreateObject("Scripting.FileSystemObject")

NOM_FICHIER = InputBox ("Entrez le nom du fichier de sortie sans extension", "Creer Fichier Host")

if NOM_FICHIER = "" then
    WScript.quit
end if

SevenZipExe = Chr(34) & "C:\Program Files\7-Zip\7z.exe" & Chr(34)
strDesktop = oShell.SpecialFolders("Desktop")
WorkZone = strDesktop
FileOut = WorkZone & "\" & NOM_FICHIER
HostFile = FileOut & ".host"
command = SevenZipExe & " a -ttar -so " & Chr(34) & FileOut & Chr(34)

For I = 0 to oArgs.Count - 1
    command = command & " " & Chr(34) & oArgs(I) & Chr(34)
Next

command = command & " | " & SevenZipExe & " a -si -tgzip " & Chr(34) & HostFile & Chr(34)

if oFSO.FileExists(HostFile) then
    oFSO.DeleteFile(HostFile)
    Wscript.Sleep 1000
end if
oShell.Run "cmd /c " & Chr(34) & command & Chr(34), 1, true
