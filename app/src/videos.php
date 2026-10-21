<?php
// Modulo Video (video caricati): validazione dei file, anteprima con ffmpeg, salvataggio e
// serializzazione. Distinto dalla sezione "YouTube" (che mostra il canale collegato al profilo).

const VIDEO_MAX_BYTES = 200 * 1024 * 1024;
const VIDEO_ALLOWED_EXT = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'm4v' => 'video/mp4'];
const VIDEO_ALLOWED_MIME = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'];

function videoUploadDir(string $slug): string {
    return '/var/www/html/uploads/videos/' . $slug;
}

// Controlla estensione e contenuto reale (non solo il nome). Ritorna ['ext' => ..., 'mime' => ...]
// oppure ['error' => '...'].
function videoValidateFile(string $path, string $originalName, ?int $size = null): array {
    $size = $size ?? (int) @filesize($path);
    if ($size <= 0) {
        return ['error' => 'File vuoto.'];
    }
    if ($size > VIDEO_MAX_BYTES) {
        return ['error' => 'Il video supera il limite di ' . (VIDEO_MAX_BYTES / 1048576) . ' MB.'];
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset(VIDEO_ALLOWED_EXT[$ext])) {
        return ['error' => 'Formato non supportato: usa MP4, MOV o WebM.'];
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($f, $path);
        finfo_close($f);
    }
    if ($mime !== '' && !in_array($mime, VIDEO_ALLOWED_MIME, true)) {
        return ['error' => 'Il file non sembra un video valido (' . $mime . ').'];
    }
    return ['ext' => $ext, 'mime' => VIDEO_ALLOWED_EXT[$ext]];
}

// Sposta il file caricato (o già scaricato) nella cartella del profilo e ritorna il percorso
// relativo (uploads/videos/slug/xxxx.mp4), oppure null se il salvataggio fallisce.
function videoStoreFile(string $srcPath, string $slug, string $ext, bool $isHttpUpload): ?string {
    $dir = videoUploadDir($slug);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    $fname = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $fname;
    $ok = $isHttpUpload ? move_uploaded_file($srcPath, $dest) : @rename($srcPath, $dest);
    if (!$ok && !$isHttpUpload) {
        $ok = @copy($srcPath, $dest) && @unlink($srcPath);
    }
    return $ok ? 'uploads/videos/' . $slug . '/' . $fname : null;
}

function videoHasFfmpeg(): bool {
    static $has = null;
    if ($has === null) {
        $has = function_exists('exec') && trim((string) @shell_exec('command -v ffmpeg 2>/dev/null')) !== '';
    }
    return $has;
}

// Durata e dimensioni del video, se ffprobe è disponibile. Ritorna [duration, width, height] (null se ignoti).
function videoProbe(string $file): array {
    if (!videoHasFfmpeg()) {
        return [null, null, null];
    }
    $cmd = 'timeout 30 ffprobe -v error -select_streams v:0 -show_entries stream=width,height:format=duration -of json ' . escapeshellarg($file) . ' 2>/dev/null';
    $data = json_decode((string) @shell_exec($cmd), true);
    $stream = $data['streams'][0] ?? [];
    $duration = isset($data['format']['duration']) ? (int) round((float) $data['format']['duration']) : null;
    return [$duration, isset($stream['width']) ? (int) $stream['width'] : null, isset($stream['height']) ? (int) $stream['height'] : null];
}

// Anteprima JPEG (max 720 px di larghezza) presa a circa un secondo dall'inizio. Ritorna il
// percorso relativo, oppure null se ffmpeg non c'è o fallisce (il video resta comunque valido).
function videoMakeThumbnail(string $videoRelPath, string $slug, ?int $duration): ?string {
    if (!videoHasFfmpeg()) {
        return null;
    }
    $dir = videoUploadDir($slug);
    $fname = 'thumb_' . bin2hex(random_bytes(6)) . '.jpg';
    $out = $dir . '/' . $fname;
    $at = ($duration !== null && $duration > 0) ? min(1.0, $duration / 2) : 0.5;
    $cmd = sprintf(
        'timeout 60 ffmpeg -y -v error -ss %s -i %s -frames:v 1 -vf "scale=720:-2" -q:v 3 %s 2>/dev/null',
        escapeshellarg((string) $at),
        escapeshellarg('/var/www/html/' . $videoRelPath),
        escapeshellarg($out)
    );
    @shell_exec($cmd);
    return (is_file($out) && filesize($out) > 0) ? 'uploads/videos/' . $slug . '/' . $fname : null;
}

// Salva l'immagine di anteprima caricata a mano (alternativa a ffmpeg).
function videoStoreThumbFromBytes(string $bytes, string $slug): ?string {
    $jpeg = compressImageToJpeg($bytes, 400000, 1280);
    if ($jpeg === null) {
        return null;
    }
    $dir = videoUploadDir($slug);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    $fname = 'thumb_' . bin2hex(random_bytes(6)) . '.jpg';
    return file_put_contents($dir . '/' . $fname, $jpeg) !== false ? 'uploads/videos/' . $slug . '/' . $fname : null;
}

function videoDeleteFile(?string $relPath): void {
    // Solo dentro uploads/videos/: nessun percorso arbitrario.
    if ($relPath && str_starts_with($relPath, 'uploads/videos/') && !str_contains($relPath, '..')) {
        @unlink('/var/www/html/' . $relPath);
    }
}

function videoDeleteFiles(array $row): void {
    videoDeleteFile($row['video_path'] ?? null);
    videoDeleteFile($row['thumb_path'] ?? null);
}

// Scarica un video da un URL pubblico direttamente su file (a blocchi, senza caricarlo in
// memoria), con tetto di dimensione. Ritorna ['path' => tmp] oppure ['error' => '...'].
function videoDownloadToTemp(string $url): array {
    if (!filter_var($url, FILTER_VALIDATE_URL) || !isSafePublicUrl($url)) {
        return ['error' => 'URL non valido o non pubblico.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'vid_');
    $fh = fopen($tmp, 'wb');
    $written = 0;
    $tooBig = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($fh, &$written, &$tooBig) {
            $written += strlen($chunk);
            if ($written > VIDEO_MAX_BYTES) {
                $tooBig = true;
                return 0;
            }
            return fwrite($fh, $chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    if ($tooBig) {
        @unlink($tmp);
        return ['error' => 'Il video supera il limite di ' . (VIDEO_MAX_BYTES / 1048576) . ' MB.'];
    }
    if (!$ok || $code !== 200) {
        @unlink($tmp);
        return ['error' => 'Download non riuscito (HTTP ' . $code . ').'];
    }
    return ['path' => $tmp];
}

// Crea il record dopo aver salvato il file. $data: title, description, is_public, in_feed,
// publish_at, thumb_path (opzionale). Genera anteprima e metadati se ffmpeg c'è.
function videoCreateRecord(int $userId, string $slug, string $videoRelPath, string $mime, int $size, array $data): int {
    [$duration, $w, $h] = videoProbe('/var/www/html/' . $videoRelPath);
    $thumb = $data['thumb_path'] ?? null;
    if (!$thumb) {
        $thumb = videoMakeThumbnail($videoRelPath, $slug, $duration);
    }
    $stmt = getDB()->prepare('INSERT INTO videos (user_id, title, description, video_path, thumb_path, mime_type, size_bytes, duration_seconds, width, height, is_public, in_feed, publish_at, sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM videos WHERE user_id=?) t))');
    $stmt->execute([
        $userId,
        mb_substr(trim((string) $data['title']), 0, 150),
        trim((string) ($data['description'] ?? '')) ?: null,
        $videoRelPath,
        $thumb,
        $mime,
        $size,
        $duration,
        $w,
        $h,
        !empty($data['is_public']) ? 1 : 0,
        array_key_exists('in_feed', $data) ? (!empty($data['in_feed']) ? 1 : 0) : 1,
        $data['publish_at'] ?? null,
        $userId,
    ]);
    return (int) getDB()->lastInsertId();
}

function videoStatus(array $v): string {
    if (!(int) $v['is_public']) {
        return 'private';
    }
    return (!empty($v['publish_at']) && strtotime($v['publish_at']) > time()) ? 'scheduled' : 'published';
}

function videoPublicUrl(string $slug, int $id): string {
    return siteUrl('/' . $slug . '/video/' . $id);
}

// Accoda il video per la pubblicazione su Metricool (stessa regola degli altri moduli: solo se
// il profilo ha Metricool attivo). $scheduledFor in ora locale "Y-m-d H:i:s" o null.
function videoEnqueueSocial(int $userId, string $slug, array $row, ?string $scheduledFor = null): ?int {
    require_once __DIR__ . '/metricool_queue.php';
    if (!metricoolIsEnabled($userId)) {
        return null;
    }
    return socialQueueEnqueue($userId, 'video', [
        'content_id' => (int) $row['id'],
        'title'      => $row['title'],
        'body'       => $row['description'] ?? '',
        'image_url'  => !empty($row['thumb_path']) ? siteUrl('/' . $row['thumb_path']) : null,
        'media_url'  => siteUrl('/' . $row['video_path']),
        'public_url' => videoPublicUrl($slug, (int) $row['id']),
    ], $scheduledFor);
}

function videoSerialize(array $v, string $slug, int $position): array {
    return [
        'id' => (int) $v['id'],
        'position' => $position,
        'title' => $v['title'],
        'description' => $v['description'] ?? null,
        'video_url' => siteUrl('/' . $v['video_path']),
        'thumbnail_url' => !empty($v['thumb_path']) ? siteUrl('/' . $v['thumb_path']) : null,
        'mime_type' => $v['mime_type'],
        'size_bytes' => (int) $v['size_bytes'],
        'duration_seconds' => $v['duration_seconds'] !== null ? (int) $v['duration_seconds'] : null,
        'width' => $v['width'] !== null ? (int) $v['width'] : null,
        'height' => $v['height'] !== null ? (int) $v['height'] : null,
        'status' => videoStatus($v),
        'is_public' => (bool) (int) $v['is_public'],
        'include_in_feed' => (bool) (int) ($v['in_feed'] ?? 1),
        'publish_at' => function_exists('apiFormatDateTimeRome') ? apiFormatDateTimeRome($v['publish_at'] ?? null) : ($v['publish_at'] ?? null),
        'created_at' => function_exists('apiFormatDateTimeRome') ? apiFormatDateTimeRome($v['created_at'] ?? null) : ($v['created_at'] ?? null),
        'url' => videoPublicUrl($slug, (int) $v['id']),
    ];
}

function videoDurationLabel(?int $seconds): string {
    if (!$seconds) {
        return '';
    }
    return $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds) ;
}

// Riordina: gli ID indicati vanno per primi nell'ordine dato (sort_order più alto = più in alto,
// come per gli album), gli altri restano dopo nel loro ordine attuale.
function videoApplyOrder(int $userId, array $orderedIds): void {
    $stmt = getDB()->prepare('SELECT id FROM videos WHERE user_id=? ORDER BY sort_order DESC, id DESC');
    $stmt->execute([$userId]);
    $current = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    $valid = array_flip($current);
    $final = [];
    foreach ($orderedIds as $id) {
        $id = (int) $id;
        if (isset($valid[$id]) && !in_array($id, $final, true)) {
            $final[] = $id;
        }
    }
    foreach ($current as $id) {
        if (!in_array($id, $final, true)) {
            $final[] = $id;
        }
    }
    $db = getDB();
    $db->beginTransaction();
    $upd = $db->prepare('UPDATE videos SET sort_order=? WHERE id=? AND user_id=?');
    $n = count($final);
    foreach ($final as $i => $id) {
        $upd->execute([$n - $i, $id, $userId]);
    }
    $db->commit();
}
