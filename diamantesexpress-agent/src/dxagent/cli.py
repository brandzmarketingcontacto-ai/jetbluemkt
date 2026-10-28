"""Línea de comandos de DiamantesExpress Agent.

  dxagent ui [--minimized]        Interfaz gráfica (inicia el servicio si no corre)
  dxagent service                 Servicio sin interfaz (proceso de ejecución)
  dxagent doctor                  Diagnóstico del entorno (no consume tokens)
  dxagent simulate --orders 100   Simulación integral aislada (backend + Android ficticios)
  dxagent mock-backend            Backend DiamantesExpress simulado en localhost
  dxagent install-sim-apk         Instala la app de simulación en BlueStacks/LDPlayer
  dxagent check-api               Heartbeat real contra DiamantesExpress (no reclama tareas)
  dxagent set-secret NOMBRE       Guardar token o clave OpenAI en el almacén seguro
  dxagent screenshot --out f.png  Captura real por ADB
  dxagent analyze-screen --image  Interpretar una captura con OpenAI (consume tokens)
  dxagent configure --emulator ldplayer|bluestacks|none   Elegir emulador
  dxagent autostart on|off        Inicio automático con Windows
  dxagent emergency-stop | clear-emergency
"""

from __future__ import annotations

import argparse
import asyncio
import getpass
import json
import logging
import os
import sys
import tempfile
import time
from datetime import timedelta
from pathlib import Path


def _print(obj) -> None:
    print(json.dumps(obj, indent=2, ensure_ascii=False, default=str))


def cmd_doctor(_args) -> int:
    from dxagent import __version__, paths
    from dxagent.android.emulator import EMULATORS, build_emulator
    from dxagent.api.contract import ApiContract
    from dxagent.ai.pricing import PricingTable
    from dxagent.engine.workflow_def import load_workflow
    from dxagent.settings import SettingsManager
    from dxagent.winplatform import autostart_enabled, has_interactive_desktop

    manager = SettingsManager()
    s = manager.load()
    report: dict = {"version": __version__, "python": sys.version.split()[0], "platform": sys.platform,
                    "data_dir": str(paths.data_dir()), "interactive_desktop": has_interactive_desktop(),
                    "autostart": autostart_enabled(), "mode": s.automation_mode.value, "device_backend": s.device_backend}
    try:
        contract = ApiContract.load(s.openapi_contract_path or None, s.route_overrides)
        report["contract"] = {"source": contract.source, "operations": len(contract.operations)}
    except Exception as exc:
        report["contract"] = f"ERROR: {exc}"
    report["api_url"] = s.api_url or "NO CONFIGURADA"
    if s.api_url and not s.api_url.rstrip("/").endswith("/hcgi/api"):
        report["api_url_warning"] = "La URL de Horizons normalmente termina en /hcgi/api"
    token = manager.secrets.get("agent_token") or ""
    if token and not token.startswith("dxa_"):
        report["agent_token_warning"] = "Los tokens de DiamantesExpress empiezan con dxa_"
    report["agent_token"] = "configurado" if manager.secrets.has("agent_token") else "falta"
    report["openai_api_key"] = "configurada" if manager.secrets.has("openai_api_key") else "falta"
    pricing = PricingTable.load(s.pricing_path or None)
    report["pricing"] = {"model": s.openai_model, "priced": pricing.price_for(s.openai_model) is not None,
                         "verified_at": pricing.verified_at}
    try:
        wf = load_workflow(s.workflow_id)
        report["workflow"] = {"id": wf.id, "simulation": wf.simulation, "allowed_modes": wf.allowed_modes}
    except Exception as exc:
        report["workflow"] = f"ERROR: {exc}"
    report["emulator"] = f"{EMULATORS[s.emulator]} ({s.emulator})"
    try:
        report["emulator_status"] = build_emulator(s).status()
    except Exception as exc:
        report["emulator_status"] = f"ERROR: {exc}"
    from dxagent.audit import AuditLog
    ok, count = AuditLog(paths.logs_dir() / "audit.jsonl").verify()
    report["audit_log"] = {"integro": ok, "registros": count}
    _print(report)
    return 0


def cmd_simulate(args) -> int:
    """Ejecuta N pedidos ficticios de punta a punta en un directorio aislado."""
    home = Path(args.home or tempfile.mkdtemp(prefix="dxagent-sim-"))
    os.environ["DXAGENT_HOME"] = str(home)
    from dxagent import paths
    from dxagent.api.client import HorizonsApiClient
    from dxagent.api.contract import ApiContract
    from dxagent.api.mock_backend import MockHorizonsBackend
    from dxagent.android.mock_device import MockDevice
    from dxagent.database import LocalDatabase
    from dxagent.logging_setup import setup_logging
    from dxagent.service.agent import AgentService
    from dxagent.settings import AgentSettings, AutomationMode, SecretStore

    setup_logging(paths.logs_dir(), "simulate", logging.WARNING)
    backend = MockHorizonsBackend(mode="simulation")
    # Pedidos v1.1 de dos fases (amistad + regalo). Las cantidades altas se dividen por maxPerTransaction.
    kinds = list(backend.catalog)
    expected: dict[str, int] = {}
    for i in range(args.orders):
        kind = kinds[i % len(kinds)]
        quantity = 1 if kind == "pase_booyah" else (100 if i % 3 == 1 else 1 + i % 40)
        backend.add_order(f"DX-SIM-{i:04d}", str(100000000 + i), kind, quantity)
        expected[f"DX-SIM-{i:04d}"] = quantity
    # Algunos clientes ya eran amigos; el resto acepta la solicitud tras el primer reintento.
    device = MockDevice(require_friendship=True, friends={str(100000000 + i) for i in range(0, args.orders, 4)})
    db = LocalDatabase(paths.db_path())
    settings = AgentSettings(api_url="https://mock.local/hcgi/api", agent_name="simulador",
                             automation_mode=AutomationMode.SIMULATION, vision_backend="disabled",
                             poll_interval_seconds=0.01)
    api = HorizonsApiClient(settings.api_url, "dxa_test_token_123456", ApiContract.load(), agent_name="simulador",
                            transport=backend.transport())

    async def factory():
        return device

    agent = AgentService(settings, db, SecretStore(env={}, use_keyring=False), api=api, device_factory=factory,
                         engine_overrides={"settle_s": 0.0, "poll_interval_s": 0.01, "screen_wait_timeout_s": 2.0})
    clock_offset = [0.0]
    real_clock = backend.clock
    backend.clock = lambda: real_clock() + timedelta(minutes=clock_offset[0])

    async def run():
        await agent.startup()
        count = 0
        started = time.monotonic()
        for round_ in range(4):  # cada ronda simula que pasa friendRetryMinutes
            while await agent.work_once():
                await agent.sync.flush_all()
                count += 1
                if count % 10 == 0:
                    print(f"  {count} tareas procesadas…")
            if round_ > 0:  # el cliente tarda: el primer reintento se aplaza con /defer
                device.accept_pending_requests()
            clock_offset[0] += backend.friend_retry_minutes + 1
        await agent.sync.flush_all()
        await agent.api.aclose()
        return count, time.monotonic() - started

    count, elapsed = asyncio.run(run())
    stats = db.job_stats_today()
    delivered = {code: backend.order_delivered(code) for code in expected}
    refs = [t.ref for t in device.transactions]
    result = {"directorio": str(home), "pedidos": args.orders, "tareas_procesadas": count,
              "segundos": round(elapsed, 2), **stats,
              "unidades_pedidas": sum(expected.values()), "unidades_entregadas": sum(delivered.values()),
              "pedidos_incompletos": [c for c, q in expected.items() if delivered[c] != q],
              "aplazamientos": len(backend.defers), "entregas_ficticias": len(device.transactions),
              "duplicados": len(refs) - len(set(refs)),
              "estados_en_servidor": {st: sum(1 for t in backend.tasks.values() if t["status"] == st)
                                      for st in {t["status"] for t in backend.tasks.values()}},
              "outbox_pendiente": db.outbox_pending_count()}
    _print(result)
    ok = not result["pedidos_incompletos"] and result["duplicados"] == 0 and result["outbox_pendiente"] == 0
    return 0 if ok else 1


def cmd_mock_backend(args) -> int:
    from dxagent.api.mock_backend import MockHorizonsBackend

    backend = MockHorizonsBackend(token=args.token, mode=args.mode)
    backend.friend_retry_minutes = args.friend_retry_minutes
    kinds = list(backend.catalog)
    for i in range(args.jobs):
        kind = kinds[i % len(kinds)]
        backend.add_order(f"DX-MOCK-{i:04d}", str(200000000 + i), kind, 1 if kind == "pase_booyah" else 100)
    server = backend.serve("127.0.0.1", args.port)
    print(f"Backend simulado (contrato real) en http://127.0.0.1:{args.port}/hcgi/api  modo={args.mode}")
    print(f"  DX_AGENT_TOKEN={args.token}")
    print("  En Configuración: URL de la API = la dirección de arriba, token = el de arriba y marque")
    print("  «Permitir HTTP solo hacia esta PC» (solo pruebas locales). Ctrl+C para detener.")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


def cmd_check_api(_args) -> int:
    """Heartbeat + tareas actuales. No reclama tareas ni modifica pedidos."""
    from dxagent.api.client import HorizonsApiClient
    from dxagent.api.contract import ApiContract
    from dxagent.settings import SettingsManager

    manager = SettingsManager()
    s = manager.load()

    async def go():
        client = HorizonsApiClient(s.api_url, manager.secrets.get("agent_token"),
                                   ApiContract.load(s.openapi_contract_path or None, s.route_overrides),
                                   agent_name=s.agent_name, timeout=s.api_timeout_seconds,
                                   allow_insecure_localhost=s.allow_insecure_http_localhost)
        try:
            state = await client.heartbeat()
            current = await client.current_tasks()
            return {"api_url": client.base_url, "server": state.__dict__,
                    "tareas_reclamadas_por_este_agente": len(current)}
        finally:
            await client.aclose()
    _print(asyncio.run(go()))
    return 0


def cmd_set_secret(args) -> int:
    from dxagent.settings import SettingsManager
    name = {"agent-token": "agent_token", "openai-key": "openai_api_key"}[args.name]
    value = getpass.getpass(f"Valor para {args.name} (no se mostrará): ").strip()
    if not value:
        return 1
    ok = SettingsManager().secrets.set(name, value)
    print("Guardado en el almacén seguro." if ok else "ADVERTENCIA: almacén seguro no disponible; no se guardó.")
    return 0 if ok else 1


def cmd_screenshot(args) -> int:
    from dxagent.android.emulator import build_emulator
    from dxagent.settings import SettingsManager
    s = SettingsManager().load()
    manager = build_emulator(s)  # BlueStacks 5 o LDPlayer 9, según Configuración

    async def go():
        device = await manager.ensure_ready(s.emulator_boot_timeout_seconds)
        png = await device.screenshot_png()
        Path(args.out).write_bytes(png)
        return {"serial": device.serial, "size": await device.screen_size(), "orientation": await device.orientation(),
                "foreground": await device.current_package(), "file": args.out}
    _print(asyncio.run(go()))
    return 0


def cmd_install_sim_apk(args) -> int:
    """Instala en el emulador configurado la app ficticia incluida en el descargable (sin Android Studio)."""
    from dxagent import paths
    from dxagent.android.emulator import build_emulator
    from dxagent.settings import SettingsManager
    apk = Path(args.apk) if args.apk else paths.simulator_apk()
    if not apk.is_file():
        print(f"No se encontró la app de simulación en {apk}")
        return 1
    s = SettingsManager().load()
    manager = build_emulator(s)

    async def go():
        device = await manager.ensure_ready(s.emulator_boot_timeout_seconds)
        installed = await device.install_simulator_apk(apk)
        return {"serial": device.serial, "apk": str(apk), "instalada": installed}
    result = asyncio.run(go())
    _print(result)
    return 0 if result["instalada"] else 1


def cmd_analyze(args) -> int:
    from dxagent import paths
    from dxagent.ai.pricing import PricingTable
    from dxagent.ai.usage import UsageTracker
    from dxagent.ai.vision import OpenAIVisionAgent, build_openai_client
    from dxagent.database import LocalDatabase
    from dxagent.engine.workflow_def import load_workflow
    from dxagent.settings import SettingsManager

    manager = SettingsManager()
    s = manager.load()
    key = manager.secrets.get("openai_api_key")
    if not key:
        print("Falta OPENAI_API_KEY")
        return 1
    wf = load_workflow(args.workflow or s.workflow_id)
    db = LocalDatabase(paths.db_path())
    tracker = UsageTracker(db, PricingTable.load(s.pricing_path or None), s.agent_name or "local",
                           s.max_ai_cost_per_order_usd, s.daily_ai_budget_usd, s.max_ai_requests_per_order,
                           s.ai_reserve_per_request_usd)
    vision = OpenAIVisionAgent(build_openai_client(key, s.openai_timeout_seconds), s.openai_model, tracker,
                               s.ai_image_detail, s.openai_max_output_tokens)

    async def go():
        return await vision.analyze(Path(args.image).read_bytes(), job_id=None, order_id=None,
                                    screens=[{"id": k, "descripcion": v.description} for k, v in wf.screens.items()],
                                    elements=[{"id": k, "descripcion": v.description} for k, v in wf.elements.items()],
                                    fields=[f"{k}: {v.description}" for k, v in wf.fields.items()],
                                    goal="Diagnóstico manual de pantalla", purpose="cli_analyze")
    analysis = asyncio.run(go())
    _print({"analysis": analysis.model_dump(), "usage": db.recent_usage(1)[0]})
    return 0


def cmd_configure(args) -> int:
    """Configuración inicial (la usa el instalador): emulador y uso de ADB."""
    from dxagent.android.emulator import EMULATORS
    from dxagent.settings import SettingsManager
    manager = SettingsManager()
    s = manager.load()
    if args.emulator == "none":
        s.device_backend = "mock"
    else:
        s.emulator = args.emulator
        s.device_backend = "adb"
    if args.instance is not None:
        s.emulator_instance = args.instance
    manager.save(s)
    label = "simulador interno" if s.device_backend == "mock" else EMULATORS[s.emulator]
    print(f"Emulador configurado: {label}. Puede cambiarlo en Configuración → Emulador Android y ADB.")
    return 0


def cmd_autostart(args) -> int:
    from dxagent.winplatform import set_autostart
    ok = set_autostart(args.state == "on")
    print("Inicio automático actualizado." if ok else "Solo disponible en Windows.")
    return 0 if ok else 1


def cmd_emergency(args) -> int:
    from dxagent import paths
    from dxagent.database import LocalDatabase
    db = LocalDatabase(paths.db_path())
    flag = paths.emergency_flag_path()
    if args.command == "emergency-stop":
        flag.write_text(f"{time.time()} cli:{getpass.getuser()}", encoding="utf-8")
        db.push_command("emergency_stop", issued_by=f"cli:{getpass.getuser()}")
        print("Parada de emergencia activada.")
    else:
        if flag.exists():
            flag.unlink()
        db.push_command("clear_emergency", issued_by=f"cli:{getpass.getuser()}")
        print("Emergencia liberada; el agente queda en pausa.")
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="dxagent", description="DiamantesExpress Agent")
    sub = parser.add_subparsers(dest="command")
    ui = sub.add_parser("ui", help="Interfaz gráfica")
    ui.add_argument("--minimized", action="store_true")
    ui.add_argument("--no-service", action="store_true", help="No iniciar el servicio automáticamente")
    sub.add_parser("service", help="Servicio de ejecución sin interfaz")
    sub.add_parser("doctor", help="Diagnóstico del entorno")
    sim = sub.add_parser("simulate", help="Simulación integral aislada")
    sim.add_argument("--orders", type=int, default=20)
    sim.add_argument("--home", default="")
    mb = sub.add_parser("mock-backend", help="Backend simulado local")
    mb.add_argument("--port", type=int, default=8787)
    mb.add_argument("--jobs", type=int, default=10, help="Pedidos de dos fases a crear")
    mb.add_argument("--token", default="dxa_test_token_123456")
    mb.add_argument("--friend-retry-minutes", type=int, default=1)
    mb.add_argument("--mode", default="simulation", choices=["off", "simulation", "supervised", "production"])
    isa = sub.add_parser("install-sim-apk", help="Instala la app de simulación en el emulador configurado")
    isa.add_argument("--apk", default="", help="Ruta del APK (por defecto, el incluido junto al programa)")
    sub.add_parser("check-api", help="Heartbeat real contra DiamantesExpress (no reclama tareas)")
    sec = sub.add_parser("set-secret", help="Guardar un secreto")
    sec.add_argument("name", choices=["agent-token", "openai-key"])  # agent-token = dxa_...
    shot = sub.add_parser("screenshot", help="Captura por ADB")
    shot.add_argument("--out", default="captura.png")
    an = sub.add_parser("analyze-screen", help="Analizar captura con OpenAI")
    an.add_argument("--image", required=True)
    an.add_argument("--workflow", default="")
    conf = sub.add_parser("configure", help="Elegir emulador (lo usa el instalador)")
    conf.add_argument("--emulator", required=True, choices=["bluestacks", "ldplayer", "none"])
    conf.add_argument("--instance", default=None, help="BlueStacks: nombre interno; LDPlayer: índice o nombre")
    auto = sub.add_parser("autostart", help="Inicio automático")
    auto.add_argument("state", choices=["on", "off"])
    sub.add_parser("emergency-stop")
    sub.add_parser("clear-emergency")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    command = args.command or "ui"
    if command == "ui":
        from dxagent.ui.app import run_ui
        return run_ui(getattr(args, "minimized", False), not getattr(args, "no_service", False))
    if command == "service":
        from dxagent.service.bootstrap import run_service
        return run_service()
    handlers = {"doctor": cmd_doctor, "simulate": cmd_simulate, "mock-backend": cmd_mock_backend,
                "check-api": cmd_check_api, "set-secret": cmd_set_secret, "screenshot": cmd_screenshot,
                "analyze-screen": cmd_analyze, "install-sim-apk": cmd_install_sim_apk, "autostart": cmd_autostart, "configure": cmd_configure,
                "emergency-stop": cmd_emergency, "clear-emergency": cmd_emergency}
    return handlers[command](args)
