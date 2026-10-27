"""ADBController contra un ejecutable adb falso: valida argumentos, parseo y seguridad."""

import json
import sys
from pathlib import Path

import pytest

from dxagent.android.adb import ADBController, AdbRunner, connect_device
from dxagent.android.device import DeviceDisconnected, UnauthorizedOperation, parse_hierarchy


@pytest.fixture
def fake_adb(tmp_path, monkeypatch):
    log = tmp_path / "adb.log"
    monkeypatch.setenv("FAKE_ADB_LOG", str(log))
    command = [sys.executable, str(Path(__file__).with_name("fake_adb.py"))]

    def calls():
        return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []
    return command, calls


async def test_adb_controller_commands_and_parsing(fake_adb):
    exe, calls = fake_adb
    runner = AdbRunner(exe, timeout=10)
    device = await connect_device(runner, adb_port=5565)
    assert device.serial == "127.0.0.1:5565"
    assert await device.is_responsive()
    assert await device.screen_size() == (1600, 900)  # orientación horizontal
    assert await device.current_package() == "com.diamantesexpress.giftsim"
    assert (await device.screenshot_png()).startswith(b"\x89PNG")
    assert await device.is_package_installed("com.diamantesexpress.giftsim")
    await device.tap(10, 20)
    await device.input_text("123 456")
    await device.keyevent("BACK")
    await device.start_app("com.diamantesexpress.giftsim", ".MainActivity")
    log = calls()
    assert ["-s", "127.0.0.1:5565", "shell", "input", "tap", "10", "20"] in log
    assert ["-s", "127.0.0.1:5565", "shell", "input", "text", "123%s456"] in log
    assert ["-s", "127.0.0.1:5565", "shell", "input", "keyevent", "KEYCODE_BACK"] in log
    assert ["-s", "127.0.0.1:5565", "shell", "am", "start", "-n", "com.diamantesexpress.giftsim/.MainActivity"] in log
    with pytest.raises(UnauthorizedOperation):
        await device.input_text("1; reboot")
    with pytest.raises(UnauthorizedOperation):
        await device.keyevent("POWER")
    with pytest.raises(UnauthorizedOperation):
        await device.start_app("com.x; rm -rf /")
    with pytest.raises(UnauthorizedOperation):
        ADBController(runner, "serial; ls")


async def test_adb_offline_is_disconnected(fake_adb, monkeypatch):
    exe, _ = fake_adb
    monkeypatch.setenv("FAKE_ADB_OFFLINE", "1")
    device = ADBController(AdbRunner(exe, timeout=10), "127.0.0.1:5565")
    assert await device.is_responsive() is False
    with pytest.raises(DeviceDisconnected):
        await device.tap(1, 1)


async def test_hierarchy_parser_from_mock(device):
    await device.start_app("com.diamantesexpress.giftsim")
    nodes = parse_hierarchy(await device.dump_ui())
    btn = next(n for n in nodes if n.short_id == "btn_start")
    assert btn.clickable and btn.text == "Enviar regalo"
    assert parse_hierarchy("basura <no xml") == []


async def test_install_only_the_simulator_apk(fake_adb, tmp_path):
    from dxagent.android.adb import SIM_APK_NAME
    exe, calls = fake_adb
    device = ADBController(AdbRunner(exe, timeout=10), "127.0.0.1:5565")
    apk = tmp_path / SIM_APK_NAME
    apk.write_bytes(b"PK")
    assert await device.install_simulator_apk(apk) is True
    assert ["-s", "127.0.0.1:5565", "install", "-r", str(apk)] in calls()
    other = tmp_path / "otra.apk"
    other.write_bytes(b"PK")
    with pytest.raises(UnauthorizedOperation):
        await device.install_simulator_apk(other)
    with pytest.raises(UnauthorizedOperation):
        await device.install_simulator_apk(tmp_path / "falta" / SIM_APK_NAME)
