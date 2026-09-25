// Server MCP remoto (Streamable HTTP, stateless) per collegare Claude (claude.ai/Claude Desktop)
// a uno o più profili CHIFACOSA, riusando l'API pubblica già esistente
// (/api/v1/social-posts/*, vedi app/src/api_helpers.php nel repo principale) invece di parlare
// direttamente col database — stesso principio di qualunque altro client dell'API, solo che
// questo lo fa per conto di Claude tramite gli strumenti MCP.
//
// Autenticazione a due livelli, volutamente diversi:
// 1) MCP_ACCESS_TOKEN — protegge sia gli strumenti MCP sia gli endpoint di amministrazione
//    (/admin/profiles) da chiunque altro su internet. È il token che l'utente incolla nella
//    configurazione del connector su claude.ai/Claude Desktop, e lo stesso che usa da terminale
//    per registrare un nuovo profilo.
// 2) Un token CHIFACOSA per ciascun profilo, salvato in un file su un volume Docker persistente
//    (vedi PROFILES_FILE sotto), MAI nelle variabili d'ambiente del container e MAI comunicato
//    a claude.ai — solo così aggiungere un nuovo profilo non richiede più un deploy: basta una
//    chiamata a /admin/profiles fatta direttamente dall'utente (mai attraverso la chat/Claude,
//    altrimenti il token vero finirebbe nella conversazione).
//
// Multi-profilo: chi gestisce più profili su CHIFACOSA registra un token per ciascuno (vedi
// sezione admin più sotto) e sceglie ogni volta su quale agire passando il parametro "profile"
// a ogni strumento.

import express from 'express';
import fs from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { z } from 'zod';

const PORT = process.env.PORT || 3000;
const MCP_ACCESS_TOKEN = process.env.MCP_ACCESS_TOKEN;
const CHIFACOSA_BASE_URL = (process.env.CHIFACOSA_BASE_URL || 'https://www.chifacosa.it/api/v1/social-posts').replace(/\/$/, '');
// Radice /api/v1, usata per costruire il percorso di QUALSIASI risorsa (social-posts, blog-posts,
// future) — CHIFACOSA_BASE_URL storicamente puntava già a /api/v1/social-posts (da prima che
// esistesse un secondo tipo di risorsa): togliendo solo quel suffisso otteniamo la radice giusta
// senza dover rinominare la variabile d'ambiente né chiedere un aggiornamento su Portainer a chi
// l'ha già configurata (con o senza override esplicito, il risultato è lo stesso).
const CHIFACOSA_API_ROOT = CHIFACOSA_BASE_URL.replace(/\/social-posts$/, '');
const DATA_DIR = process.env.DATA_DIR || '/data';
const PROFILES_FILE = path.join(DATA_DIR, 'profiles.json');

if (!MCP_ACCESS_TOKEN) {
    console.error('Manca la variabile d\'ambiente MCP_ACCESS_TOKEN — il server non parte senza.');
    process.exit(1);
}

// Profili salvati su file (persistente sul volume Docker, sopravvive ai redeploy) — questa è
// l'unica fonte di verità per i profili "nuovo stile", gestibili a caldo via /admin/profiles.
function loadFileProfiles() {
    try {
        if (fs.existsSync(PROFILES_FILE)) {
            return JSON.parse(fs.readFileSync(PROFILES_FILE, 'utf8'));
        }
    } catch (err) {
        console.error('Errore leggendo profiles.json, ignorato:', err.message);
    }
    return {};
}

function saveFileProfiles(profiles) {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(PROFILES_FILE, JSON.stringify(profiles, null, 2));
}

// Compatibilità con la vecchia configurazione a variabili d'ambiente (CHIFACOSA_PROFILE_N_NAME/
// _TOKEN o il singolo CHIFACOSA_API_TOKEN) — chi li aveva già impostati non li perde, ma da qui
// in avanti il modo per AGGIUNGERE profili è /admin/profiles, non nuove variabili nel container.
function loadEnvProfiles() {
    const profiles = {};
    for (const key of Object.keys(process.env)) {
        const m = key.match(/^CHIFACOSA_PROFILE_(\d+)_NAME$/);
        if (!m) continue;
        const name = (process.env[key] || '').trim();
        const token = (process.env[`CHIFACOSA_PROFILE_${m[1]}_TOKEN`] || '').trim();
        if (name && token) profiles[name] = token;
    }
    if (Object.keys(profiles).length === 0 && process.env.CHIFACOSA_API_TOKEN) {
        profiles['principale'] = process.env.CHIFACOSA_API_TOKEN.trim();
    }
    return profiles;
}

// Letta ad ogni richiesta (non una volta sola all'avvio): un profilo registrato via
// /admin/profiles deve essere utilizzabile subito, senza riavviare il container.
function getProfiles() {
    return { ...loadEnvProfiles(), ...loadFileProfiles() };
}

function requireAdminAuth(req, res) {
    const raw = req.headers['authorization'] || '';
    const m = raw.trim().match(/^Bearer\s*(.+)$/i);
    const token = (m ? m[1] : raw).trim();
    if (token !== MCP_ACCESS_TOKEN) {
        res.status(401).json({ error: 'Unauthorized' });
        return false;
    }
    return true;
}

// Wrapper unico per tutte le chiamate all'API CHIFACOSA: stessa gestione errori/JSON/selezione
// del token in base al profilo per ogni strumento, invece di ripeterla 5 volte.
async function chifacosaApi(profileName, path, { method = 'GET', body } = {}) {
    const profiles = getProfiles();
    const profileNames = Object.keys(profiles);
    const token = profiles[profileName];
    if (!token) {
        return {
            httpStatus: 400,
            data: { success: false, error: `Profilo "${profileName}" non configurato su questo server. Profili disponibili: ${profileNames.join(', ') || '(nessuno — registrane uno con POST /admin/profiles)'}.` },
        };
    }
    const res = await fetch(CHIFACOSA_API_ROOT + path, {
        method,
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = { success: false, error: `Risposta non-JSON dall'API CHIFACOSA (HTTP ${res.status}): ${text.slice(0, 300)}` };
    }
    return { httpStatus: res.status, data };
}

// Restituisce sempre il JSON completo (successo o errore) come testo: lasciamo che sia Claude a
// leggerlo e a decidere come spiegarlo all'utente, invece di nascondere dettagli utili qui.
function toolResult(apiResult) {
    return {
        content: [{ type: 'text', text: JSON.stringify(apiResult.data, null, 2) }],
        isError: apiResult.data?.success === false,
    };
}

// Campi comuni a create/update — stessa forma esposta dall'API REST, vedi
// app/src/api_helpers.php::apiValidateSocialPostPayload().
const postFieldsSchema = {
    title: z.string().max(100).optional().describe('Titolo del post (max 100 caratteri)'),
    description: z.string().optional().describe('Testo/corpo del post'),
    image_url: z.string().url().optional().describe('URL pubblico di un\'immagine da scaricare e allegare al post'),
    publication_date: z.string().optional().describe('Data/ora di pubblicazione in ISO 8601 con fuso orario esplicito, es. 2026-09-24T08:00:00+02:00'),
    hashtags: z.string().max(300).optional().describe('Hashtag da includere nel post'),
    call_to_action: z.string().max(200).optional().describe('Call to action, es. "Contattaci: link in bio"'),
    redirect_link: z.string().url().optional().describe('Link di reindirizzamento fisso per QUESTO post soltanto: chi apre la pagina del post viene mandato subito a questo URL, per sempre, indipendentemente da altri post o impostazioni del profilo — a differenza del "Link personalizzato per il feed" del profilo (non gestito da questo strumento), che è invece un interruttore globale con soglia temporale, valido per tutti i contenuti pubblicati da quando viene attivato'),
    status: z.enum(['draft', 'scheduled', 'published']).optional().describe('draft = non pubblico, scheduled = richiede publication_date futura, published = subito visibile'),
};

// Campi comuni a create/update per un articolo blog — stessa forma esposta dall'API REST, vedi
// app/src/api_helpers.php::apiValidateBlogPostPayload().
const blogPostFieldsSchema = {
    title: z.string().max(200).optional().describe('Titolo dell\'articolo (max 200 caratteri)'),
    content: z.string().optional().describe('Contenuto/corpo dell\'articolo'),
    tags: z.string().max(300).optional().describe('Tag separati da virgola, es. "concerti, novità, napoli"'),
    categories: z.array(z.string()).optional().describe('Nomi delle categorie da assegnare — una categoria non ancora esistente viene creata automaticamente'),
    image_url: z.string().url().optional().describe('URL pubblico di un\'immagine da scaricare e usare come copertina'),
    publication_date: z.string().optional().describe('Data/ora di pubblicazione in ISO 8601 con fuso orario esplicito, es. 2026-09-24T08:00:00+02:00 — se futura l\'articolo resta programmato fino ad allora, se omessa si pubblica subito'),
};

// Campi comuni a create/update per un evento — stessa forma esposta dall'API REST, vedi
// app/src/api_helpers.php::apiValidateEventPayload(). A differenza di Timeline/Blog un evento non
// ha un concetto di programmazione/bozza: è sempre visibile subito, event_date è solo quando si
// terrà, non quando pubblicarlo.
const eventFieldsSchema = {
    title: z.string().max(150).optional().describe('Nome dell\'evento (max 150 caratteri)'),
    venue: z.string().max(150).optional().describe('Nome del locale/luogo (opzionale)'),
    city: z.string().max(100).optional().describe('Città (opzionale)'),
    event_date: z.string().optional().describe('Data/ora dell\'evento in ISO 8601 con fuso orario esplicito, es. 2026-09-24T21:00:00+02:00'),
    ticket_url: z.string().url().optional().describe('Link biglietti (opzionale)'),
    description: z.string().optional().describe('Descrizione dell\'evento (opzionale)'),
    is_perpetual: z.boolean().optional().describe('true = evento perpetuo, nessuna data di fine, resta sempre visibile in "Prossimi eventi"'),
    recurrence: z.enum(['none', 'weekdays', 'weekend']).optional().describe('none = data singola, weekdays = dal lunedì al venerdì, weekend = solo weekend'),
    accepts_reservations: z.boolean().optional().describe('true = accetta prenotazioni per questo evento'),
    image_url: z.string().url().optional().describe('URL pubblico di un\'immagine da scaricare e usare come copertina'),
};

function buildMcpServer() {
    const server = new McpServer({ name: 'chifacosa-social-posts', version: '1.5.0' });

    // Ricalcolati ad ogni richiesta (siamo in modalità stateless, un buildMcpServer() per
    // richiesta — vedi più sotto): un profilo appena registrato via /admin/profiles deve
    // comparire subito nell'enum, senza dover riavviare il container.
    const profileNames = Object.keys(getProfiles());
    // Se non c'è ancora nessun profilo registrato, z.enum([]) romperebbe la definizione dello
    // schema (richiede almeno un valore) — in quel caso si accetta una stringa qualsiasi, tanto
    // chifacosaApi() la rifiuta comunque con un errore chiaro che spiega come registrarne uno.
    const profileSchema = profileNames.length > 0 ? z.enum(profileNames) : z.string();
    const profileField = {
        profile: profileSchema.describe(`Su quale profilo agire. Profili configurati: ${profileNames.join(', ') || '(nessuno ancora — vedi POST /admin/profiles)'}`),
    };

    server.registerTool('list_chifacosa_profiles', {
        title: 'Elenca i profili CHIFACOSA disponibili',
        description: 'Elenca i nomi dei profili CHIFACOSA configurati su questo server MCP, da usare come valore del parametro "profile" negli altri strumenti.',
        inputSchema: {},
    }, async () => ({
        content: [{ type: 'text', text: JSON.stringify({ success: true, profiles: profileNames }, null, 2) }],
        isError: false,
    }));

    server.registerTool('create_social_post', {
        title: 'Crea un post sulla Timeline di un profilo CHIFACOSA',
        description: 'Crea un nuovo post (subito pubblicato, programmato per una data futura, o come bozza) sulla Timeline pubblica del profilo CHIFACOSA scelto.',
        inputSchema: { ...profileField, ...postFieldsSchema },
    }, async ({ profile, ...fields }) => toolResult(await chifacosaApi(profile, '/social-posts/create', { method: 'POST', body: fields })));

    server.registerTool('list_social_posts', {
        title: 'Elenca i post di un profilo',
        description: 'Elenca i post del profilo scelto, con filtri opzionali per status e intervallo di date, e paginazione.',
        inputSchema: {
            ...profileField,
            status: z.enum(['draft', 'scheduled', 'published']).optional(),
            from: z.string().optional().describe('Data minima (YYYY-MM-DD)'),
            to: z.string().optional().describe('Data massima (YYYY-MM-DD)'),
            page: z.number().int().min(1).optional(),
            per_page: z.number().int().min(1).max(100).optional(),
        },
    }, async ({ profile, ...args }) => {
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(args || {})) {
            if (v !== undefined && v !== null) params.set(k, String(v));
        }
        const qs = params.toString();
        return toolResult(await chifacosaApi(profile, '/social-posts/list' + (qs ? `?${qs}` : '')));
    });

    server.registerTool('get_social_post', {
        title: 'Dettaglio di un post',
        description: 'Recupera i dettagli di un singolo post di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID del post') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/social-posts/${id}`)));

    server.registerTool('update_social_post', {
        title: 'Modifica un post',
        description: 'Modifica un post esistente di un profilo — funziona solo se il post è ancora "draft" o "scheduled" (non ancora pubblicato). Tutti i campi oltre a profile/id sono opzionali: solo quelli forniti vengono aggiornati.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID del post da modificare'), ...postFieldsSchema },
    }, async ({ profile, id, ...fields }) => toolResult(await chifacosaApi(profile, `/social-posts/${id}`, { method: 'PUT', body: fields })));

    server.registerTool('delete_social_post', {
        title: 'Elimina un post',
        description: 'Elimina definitivamente un post di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID del post da eliminare') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/social-posts/${id}`, { method: 'DELETE' })));

    server.registerTool('create_blog_post', {
        title: 'Crea un articolo sul Blog di un profilo CHIFACOSA',
        description: 'Crea un nuovo articolo (subito pubblicato, o programmato per una data futura) sul Blog del profilo CHIFACOSA scelto. Richiede title e content.',
        inputSchema: { ...profileField, ...blogPostFieldsSchema },
    }, async ({ profile, ...fields }) => toolResult(await chifacosaApi(profile, '/blog-posts/create', { method: 'POST', body: fields })));

    server.registerTool('list_blog_posts', {
        title: 'Elenca gli articoli del blog di un profilo',
        description: 'Elenca gli articoli del Blog del profilo scelto, con filtri opzionali per status e intervallo di date, e paginazione.',
        inputSchema: {
            ...profileField,
            status: z.enum(['scheduled', 'published']).optional(),
            from: z.string().optional().describe('Data minima (YYYY-MM-DD)'),
            to: z.string().optional().describe('Data massima (YYYY-MM-DD)'),
            page: z.number().int().min(1).optional(),
            per_page: z.number().int().min(1).max(100).optional(),
        },
    }, async ({ profile, ...args }) => {
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(args || {})) {
            if (v !== undefined && v !== null) params.set(k, String(v));
        }
        const qs = params.toString();
        return toolResult(await chifacosaApi(profile, '/blog-posts/list' + (qs ? `?${qs}` : '')));
    });

    server.registerTool('get_blog_post', {
        title: 'Dettaglio di un articolo del blog',
        description: 'Recupera i dettagli di un singolo articolo del blog di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID dell\'articolo') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/blog-posts/${id}`)));

    server.registerTool('update_blog_post', {
        title: 'Modifica un articolo del blog',
        description: 'Modifica un articolo del blog esistente di un profilo, anche già pubblicato. Tutti i campi oltre a profile/id sono opzionali: solo quelli forniti vengono aggiornati.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID dell\'articolo da modificare'), ...blogPostFieldsSchema },
    }, async ({ profile, id, ...fields }) => toolResult(await chifacosaApi(profile, `/blog-posts/${id}`, { method: 'PUT', body: fields })));

    server.registerTool('list_cinema_films', {
        title: 'Elenca i film Cinema di un profilo',
        description: 'Elenca i film sincronizzati dal feed JSON Cinema del profilo scelto (Dashboard -> Cinema), con la data in cui sono stati aggiunti — utile per individuare le nuove uscite e creare un post (create_social_post / create_blog_post) per ciascuna.',
        inputSchema: {
            ...profileField,
            since: z.string().optional().describe('Mostra solo i film aggiunti da questa data in poi (YYYY-MM-DD) — utile per trovare solo le nuove uscite dall\'ultimo controllo'),
            sync: z.boolean().optional().describe('Se true, forza una sincronizzazione col feed JSON esterno prima di leggere (utile se sul server non è già configurato un cron periodico)'),
        },
    }, async ({ profile, ...args }) => {
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(args || {})) {
            if (v !== undefined && v !== null) params.set(k, String(v));
        }
        const qs = params.toString();
        return toolResult(await chifacosaApi(profile, '/cinema-films/list' + (qs ? `?${qs}` : '')));
    });

    server.registerTool('delete_blog_post', {
        title: 'Elimina un articolo del blog',
        description: 'Elimina definitivamente un articolo del blog di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID dell\'articolo da eliminare') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/blog-posts/${id}`, { method: 'DELETE' })));

    server.registerTool('create_event', {
        title: 'Crea un evento su un profilo CHIFACOSA',
        description: 'Crea un nuovo evento (sempre visibile subito, nessun concetto di bozza/programmazione) sul profilo CHIFACOSA scelto. Richiede title e event_date.',
        inputSchema: { ...profileField, ...eventFieldsSchema },
    }, async ({ profile, ...fields }) => toolResult(await chifacosaApi(profile, '/events/create', { method: 'POST', body: fields })));

    server.registerTool('list_events', {
        title: 'Elenca gli eventi di un profilo',
        description: 'Elenca gli eventi del profilo scelto, con filtro opzionale per intervallo di date (su event_date, quando si terranno) e paginazione.',
        inputSchema: {
            ...profileField,
            from: z.string().optional().describe('Data minima (YYYY-MM-DD)'),
            to: z.string().optional().describe('Data massima (YYYY-MM-DD)'),
            page: z.number().int().min(1).optional(),
            per_page: z.number().int().min(1).max(100).optional(),
        },
    }, async ({ profile, ...args }) => {
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(args || {})) {
            if (v !== undefined && v !== null) params.set(k, String(v));
        }
        const qs = params.toString();
        return toolResult(await chifacosaApi(profile, '/events/list' + (qs ? `?${qs}` : '')));
    });

    server.registerTool('get_event', {
        title: 'Dettaglio di un evento',
        description: 'Recupera i dettagli di un singolo evento di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID dell\'evento') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/events/${id}`)));

    server.registerTool('update_event', {
        title: 'Modifica un evento',
        description: 'Modifica un evento esistente di un profilo. Tutti i campi oltre a profile/id sono opzionali: solo quelli forniti vengono aggiornati.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID dell\'evento da modificare'), ...eventFieldsSchema },
    }, async ({ profile, id, ...fields }) => toolResult(await chifacosaApi(profile, `/events/${id}`, { method: 'PUT', body: fields })));

    server.registerTool('delete_event', {
        title: 'Elimina un evento',
        description: 'Elimina definitivamente un evento di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID dell\'evento da eliminare') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/events/${id}`, { method: 'DELETE' })));

    return server;
}

const app = express();
app.use(express.json());

// Log minimale di ogni richiesta in arrivo — mai il valore dell'header Authorization o del
// body, solo se un Authorization è presente o no: serve a capire da fuori se una richiesta
// arriva davvero al container, senza esporre segreti nei log.
app.use((req, res, next) => {
    const hasAuth = req.headers['authorization'] ? 'con Authorization' : 'senza Authorization';
    console.log(`[req] ${req.method} ${req.path} — ${hasAuth} — User-Agent: ${req.headers['user-agent'] || '(nessuno)'}`);
    next();
});

// Gestione profili a caldo, senza redeploy: protetta dallo stesso MCP_ACCESS_TOKEN degli
// strumenti — vanno chiamate direttamente da un terminale, MAI chiedendo a Claude di farlo,
// altrimenti il token CHIFACOSA vero finirebbe scritto nella conversazione.
app.get('/admin/profiles', (req, res) => {
    if (!requireAdminAuth(req, res)) return;
    res.json({ success: true, profiles: Object.keys(getProfiles()) });
});

app.post('/admin/profiles', (req, res) => {
    if (!requireAdminAuth(req, res)) return;
    const name = (req.body?.name || '').trim();
    const token = (req.body?.token || '').trim();
    if (!name || !token) {
        res.status(400).json({ success: false, error: 'Servono sia "name" (nome a scelta per il profilo) sia "token" (il token CHIFACOSA di quel profilo, da Dashboard -> API).' });
        return;
    }
    const profiles = loadFileProfiles();
    profiles[name] = token;
    saveFileProfiles(profiles);
    console.log(`[admin] profilo "${name}" salvato (o aggiornato)`);
    res.json({ success: true, profiles: Object.keys(getProfiles()) });
});

app.delete('/admin/profiles/:name', (req, res) => {
    if (!requireAdminAuth(req, res)) return;
    const profiles = loadFileProfiles();
    const existed = req.params.name in profiles;
    delete profiles[req.params.name];
    saveFileProfiles(profiles);
    console.log(`[admin] profilo "${req.params.name}" rimosso (${existed ? 'esisteva' : 'non esisteva nel file — nessun effetto'})`);
    res.json({ success: true, profiles: Object.keys(getProfiles()) });
});

app.post('/mcp', async (req, res) => {
    // Tollerante sul formato: accetta sia "Bearer <token>" (maiuscole/minuscole indifferenti,
    // spazi extra ignorati) sia il token nudo senza prefisso — alcuni client compilano l'header
    // in modi leggermente diversi, meglio non dipendere da un confronto esatto sull'intera stringa.
    const rawAuthHeader = req.headers['authorization'] || '';
    // \s* (non \s+): claude.ai a quanto pare compone l'header come "Bearer" + valore, SENZA
    // spazio in mezzo — visto nei log come token ricevuto più lungo del previsto esattamente di
    // 6 caratteri ("Bearer" letterale attaccato davanti).
    const bearerMatch = rawAuthHeader.trim().match(/^Bearer\s*(.+)$/i);
    const providedToken = (bearerMatch ? bearerMatch[1] : rawAuthHeader).trim();
    if (providedToken !== MCP_ACCESS_TOKEN) {
        console.log(`[mcp] token non valido — lunghezza ricevuta: ${providedToken.length} (attesa: ${MCP_ACCESS_TOKEN.length}), prefisso "Bearer" rilevato: ${!!bearerMatch} — richiesta rifiutata (401)`);
        res.status(401).json({ error: 'Unauthorized' });
        return;
    }

    // Modalità stateless: un McpServer + una transport nuovi per ogni richiesta, niente sessione
    // da mantenere in memoria — adatto a un uso personale a bassissimo traffico come questo,
    // evita la complessità (e i bug) della gestione dello stato tra richieste concorrenti.
    try {
        const server = buildMcpServer();
        const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
        res.on('close', () => {
            transport.close();
            server.close();
        });
        await server.connect(transport);
        await transport.handleRequest(req, res, req.body);
    } catch (err) {
        console.error('Errore nella gestione della richiesta MCP:', err);
        if (!res.headersSent) {
            res.status(500).json({ jsonrpc: '2.0', error: { code: -32603, message: 'Internal server error' }, id: null });
        }
    }
});

// Il trasporto stateless non supporta GET/DELETE su /mcp (niente sessioni da interrogare o
// chiudere) — risposta esplicita invece di un 404 generico, più chiara per chi debugga.
app.get('/mcp', (req, res) => res.status(405).json({ error: 'Method not allowed (stateless server, solo POST).' }));
app.delete('/mcp', (req, res) => res.status(405).json({ error: 'Method not allowed (stateless server, solo POST).' }));

app.get('/health', (req, res) => res.json({ ok: true }));

// Qualunque altro percorso (es. i "well-known" che alcuni client provano a scoprire da soli
// prima di connettersi, come /.well-known/oauth-protected-resource) finisce qui: lo logghiamo
// comunque, così se claude.ai prova un URL diverso da /mcp lo vediamo nei log invece di restare
// al buio con un 404 muto.
app.use((req, res) => {
    console.log(`[404] nessuna rotta per ${req.method} ${req.path}`);
    res.status(404).json({ error: 'Not found' });
});

app.listen(PORT, () => {
    const startupProfiles = Object.keys(getProfiles());
    console.log(`chifacosa-mcp-server in ascolto sulla porta ${PORT} — target: ${CHIFACOSA_API_ROOT} — profili configurati: ${startupProfiles.join(', ') || '(nessuno ancora — registrali con POST /admin/profiles)'}`);
});
