// Server MCP remoto (Streamable HTTP, stateless) per collegare Claude (claude.ai/Claude Desktop)
// al profilo CHIFACOSA configurato qui sotto, riusando l'API pubblica già esistente
// (/api/v1/social-posts/*, vedi app/src/api_helpers.php nel repo principale) invece di parlare
// direttamente col database — stesso principio di qualunque altro client dell'API, solo che
// questo lo fa per conto di Claude tramite gli strumenti MCP.
//
// Autenticazione a due livelli, volutamente diversi:
// 1) MCP_ACCESS_TOKEN — protegge QUESTO server da chiunque altro su internet: è il token che
//    l'utente incolla nella configurazione del connector su claude.ai/Claude Desktop.
// 2) CHIFACOSA_API_TOKEN — il token generato in Dashboard -> API su CHIFACOSA, usato QUI dentro
//    per chiamare l'API per conto dell'utente. Non viene mai comunicato a claude.ai.
// Tenerli separati vuol dire che il token "vero" verso CHIFACOSA non deve mai transitare per la
// configurazione del connector: se un giorno va ruotato, si cambia solo qui, non lato claude.ai.

import express from 'express';
import { randomUUID } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { z } from 'zod';

const PORT = process.env.PORT || 3000;
const MCP_ACCESS_TOKEN = process.env.MCP_ACCESS_TOKEN;
const CHIFACOSA_API_TOKEN = process.env.CHIFACOSA_API_TOKEN;
const CHIFACOSA_BASE_URL = (process.env.CHIFACOSA_BASE_URL || 'https://www.chifacosa.it/api/v1/social-posts').replace(/\/$/, '');

if (!MCP_ACCESS_TOKEN || !CHIFACOSA_API_TOKEN) {
    console.error('Mancano le variabili d\'ambiente MCP_ACCESS_TOKEN e/o CHIFACOSA_API_TOKEN — il server non parte senza.');
    process.exit(1);
}

// Wrapper unico per tutte le chiamate all'API CHIFACOSA: stessa gestione errori/JSON per ogni
// strumento, invece di ripeterla 5 volte.
async function chifacosaApi(path, { method = 'GET', body } = {}) {
    const res = await fetch(CHIFACOSA_BASE_URL + path, {
        method,
        headers: {
            Authorization: `Bearer ${CHIFACOSA_API_TOKEN}`,
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
    status: z.enum(['draft', 'scheduled', 'published']).optional().describe('draft = non pubblico, scheduled = richiede publication_date futura, published = subito visibile'),
};

function buildMcpServer() {
    const server = new McpServer({ name: 'chifacosa-social-posts', version: '1.0.0' });

    server.registerTool('create_social_post', {
        title: 'Crea un post sulla Timeline del profilo CHIFACOSA',
        description: 'Crea un nuovo post (subito pubblicato, programmato per una data futura, o come bozza) sulla Timeline pubblica del profilo collegato a questo server.',
        inputSchema: postFieldsSchema,
    }, async (args) => toolResult(await chifacosaApi('/create', { method: 'POST', body: args })));

    server.registerTool('list_social_posts', {
        title: 'Elenca i post del profilo',
        description: 'Elenca i post del profilo, con filtri opzionali per status e intervallo di date, e paginazione.',
        inputSchema: {
            status: z.enum(['draft', 'scheduled', 'published']).optional(),
            from: z.string().optional().describe('Data minima (YYYY-MM-DD)'),
            to: z.string().optional().describe('Data massima (YYYY-MM-DD)'),
            page: z.number().int().min(1).optional(),
            per_page: z.number().int().min(1).max(100).optional(),
        },
    }, async (args) => {
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(args || {})) {
            if (v !== undefined && v !== null) params.set(k, String(v));
        }
        const qs = params.toString();
        return toolResult(await chifacosaApi('/list' + (qs ? `?${qs}` : '')));
    });

    server.registerTool('get_social_post', {
        title: 'Dettaglio di un post',
        description: 'Recupera i dettagli di un singolo post del profilo, dato il suo ID.',
        inputSchema: { id: z.number().int().describe('ID del post') },
    }, async ({ id }) => toolResult(await chifacosaApi(`/${id}`)));

    server.registerTool('update_social_post', {
        title: 'Modifica un post',
        description: 'Modifica un post esistente — funziona solo se il post è ancora "draft" o "scheduled" (non ancora pubblicato). Tutti i campi sono opzionali: solo quelli forniti vengono aggiornati.',
        inputSchema: { id: z.number().int().describe('ID del post da modificare'), ...postFieldsSchema },
    }, async ({ id, ...fields }) => toolResult(await chifacosaApi(`/${id}`, { method: 'PUT', body: fields })));

    server.registerTool('delete_social_post', {
        title: 'Elimina un post',
        description: 'Elimina definitivamente un post del profilo, dato il suo ID.',
        inputSchema: { id: z.number().int().describe('ID del post da eliminare') },
    }, async ({ id }) => toolResult(await chifacosaApi(`/${id}`, { method: 'DELETE' })));

    return server;
}

const app = express();
app.use(express.json());

app.post('/mcp', async (req, res) => {
    const authHeader = req.headers['authorization'] || '';
    if (authHeader !== `Bearer ${MCP_ACCESS_TOKEN}`) {
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

app.listen(PORT, () => {
    console.log(`chifacosa-mcp-server in ascolto sulla porta ${PORT} — target: ${CHIFACOSA_BASE_URL}`);
});
