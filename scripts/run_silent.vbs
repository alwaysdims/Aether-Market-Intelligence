Set objArgs = WScript.Arguments
If objArgs.Count > 0 Then
    Dim cmd, i
    cmd = """" & objArgs(0) & """"
    For i = 1 To objArgs.Count - 1
        cmd = cmd & " """ & objArgs(i) & """"
    Next
    Dim exitCode
    exitCode = CreateObject("Wscript.Shell").Run(cmd, 0, True)
    WScript.Quit exitCode
End If
