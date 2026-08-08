<?php
/**
 * Client SMTP minimale e autonomo (nessuna dipendenza esterna/Composer).
 * Supporta autenticazione AUTH LOGIN e connessioni SSL implicito o STARTTLS.
 * Pensato per l'invio di notifiche semplici in testo semplice.
 */
class SimpleSmtpMailer {
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $secure; // 'ssl', 'tls' oppure '' (nessuna cifratura)
    private bool $verifyCert;
    private int $timeout = 10;

    public function __construct(string $host, int $port, string $user, string $pass, string $secure = 'tls', bool $verifyCert = true) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->secure = strtolower($secure);
        $this->verifyCert = $verifyCert;
    }

    /**
     * Invia un'email di testo semplice. Restituisce true/false; in caso di errore
     * scrive il dettaglio nei log PHP del container (visibili da Portainer) senza
     * interrompere l'esecuzione della pagina chiamante.
     */
    public function send(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $body): bool {
        try {
            $remote = ($this->secure === 'ssl' ? 'ssl://' : '') . $this->host;

            // Contesto SSL: molti hosting (es. Aruba) usano un hostname "vetrina" personalizzato
            // (es. smtp.tuodominio.it) che punta allo stesso server con certificato intestato al
            // nome reale del provider (es. smtps.aruba.it). Se verifyCert è disattivato, non
            // blocchiamo l'invio per questo mismatch, comune e non indicativo di un problema di
            // sicurezza reale in questo scenario.
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => $this->verifyCert,
                    'verify_peer_name' => $this->verifyCert,
                    'allow_self_signed' => !$this->verifyCert,
                ],
            ]);

            $socket = @stream_socket_client("{$remote}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                throw new Exception("Connessione SMTP fallita: {$errstr} ({$errno})");
            }
            stream_set_timeout($socket, $this->timeout);

            $this->expect($socket, 220);
            $this->command($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'), 250);

            if ($this->secure === 'tls') {
                $this->command($socket, "STARTTLS", 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("Attivazione TLS fallita (verifica certificato: " . ($this->verifyCert ? 'attiva' : 'disattivata') . ")");
                }
                $this->command($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'), 250);
            }

            if ($this->user !== '') {
                $this->command($socket, "AUTH LOGIN", 334);
                $this->command($socket, base64_encode($this->user), 334);
                $this->command($socket, base64_encode($this->pass), 235);
            }

            $this->command($socket, "MAIL FROM:<{$fromEmail}>", 250);
            $this->command($socket, "RCPT TO:<{$toEmail}>", 250);
            $this->command($socket, "DATA", 354);

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $headers = [
                "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
                "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>",
                "Subject: {$encodedSubject}",
                "MIME-Version: 1.0",
                "Content-Type: text/plain; charset=UTF-8",
                "Content-Transfer-Encoding: base64",
            ];
            $encodedBody = chunk_split(base64_encode($body));
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody . "\r\n.";

            $this->command($socket, $message, 250);
            $this->command($socket, "QUIT", 221);
            fclose($socket);
            return true;
        } catch (Exception $e) {
            error_log('[SimpleSmtpMailer] ' . $e->getMessage());
            return false;
        }
    }

    private function command($socket, string $cmd, int $expectedCode): string {
        fwrite($socket, $cmd . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, int $expectedCode): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // Le risposte multilinea SMTP hanno un trattino dopo il codice sulle righe intermedie
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("Risposta SMTP inattesa: atteso {$expectedCode}, ricevuto '{$response}'");
        }
        return $response;
    }
}
