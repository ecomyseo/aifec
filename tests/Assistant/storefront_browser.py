"""Prueba del chat servido por PHP en un sitio SQLite temporal. Ejecutar con navegador_ia.py python."""
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[2]
php = os.environ.get("AIFEC_TEST_PHP") or shutil.which("php")
evidence = Path(os.environ["AIFEC_UI_EVIDENCE"])
evidence.mkdir(parents=True, exist_ok=True)


def run(cmd, cwd):
    result = subprocess.run(cmd, cwd=cwd, capture_output=True, timeout=100)
    if result.returncode:
        raise RuntimeError(f"{Path(cmd[0]).name} devolvió {result.returncode}: {result.stderr.decode(errors='replace')[-500:]}")


with tempfile.TemporaryDirectory(prefix="aifec-ui-") as temp:
    site = Path(temp)
    files = subprocess.check_output(["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"], cwd=root)
    for relative in files.decode("utf-8").split("\0"):
        if not relative or relative.startswith(("tests/", ".github/", "docs/", "manuales/")):
            continue
        path = root / relative
        if path.is_file():
            dest = site / relative
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(path, dest)
    with socket.socket() as port_source:
        port_source.bind(("127.0.0.1", 0))
        port = port_source.getsockname()[1]
    host = f"127.0.0.1:{port}"
    base = f"http://{host}"
    run([php, "aifec", "site:create", "main", "--url=" + base, "--lang=es", "--name=Tienda de prueba", "--email=pruebas@ejemplo.test"], site)
    run([php, "aifec", "assets:publish"], site)
    log = (site / "server.log").open("wb")
    server = subprocess.Popen([php, "-S", host, "-t", "public", "public/index.php"], cwd=site, stdout=log, stderr=log)
    try:
        for attempt in range(50):
            try:
                with urllib.request.urlopen(base + "/asistente/inicio", timeout=4) as response:
                    assert response.status == 200
                    starters = json.load(response)
                    print("Sugerencias HTTP:", starters["suggestions"], flush=True)
                break
            except OSError:
                if attempt == 49:
                    raise
                time.sleep(.1)
        with sync_playwright() as api:
            browser = api.chromium.launch(headless=True)
            context = browser.new_context(viewport={"width": 390, "height": 844}, locale="es-ES")
            page = context.new_page()
            errors = []
            page.on("pageerror", lambda error: errors.append(str(error)))
            page.set_default_timeout(12000)
            page.on("response", lambda r: print("HTTP", r.status, r.url.split("/")[-1], flush=True) if "/asistente/" in r.url else None)
            page.goto(base)
            panel = page.locator("#af-assistant-panel")
            opener = page.locator("[data-af-assistant-open]")
            assert not panel.is_visible()
            opener.click()
            field = page.locator("#af-assistant-input")
            field.wait_for()
            chips = page.locator("[data-af-assistant-chips] [data-af-assistant-chip]")
            try:
                chips.first.wait_for()
            except Exception:
                print("Estado del panel:", panel.inner_text()[:650], "Errores JS:", errors, flush=True)
                raise
            page.get_by_role("button", name=starters["suggestions"][-2], exact=True).click()
            page.locator(".af-assistant-msg.is-user").wait_for()
            page.locator(".af-assistant-msg.is-bot").nth(1).wait_for()
            assert not page.evaluate("document.documentElement.scrollWidth > innerWidth")
            assert field.evaluate("x => x.getBoundingClientRect().right <= innerWidth")
            assert page.locator("main").evaluate("node => node.closest('[inert]') !== null")
            field.focus()
            for _ in range(13):
                page.keyboard.press("Tab")
                assert panel.evaluate("node => node.contains(document.activeElement)")
            field.focus()
            assert page.evaluate("document.documentElement.classList.contains('af-assistant-lock')")
            page.screenshot(path=str(evidence / "chat-movil.png"))
            lighthouse_source = os.environ.get("AIFEC_LIGHTHOUSE_GATHER")
            if lighthouse_source:
                shutil.copyfile(lighthouse_source, site / "public" / "audit-lighthouse.js")
                page.add_script_tag(url=base + "/audit-lighthouse.js")
                artifact = page.evaluate("() => window.aifecLighthouseGather()")
                (evidence / "lighthouse-accessibility-artifact.json").write_text(json.dumps(artifact, ensure_ascii=False), encoding="utf-8")
            axe_source = os.environ.get("AIFEC_AXE_JS")
            if axe_source:
                audit_path = site / "public" / "audit-wcag.js"
                shutil.copyfile(axe_source, audit_path)
                page.add_script_tag(url=base + "/audit-wcag.js")
                audit = page.evaluate("""async () => {
                    const audit = await window.axe.run(document, {
                        runOnly: {type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}
                    });
                    return {violations: audit.violations.map(v => ({id: v.id, impact: v.impact, nodes: v.nodes.map(n => ({target:n.target,summary:n.failureSummary}))})),
                        incomplete: audit.incomplete.map(v => v.id), passed: audit.passes.map(v => v.id)};
                }""")
                (evidence / "accesibilidad-movil.json").write_text(json.dumps(audit, ensure_ascii=False, indent=2), encoding="utf-8")
                assert not audit["violations"], audit["violations"]
            field.fill("Mi correo es ficticio@ejemplo.test")
            field.press("Enter")
            page.locator(".af-assistant-msg.is-bot").nth(2).wait_for()
            page.locator(".af-assistant-msg.is-busy").wait_for(state="detached")
            assert "ficticio@ejemplo.test" not in page.evaluate("sessionStorage.getItem('af-assistant-public-log') || ''")
            page.reload()
            assert not panel.is_visible()
            page.locator("[data-af-assistant-resume]").wait_for()
            page.set_viewport_size({"width": 1280, "height": 820})
            opener.click()
            menu = page.locator(".af-assistant-menu summary")
            menu.click()
            page.locator("[data-af-layout=large]").click()
            assert page.locator("[data-af-assistant]").get_attribute("data-layout") == "large"
            menu.click()
            page.locator("[data-af-layout=side]").click()
            page.reload()
            panel.wait_for()
            assert page.locator("[data-af-assistant]").get_attribute("data-layout") == "side"
            menu.click()
            with context.expect_page() as new_window:
                page.locator("a[data-af-chat-window]").click()
            child = new_window.value
            child.wait_for_url("**/asistente/ventana")
            child.locator("#af-assistant-input").wait_for()
            assert child.evaluate("window.opener === null")
            assert "ficticio@ejemplo.test" not in child.locator("[data-af-assistant-log]").inner_text()
            child.close()
            page.screenshot(path=str(evidence / "chat-escritorio.png"))
            assert not errors, errors
            (evidence / "chat-navegador.json").write_text(json.dumps({"mobile": [390, 844], "desktop": [1280, 820], "php_http": True, "resume": True, "dock": True, "separate_window": True, "private_history": True, "page_errors": errors}, indent=2), encoding="utf-8")
            context.close()
            browser.close()
            print("CHAT_HTTP_VERIFICADO")
    finally:
        server.terminate()
        server.wait(10)
        log.close()
