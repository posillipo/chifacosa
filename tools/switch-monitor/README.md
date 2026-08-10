# Monitor switch di rete

Pannellino web che pinga periodicamente una lista di switch/IP e mostra chi è
online e chi è offline, con da quanto tempo. Utile dopo un blackout per capire
subito quali switch non si sono riallineati.

Nessuna dipendenza esterna: solo Python 3 (standard library). Va fatto girare
su un dispositivo **dentro la rete locale** (Raspberry Pi, NAS, mini PC sempre
acceso) perché gli IP 192.168.x.x non sono raggiungibili da internet.

## Avvio rapido

```bash
cd tools/switch-monitor
python3 monitor.py
```

Poi apri `http://<ip-del-dispositivo>:8090` da qualsiasi browser sulla stessa
rete (anche dal telefono).

## Configurazione

Modifica `config.json`:

```json
{
  "interval_seconds": 10,
  "ping_timeout_seconds": 1,
  "targets": [
    { "ip": "192.168.8.62", "name": "Sala CED", "model": "Netgear ProSafe GS728TPP" }
  ]
}
```

- `interval_seconds`: ogni quanto ripetere il giro di ping (default 10s).
- `ping_timeout_seconds`: timeout del singolo ping (default 1s).
- `targets`: `name` è la location, `model` è il modello dell'apparato (mostrato
  sotto l'IP nel pannello). I 14 dispositivi sono già precompilati con
  location e modello reali (13 switch/firewall privati + il firewall con IP
  pubblico).

## Farlo partire da solo al boot (systemd)

Su Raspberry Pi / Linux con systemd:

```bash
sudo mkdir -p /opt/switch-monitor
sudo cp monitor.py config.json /opt/switch-monitor/
sudo cp switch-monitor.service /etc/systemd/system/
# se l'utente non è "pi", modifica User= dentro il file .service
sudo systemctl daemon-reload
sudo systemctl enable --now switch-monitor
sudo systemctl status switch-monitor
```

Da quel momento il pannello riparte automaticamente ad ogni riavvio/blackout,
appena la rete torna su.

## Come funziona

- Un thread in background pinga tutti gli IP in parallelo ogni `interval_seconds`.
- Per ogni IP tiene in memoria: stato attuale (online/offline), latenza
  dell'ultimo ping, e da quanto tempo è in quello stato.
- La pagina web (`/`) interroga `/api/status` (JSON) ogni 5 secondi e
  aggiorna le card senza ricaricare la pagina.
- Non c'è persistenza su disco: se riavvii lo script, lo storico "da quanto
  tempo" riparte da zero (ma lo stato attuale online/offline viene rilevato
  di nuovo al primo giro di ping).
