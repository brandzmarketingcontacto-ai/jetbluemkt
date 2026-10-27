"""LDPlayer 9: detección, instancias, inicio y conexión ADB con un ldconsole y un adb falsos."""

import json
import sys
from pathlib import Path

import pytest

from conftest import make_settings
from dxagent.android.device import DeviceError
from dxagent.android.emulator import build_emulator
from dxagent.android.ldplayer import LDPlayerManager, parse_list2

LIST2 = ("0,LDPlayer,264218,329754,1,4120,5032,900,1600,240\n"
         "1,Pruebas DX,0,0,0,-1,-1,1280,720,240\n")


class FakeConsole:
    def __init__(self, output=LIST2):
        self.output = output
        self.calls = []

    def __call__(self, console, args):
        self.calls.append(args)
        return self.output if args == ["list2"] else ""


@pytest.fixture
def ld_dir(tmp_path):
    d = tmp_path / "LDPlayer9"
    d.mkdir()
    (d / "ldconsole.exe").write_bytes(b"")
    (d / "adb.exe").write_bytes(b"")
    return d


def test_parse_list2():
    inst = parse_list2(LIST2 + "basura\n")
    assert set(inst) == {0, 1}
    assert inst[0].android_started and inst[0].pid == 4120 and (inst[0].width, inst[0].height) == (900, 1600)
    assert not inst[1].android_started and inst[1].pid == 0
    assert (inst[0].emulator_serial, inst[0].adb_port) == ("emulator-5554", 5555)
    assert (inst[1].emulator_serial, inst[1].adb_port) == ("emulator-5556", 5557)


def test_status_and_launch_by_index_or_name(ld_dir):
    console = FakeConsole()
    by_name = LDPlayerManager(str(ld_dir), instance="Pruebas DX", console_runner=console, registry=lambda: None)
    st = by_name.status()
    assert st["installed"] and st["emulator"] == "ldplayer" and st["install_dir"] == str(ld_dir)
    assert st["instances"]["1"]["display_name"] == "Pruebas DX" and st["running"] is False
    assert st["adb_path"].endswith("adb.exe")
    by_name.launch()
    assert ["launch", "--index", "1"] in console.calls
    by_name.stop()
    assert ["quit", "--index", "1"] in console.calls
    main = LDPlayerManager(str(ld_dir), instance="0", console_runner=console, registry=lambda: None)
    assert main.is_running() is True


def test_not_installed_gives_clear_error(tmp_path):
    manager = LDPlayerManager(str(tmp_path / "no"), console_runner=FakeConsole(), registry=lambda: None)
    if not manager.installed:
        with pytest.raises(DeviceError, match="LDPlayer"):
            manager.launch()


async def test_connect_uses_emulator_serial_or_local_port(ld_dir, tmp_path, monkeypatch):
    log = tmp_path / "adb.log"
    monkeypatch.setenv("FAKE_ADB_LOG", str(log))
    manager = LDPlayerManager(str(ld_dir), instance="0", console_runner=FakeConsole(), registry=lambda: None)
    from dxagent.android.adb import AdbRunner
    manager.runner = AdbRunner([sys.executable, str(Path(__file__).with_name("fake_adb.py"))], timeout=10)
    device = await manager.connect()
    # El adb falso solo lista 127.0.0.1:5565, así que se conecta al puerto local de la instancia 0.
    assert device.serial == "127.0.0.1:5555"
    calls = [json.loads(line) for line in log.read_text().splitlines()]
    assert ["connect", "127.0.0.1:5555"] in calls


def test_factory_selects_emulator_from_settings(ld_dir):
    s = make_settings(emulator="ldplayer", ldplayer_install_dir=str(ld_dir), emulator_instance="1")
    assert build_emulator(s).name == "ldplayer"
    assert build_emulator(make_settings(emulator="bluestacks")).name == "bluestacks"
    with pytest.raises(ValueError):
        make_settings(emulator="nox")


def test_settings_env_and_legacy_instance(tmp_path):
    from dxagent.settings import SecretStore, SettingsManager
    (tmp_path / "settings.json").write_text(json.dumps({"bluestacks_instance": "Pie64_1"}))
    manager = SettingsManager(tmp_path / "settings.json", env={}, env_file=tmp_path / ".env",
                              secrets=SecretStore(env={}, use_keyring=False))
    assert manager.load().emulator_instance == "Pie64_1"  # migración de la versión anterior
    env = {"DX_EMULATOR": "ldplayer", "EMULATOR_INSTANCE": "0", "ADB_DEVICE_SERIAL": "emulator-5554"}
    manager = SettingsManager(tmp_path / "settings.json", env=env, env_file=tmp_path / ".env",
                              secrets=SecretStore(env={}, use_keyring=False))
    s = manager.load()
    assert (s.emulator, s.emulator_instance, s.adb_serial) == ("ldplayer", "0", "emulator-5554")


def test_cli_configure_used_by_installer():
    from dxagent.cli import main
    from dxagent.settings import SettingsManager
    assert main(["configure", "--emulator", "ldplayer", "--instance", "0"]) == 0
    s = SettingsManager().load()
    assert (s.emulator, s.device_backend, s.emulator_instance) == ("ldplayer", "adb", "0")
    assert main(["configure", "--emulator", "none"]) == 0
    assert SettingsManager().load().device_backend == "mock"


def test_settings_page_switches_emulator(qapp):
    from dxagent.settings import SettingsManager
    from dxagent.ui.settings_page import SettingsPage
    page = SettingsPage(SettingsManager())
    page.device_backend.setCurrentIndex(2)  # LDPlayer 9
    assert page.emu_form.isRowVisible(page.ld_dir) and not page.emu_form.isRowVisible(page.bs_dir)
    s = page.collect()
    assert (s.device_backend, s.emulator) == ("adb", "ldplayer")
    page.device_backend.setCurrentIndex(1)  # BlueStacks 5
    assert page.emu_form.isRowVisible(page.bs_dir) and not page.emu_form.isRowVisible(page.ld_dir)
    assert page.collect().emulator == "bluestacks"
    page.device_backend.setCurrentIndex(0)
    assert page.collect().device_backend == "mock"


@pytest.fixture
def qapp():
    pytest.importorskip("PySide6.QtWidgets")
    from PySide6.QtWidgets import QApplication
    return QApplication.instance() or QApplication([])
