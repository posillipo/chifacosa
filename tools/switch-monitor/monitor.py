#!/usr/bin/env python3
"""Pannello web per monitorare la raggiungibilita' (ping) di una lista di switch/IP.

Pensato per girare su un dispositivo dentro la rete locale (Raspberry Pi, NAS, PC
sempre acceso) dato che gli IP privati (192.168.x.x) non sono raggiungibili da
internet. Nessuna dipendenza esterna: solo libreria standard di Python 3.

Uso:
    python3 monitor.py [--config config.json] [--port 8090]

Poi apri http://<ip-del-dispositivo>:8090 nel browser.
"""

from __future__ import annotations

import argparse
import json
import platform
import subprocess
import threading
import time
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from concurrent.futures import ThreadPoolExecutor

state_lock = threading.Lock()
state = {}  # ip -> {name, status, latency_ms, last_check, last_change, since_seconds}


def ping_once(ip: str, timeout_seconds: int) -> float | None:
    """Ritorna la latenza in ms se l'host risponde, altrimenti None."""
    is_windows = platform.system().lower() == "windows"
    if is_windows:
        cmd = ["ping", "-n", "1", "-w", str(timeout_seconds * 1000), ip]
    else:
        cmd = ["ping", "-c", "1", "-W", str(timeout_seconds), ip]

    start = time.monotonic()
    try:
        result = subprocess.run(
            cmd,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=timeout_seconds + 2,
        )
    except (subprocess.TimeoutExpired, OSError):
        return None

    if result.returncode != 0:
        return None
    return round((time.monotonic() - start) * 1000, 1)


def check_target(target: dict, timeout_seconds: int):
    ip = target["ip"]
    name = target.get("name", ip)
    model = target.get("model", "")
    now = datetime.now(timezone.utc)
    try:
        latency = ping_once(ip, timeout_seconds)
        new_status = "up" if latency is not None else "down"
    except Exception as exc:
        print(f"Errore imprevisto pingando {ip}: {exc}")
        latency = None
        new_status = "down"

    with state_lock:
        prev = state.get(ip)
        if prev is None or prev["status"] != new_status:
            last_change = now
        else:
            last_change = prev["last_change"]

        state[ip] = {
            "ip": ip,
            "name": name,
            "model": model,
            "status": new_status,
            "latency_ms": latency,
            "last_check": now,
            "last_change": last_change,
        }


def poll_loop(config: dict, stop_event: threading.Event):
    targets = config["targets"]
    interval = config.get("interval_seconds", 10)
    timeout_seconds = config.get("ping_timeout_seconds", 1)

    with ThreadPoolExecutor(max_workers=max(4, len(targets))) as pool:
        while not stop_event.is_set():
            cycle_start = time.monotonic()
            try:
                list(pool.map(lambda t: check_target(t, timeout_seconds), targets))
            except Exception as exc:
                print(f"Errore imprevisto nel ciclo di ping: {exc}")
            elapsed = time.monotonic() - cycle_start
            stop_event.wait(max(0.0, interval - elapsed))


def format_duration(delta_seconds: float) -> str:
    delta_seconds = int(delta_seconds)
    if delta_seconds < 60:
        return f"{delta_seconds}s"
    minutes, seconds = divmod(delta_seconds, 60)
    if minutes < 60:
        return f"{minutes}m {seconds}s"
    hours, minutes = divmod(minutes, 60)
    if hours < 24:
        return f"{hours}h {minutes}m"
    days, hours = divmod(hours, 24)
    return f"{days}g {hours}h"


def snapshot_json() -> str:
    now = datetime.now(timezone.utc)
    with state_lock:
        items = []
        for entry in state.values():
            since = (now - entry["last_change"]).total_seconds()
            items.append({
                "ip": entry["ip"],
                "name": entry["name"],
                "model": entry["model"],
                "status": entry["status"],
                "latency_ms": entry["latency_ms"],
                "last_check": entry["last_check"].isoformat(),
                "since_seconds": since,
                "since_human": format_duration(since),
            })
    items.sort(key=lambda x: tuple(int(p) for p in x["ip"].split(".")))
    up = sum(1 for i in items if i["status"] == "up")
    return json.dumps({
        "generated_at": now.isoformat(),
        "up_count": up,
        "total_count": len(items),
        "targets": items,
    })


PAGE_HTML = """<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monitor switch di rete</title>
<style>
  :root { color-scheme: light dark; }
  body {
    font-family: -apple-system, Segoe UI, Roboto, sans-serif;
    margin: 0; padding: 1.5rem; background: #f4f5f7; color: #1a1a1a;
  }
  @media (prefers-color-scheme: dark) {
    body { background: #14161a; color: #eaeaea; }
    .card { background: #1e2126 !important; border-color: #2c3038 !important; }
    .muted { color: #9aa0a8 !important; }
  }
  h1 { font-size: 1.3rem; margin: 0 0 0.25rem; }
  .summary { margin-bottom: 1rem; font-size: 0.95rem; }
  .muted { color: #666; }
  .grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 0.75rem;
  }
  .card {
    border: 1px solid #e0e0e0; border-radius: 10px; padding: 0.9rem 1rem;
    background: #fff;
  }
  .row { display: flex; justify-content: space-between; align-items: center; }
  .name { font-weight: 600; }
  .ip { font-size: 0.8rem; color: #888; }
  .badge {
    display: inline-block; padding: 0.2rem 0.6rem; border-radius: 999px;
    font-size: 0.78rem; font-weight: 600; color: #fff;
  }
  .up { background: #1e9e5a; }
  .down { background: #d64545; }
  .detail { font-size: 0.8rem; margin-top: 0.4rem; }
  #updated { font-size: 0.75rem; }
</style>
</head>
<body>
  <h1>Monitor switch di rete</h1>
  <div class="summary" id="summary">Caricamento...</div>
  <div class="grid" id="grid"></div>
  <p class="muted" id="updated"></p>

<script>
async function refresh() {
  try {
    const res = await fetch('/api/status', { cache: 'no-store' });
    const data = await res.json();
    document.getElementById('summary').textContent =
      `${data.up_count} / ${data.total_count} switch raggiungibili`;
    const grid = document.getElementById('grid');
    grid.innerHTML = '';
    for (const t of data.targets) {
      const card = document.createElement('div');
      card.className = 'card';
      const statusLabel = t.status === 'up' ? 'ONLINE' : 'OFFLINE';
      card.innerHTML = `
        <div class="row">
          <div>
            <div class="name">${t.name}</div>
            <div class="ip">${t.ip}${t.model ? ' &middot; ' + t.model : ''}</div>
          </div>
          <span class="badge ${t.status}">${statusLabel}</span>
        </div>
        <div class="detail muted">
          ${t.status === 'up'
            ? `${t.latency_ms} ms &middot; stabile da ${t.since_human}`
            : `offline da ${t.since_human}`}
        </div>`;
      grid.appendChild(card);
    }
    document.getElementById('updated').textContent =
      'Ultimo aggiornamento: ' + new Date(data.generated_at).toLocaleTimeString('it-IT');
  } catch (e) {
    document.getElementById('summary').textContent = 'Errore nel contattare il server di monitoraggio';
  }
}
refresh();
setInterval(refresh, 5000);
</script>
</body>
</html>
"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        pass  # silenzia i log di accesso

    def do_GET(self):
        if self.path == "/" or self.path == "/index.html":
            body = PAGE_HTML.encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif self.path == "/api/status":
            body = snapshot_json().encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_response(404)
            self.end_headers()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", default="config.json", help="Percorso del file di configurazione")
    parser.add_argument("--port", type=int, default=8090, help="Porta HTTP del pannello")
    args = parser.parse_args()

    with open(args.config, "r", encoding="utf-8") as f:
        config = json.load(f)

    stop_event = threading.Event()
    poll_thread = threading.Thread(target=poll_loop, args=(config, stop_event), daemon=True)
    poll_thread.start()

    server = ThreadingHTTPServer(("0.0.0.0", args.port), Handler)
    print(f"Monitor switch in ascolto su http://0.0.0.0:{args.port}  (Ctrl+C per fermare)")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        stop_event.set()
        server.shutdown()


if __name__ == "__main__":
    main()
