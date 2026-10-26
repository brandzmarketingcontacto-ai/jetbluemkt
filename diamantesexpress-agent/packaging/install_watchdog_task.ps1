# OPCIONAL: tarea programada que relanza el SERVICIO al iniciar sesión y lo reinicia si termina.
# BlueStacks necesita escritorio: la tarea corre en la sesión interactiva del usuario (no como servicio
# de Windows en la sesión 0). Ejecutar en PowerShell como el usuario que usará la PC dedicada.
param([string]$Exe = "$env:ProgramFiles\DiamantesExpressAgent\DiamantesExpressAgent.exe")
$action = New-ScheduledTaskAction -Execute $Exe -Argument "service"
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit (New-TimeSpan -Seconds 0) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName "DiamantesExpressAgentService" -Action $action -Trigger $trigger `
    -Settings $settings -Principal $principal -Force
Write-Host "Tarea 'DiamantesExpressAgentService' registrada. El servicio usa un bloqueo de instancia única,"
Write-Host "por lo que convive sin duplicarse con el inicio desde la interfaz."
