// Server MCP remoto (Streamable HTTP, stateless) per collegare Claude (claude.ai/Claude Desktop)
// a uno o più profili CHIFACOSA, riusando l'API pubblica già esistente
// (/api/v1/social-posts/*, vedi app/src/api_helpers.php nel repo principale) invece di parlare
// direttamente col database — stesso principio di qualunque altro client dell'API, solo che
// questo lo fa per conto di Claude tramite gli strumenti MCP.
//
// Autenticazione a due livelli, volutamente diversi:
// 1) MCP_ACCESS_TOKEN — protegge QUESTO server da chiunque altro su internet: è il token che
//    l'utente incolla nella configurazione del connector su claude.ai/Claude Desktop.
// 2) Un token CHIFACOSA per ciascun profilo (vedi parseProfiles() sotto) — usati QUI dentro per
//    chiamare l'API per conto dell'utente. Non vengono mai comunicati a claude.ai.
// Tenerli separati vuol dire che i token "veri" verso CHIFACOSA non devono mai transitare per la
// configurazione del connector: se uno va ruotato, si cambia solo qui, non lato claude.ai.
//
// Multi-profilo: chi gestisce più profili su CHIFACOSA può configurare un token per ciascuno
// (uno stesso server MCP, un solo connector su claude.ai) e scegliere ogni volta su quale
// profilo agire passando il parametro "profile" a ogni strumento — vedi parseProfiles().

import express from 'express';
import { randomUUID } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { z } from 'zod';

const PORT = process.env.PORT || 3000;
const MCP_ACCESS_TOKEN = process.env.MCP_ACCESS_TOKEN;
const CHIFACOSA_BASE_URL = (process.env.CHIFACOSA_BASE_URL || 'https://www.chifacosa.it/api/v1/social-posts').replace(/\/$/, '');

// Legge i profili configurati dalle variabili d'ambiente CHIFACOSA_PROFILE_<N>_NAME /
// CHIFACOSA_PROFILE_<N>_TOKEN (N = 1, 2, 3...) — un nome comodo scelto dall'utente (es. lo slug
// del profilo) abbinato al token API generato per quel profilo da Dashboard -> API su CHIFACOSA.
// Se non ce n'è nessuna, ricade sulla variabile singola CHIFACOSA_API_TOKEN (compatibilità con
// l'installazione a un solo profilo, sotto il nome "principale").
function parseProfiles() {
    const profiles = {};
    for (const key of Object.keys(process.env)) {
        const m = key.match(/^CHIFACOSA_PROFILE_(\d+)_NAME$/);
        if (!m) continue;
        const idx = m[1];
        const name = (process.env[key] || '').trim();
        const token = (process.env[`CHIFACOSA_PROFILE_${idx}_TOKEN`] || '').trim();
        if (name && token) {
            profiles[name] = token;
        }
    }
    if (Object.keys(profiles).length === 0 && process.env.CHIFACOSA_API_TOKEN) {
        profiles['principale'] = process.env.CHIFACOSA_API_TOKEN.trim();
    }
    return profiles;
}

const PROFILES = parseProfiles();
const PROFILE_NAMES = Object.keys(PROFILES);

if (!MCP_ACCESS_TOKEN || PROFILE_NAMES.length === 0) {
    console.error(
        'Mancano le variabili d\'ambiente necessarie: serve MCP_ACCESS_TOKEN e almeno un profilo ' +
        '(CHIFACOSA_PROFILE_1_NAME + CHIFACOSA_PROFILE_1_TOKEN, oppure CHIFACOSA_API_TOKEN per un singolo profilo) — il server non parte senza.'
    );
    process.exit(1);
}

// Wrapper unico per tutte le chiamate all'API CHIFACOSA: stessa gestione errori/JSON/selezione
// del token in base al profilo per ogni strumento, invece di ripeterla 5 volte.
async function chifacosaApi(profileName, path, { method = 'GET', body } = {}) {
    const token = PROFILES[profileName];
    if (!token) {
        return {
            httpStatus: 400,
            data: { success: false, error: `Profilo "${profileName}" non configurato su questo server. Profili disponibili: ${PROFILE_NAMES.join(', ')}.` },
        };
    }
    const res = await fetch(CHIFACOSA_BASE_URL + path, {
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

// Parametro "profile" aggiunto a ogni strumento: un enum dei nomi effettivamente configurati,
// così Claude vede subito le opzioni valide invece di doverle indovinare o chiedertele a parte.
// Se è configurato un solo profilo il campo resta comunque obbligatorio (per coerenza/chiarezza
// dei log), ma con un solo valore possibile Claude lo compila da sé senza doverlo chiedere.
const profileField = {
    profile: z.enum(PROFILE_NAMES).describe(`Su quale profilo agire. Profili configurati: ${PROFILE_NAMES.join(', ')}`),
};

// Campi comuni a create/update — stessa forma esposta dall'API REST, vedi
// app/src/api_helpers.php::apiValidateSocialPostPayload().
const postFieldsSchema = {
    title: z.string().max(100).optional().describe('Titolo del post (max 100 caratteri)'),
    description: z.string().optional().describe('Testo/corpo del post'),
    image_url: z.string().url().optional().describe('URL pubblico di un\'immagine da scaricare e allegare al post'),
    publication_date: z.string().optional().describe('Data/ora di pubblicazione in ISO 8601 con fuso orario esplicito, es. 2026-09-24T08:00:00+02:00'),
    hashtags: z.string().max(300).optional().describe('Hashtag da includere nel post'),
    call_to_action: z.string().max(200).optional().describe('Call to action, es. "Contattaci: link in bio"'),
    status: z.enum(['draft', 'scheduled', 'published']).optional().describe('draft = non pubblico, scheduled = richiede publication_date futura, published = subito visibile'),
};

function buildMcpServer() {
    const server = new McpServer({ name: 'chifacosa-social-posts', version: '1.1.0' });

    server.registerTool('list_chifacosa_profiles', {
        title: 'Elenca i profili CHIFACOSA disponibili',
        description: 'Elenca i nomi dei profili CHIFACOSA configurati su questo server MCP, da usare come valore del parametro "profile" negli altri strumenti.',
        inputSchema: {},
    }, async () => ({
        content: [{ type: 'text', text: JSON.stringify({ success: true, profiles: PROFILE_NAMES }, null, 2) }],
        isError: false,
    }));

    server.registerTool('create_social_post', {
        title: 'Crea un post sulla Timeline di un profilo CHIFACOSA',
        description: 'Crea un nuovo post (subito pubblicato, programmato per una data futura, o come bozza) sulla Timeline pubblica del profilo CHIFACOSA scelto.',
        inputSchema: { ...profileField, ...postFieldsSchema },
    }, async ({ profile, ...fields }) => toolResult(await chifacosaApi(profile, '/create', { method: 'POST', body: fields })));

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
        return toolResult(await chifacosaApi(profile, '/list' + (qs ? `?${qs}` : '')));
    });

    server.registerTool('get_social_post', {
        title: 'Dettaglio di un post',
        description: 'Recupera i dettagli di un singolo post di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID del post') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/${id}`)));

    server.registerTool('update_social_post', {
        title: 'Modifica un post',
        description: 'Modifica un post esistente di un profilo — funziona solo se il post è ancora "draft" o "scheduled" (non ancora pubblicato). Tutti i campi oltre a profile/id sono opzionali: solo quelli forniti vengono aggiornati.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID del post da modificare'), ...postFieldsSchema },
    }, async ({ profile, id, ...fields }) => toolResult(await chifacosaApi(profile, `/${id}`, { method: 'PUT', body: fields })));

    server.registerTool('delete_social_post', {
        title: 'Elimina un post',
        description: 'Elimina definitivamente un post di un profilo, dato il suo ID.',
        inputSchema: { ...profileField, id: z.number().int().describe('ID del post da eliminare') },
    }, async ({ profile, id }) => toolResult(await chifacosaApi(profile, `/${id}`, { method: 'DELETE' })));

    return server;
}

const app = express();
app.use(express.json());

// Log minimale di ogni richiesta in arrivo — mai il valore dell'header Authorization, solo se
// è presente o no: serve a capire da fuori se una richiesta arriva davvero al container (utile
// in fase di collegamento con claude.ai) senza esporre segreti nei log.
app.use((req, res, next) => {
    const hasAuth = req.headers['authorization'] ? 'con Authorization' : 'senza Authorization';
    console.log(`[req] ${req.method} ${req.path} — ${hasAuth} — User-Agent: ${req.headers['user-agent'] || '(nessuno)'}`);
    next();
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
    console.log(`chifacosa-mcp-server in ascolto sulla porta ${PORT} — target: ${CHIFACOSA_BASE_URL} — profili configurati: ${PROFILE_NAMES.join(', ')}`);
});
