; Instalador de DiamantesExpress Agent (Inno Setup 6). Compilar con build.ps1 -Installer.
#define AppName "DiamantesExpress Agent"
#define AppVersion "0.1.0"
#define AppExe "DiamantesExpressAgent.exe"

[Setup]
AppId={{6C1E4B0E-5D57-4E44-9C3B-2F1D7E2D9A11}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=DiamantesExpress
DefaultDirName={autopf}\DiamantesExpressAgent
DefaultGroupName={#AppName}
OutputDir=..\dist\installer
OutputBaseFilename=DiamantesExpressAgent-Setup
SetupIconFile=dxagent.ico
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=admin
WizardStyle=modern

[Languages]
Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"

[Tasks]
; Emulador Android a usar. La elección se guarda en la configuración del usuario y se puede cambiar
; después desde Configuración → Emulador Android y ADB.
Name: "emu_bluestacks"; Description: "BlueStacks 5"; GroupDescription: "Emulador Android a usar (se puede cambiar luego en Configuración):"; Flags: exclusive
Name: "emu_ldplayer"; Description: "LDPlayer 9"; GroupDescription: "Emulador Android a usar (se puede cambiar luego en Configuración):"; Flags: exclusive unchecked
Name: "emu_none"; Description: "Ninguno por ahora (simulador interno, solo pruebas)"; GroupDescription: "Emulador Android a usar (se puede cambiar luego en Configuración):"; Flags: exclusive unchecked
Name: "autostart"; Description: "Iniciar DiamantesExpress Agent al iniciar sesión en Windows"; Flags: checkedonce
Name: "desktopicon"; Description: "Crear acceso directo en el escritorio"; Flags: unchecked

[Files]
Source: "..\dist\DiamantesExpressAgent\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#AppExe}"; Parameters: "ui"
Name: "{group}\Diagnóstico"; Filename: "{cmd}"; Parameters: "/k ""{app}\dxagent-cli.exe"" doctor"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; Parameters: "ui"; Tasks: desktopicon

[Registry]
; Inicio automático por usuario (la interfaz se inicia minimizada y lanza el servicio).
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; \
  ValueName: "DiamantesExpressAgent"; ValueData: """{app}\{#AppExe}"" ui --minimized"; \
  Flags: uninsdeletevalue; Tasks: autostart

[Run]
; runasoriginaluser: escribe la configuración en %LOCALAPPDATA% del usuario que instaló, no del administrador.
Filename: "{app}\dxagent-cli.exe"; Parameters: "configure --emulator bluestacks"; Flags: runhidden runasoriginaluser waituntilterminated; Tasks: emu_bluestacks
Filename: "{app}\dxagent-cli.exe"; Parameters: "configure --emulator ldplayer"; Flags: runhidden runasoriginaluser waituntilterminated; Tasks: emu_ldplayer
Filename: "{app}\dxagent-cli.exe"; Parameters: "configure --emulator none"; Flags: runhidden runasoriginaluser waituntilterminated; Tasks: emu_none
Filename: "{app}\{#AppExe}"; Parameters: "ui"; Description: "Abrir {#AppName}"; Flags: nowait postinstall skipifsilent

[UninstallRun]
Filename: "{app}\dxagent-cli.exe"; Parameters: "emergency-stop"; Flags: runhidden; RunOnceId: "StopAgent"
