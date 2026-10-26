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
Filename: "{app}\{#AppExe}"; Parameters: "ui"; Description: "Abrir {#AppName}"; Flags: nowait postinstall skipifsilent

[UninstallRun]
Filename: "{app}\dxagent-cli.exe"; Parameters: "emergency-stop"; Flags: runhidden; RunOnceId: "StopAgent"
