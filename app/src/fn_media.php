<?php
// Immagini e foto: caricamento, compressione, anteprime per i social, gallerie.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Ricomprime qualsiasi immagine (jpg/png/webp/gif...) in un JPEG che non superi $maxBytes,
// restituendo i byte già pronti da salvare (o null se $data non è un'immagine valida). Unico
// punto del sito che usa GD: prima le foto venivano salvate così come arrivavano (fino a
// qualche MB a testa); ora ogni copertina/foto caricata — moduli "che amo", blog, eventi, link,
// timeline, avatar — passa sempre da qui, così il disco non cresce senza controllo e ogni pagina
// pubblica carica più veloce. La trasparenza (PNG/WebP) viene appiattita su sfondo bianco: il
// JPEG non la supporta. Se la qualità più bassa non basta a stare sotto il limite, si riduce
// anche la dimensione e si riprova, fino a un minimo di 80px di lato.
function compressImageToJpeg(string $data, int $maxBytes = 256000, int $maxDimension = 1600): ?string {
    // Foto ad alta risoluzione (es. scattate con smartphone recenti, anche 20+ megapixel) possono
    // richiedere più degli attuali 128MB di default di PHP per essere decodificate ed elaborate —
    // alzato solo per la richiesta in corso, non è una modifica permanente al sito. Riscontrato
    // in produzione ("Allowed memory size of 134217728 bytes exhausted") sulla pagina che
    // ricomprime le foto già caricate, alcune delle quali molto pesanti.
    @ini_set('memory_limit', '512M');

    $info = @getimagesizefromstring($data);
    $img = @imagecreatefromstring($data);
    if ($img === false) {
        return null;
    }

    $width = imagesx($img);
    $height = imagesy($img);

    // Ridimensiona SUBITO se l'originale supera il necessario, prima di qualunque altra copia in
    // memoria: dimezza il picco di RAM dei passaggi successivi per le foto più pesanti (prima il
    // ridimensionamento avveniva dopo aver già duplicato l'immagine a piena risoluzione).
    $longSide = max($width, $height);
    if ($longSide > $maxDimension) {
        $scale = $maxDimension / $longSide;
        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);
        $img = $resized;
        $width = $newWidth;
        $height = $newHeight;
    }

    // La trasparenza (PNG/WebP) va appiattita su sfondo bianco: il JPEG non la supporta. Un JPEG
    // in ingresso non può averne, quindi si salta il passaggio — altro risparmio di memoria (e di
    // tempo) per il caso più comune, una foto già in JPEG.
    $isJpegSource = $info && $info[2] === IMAGETYPE_JPEG;
    if (!$isJpegSource) {
        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $img, 0, 0, 0, 0, $width, $height);
        imagedestroy($img);
        $img = $flat;
    }

    $qualities = [82, 74, 66, 58, 50, 42, 35, 28];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        foreach ($qualities as $q) {
            ob_start();
            imagejpeg($img, null, $q);
            $bytes = ob_get_clean();
            if (strlen($bytes) <= $maxBytes) {
                imagedestroy($img);
                return $bytes;
            }
        }
        $width = (int) round($width * 0.75);
        $height = (int) round($height * 0.75);
        if ($width < 80 || $height < 80) {
            break;
        }
        $resized = imagecreatetruecolor($width, $height);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $width, $height, imagesx($img), imagesy($img));
        imagedestroy($img);
        $img = $resized;
    }
    // Anche al minimo di qualità/dimensione resta sopra il limite (foto estremamente
    // "rumorosa"): si restituisce comunque il risultato migliore ottenuto, meglio di niente.
    ob_start();
    imagejpeg($img, null, 25);
    $bytes = ob_get_clean();
    imagedestroy($img);
    return $bytes;
}


// Gestisce l'upload di un'immagine di copertina (moduli "che amo", link, articoli blog, eventi,
// timeline). Restituisce il percorso relativo salvato (sempre .jpg, vedi compressImageToJpeg()),
// o null se non è stato caricato nessun file valido. Non lancia mai errori: un file mancante o
// non valido significa semplicemente "nessuna copertina".
function handleCoverUpload(string $slug, string $fileInputName = 'cover'): ?string {
    if (empty($_FILES[$fileInputName]['name']) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $raw = file_get_contents($_FILES[$fileInputName]['tmp_name']);
    if ($raw === false) {
        return null;
    }
    $jpeg = compressImageToJpeg($raw);
    if ($jpeg === null) {
        return null;
    }
    $fname = bin2hex(random_bytes(6)) . '.jpg';
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = $dir . '/' . $fname;
    if (file_put_contents($dest, $jpeg) !== false) {
        return 'uploads/images/' . $slug . '/' . $fname;
    }
    return null;
}


// Copia "per la condivisione social" della foto di copertina di un post con più foto — usata SOLO
// per og:image/Twitter card (Metricool e strumenti simili, per l'immagine del post automatico,
// leggono l'og:image della pagina permalink e non l'allegato RSS: vedi commento in feed.php), MAI
// per il carosello mostrato sul sito, che continua a mostrare le foto originali intatte. Ha senso
// solo quando ci sono più foto: sui social arriva sempre e solo questa singola immagine (mai un
// carosello), quindi la scritta segnala che ce ne sono altre da vedere nel link in descrizione.
// Generata una sola volta e tenuta in cache su disco accanto all'originale (rigenerata solo se
// l'originale cambia) — non un'elaborazione ad ogni visita/condivisione. Ritorna il percorso
// dell'originale, invariato, se la generazione fallisce per qualunque motivo (GD assente, font non
// trovato, file non leggibile...): un problema di stile non deve mai rompere la condivisione.
function getFeedShareImage(string $imagePath): string {
    $srcFile = '/var/www/html/' . $imagePath;
    if (!is_file($srcFile)) {
        return $imagePath;
    }

    $dir = dirname($imagePath);
    $base = pathinfo($imagePath, PATHINFO_FILENAME);
    $shareRelPath = ($dir !== '.' ? $dir . '/' : '') . $base . '__share.jpg';
    $shareFile = '/var/www/html/' . $shareRelPath;

    if (is_file($shareFile) && filemtime($shareFile) >= filemtime($srcFile)) {
        return $shareRelPath;
    }

    // Percorso assoluto fisso, NON relativo a __DIR__: in produzione (Dockerfile) src/ e public/
    // finiscono in due cartelle indipendenti, /var/www/src/ e /var/www/html/ — non sorelle come
    // nel checkout di sviluppo (entrambe dentro app/), dove "risali di uno da src/ ed entra in
    // public/" combacia per coincidenza. Bug reale riscontrato in produzione: il font veniva
    // cercato in /var/www/public/... (inesistente), mai trovato, testo mai disegnato — nessun
    // errore visibile, la funzione ricadeva in silenzio sulla foto originale.
    // Il percorso resta dentro "garden-anomaly" anche dopo la rimozione di quel tema: il file
    // del font è rimasto lì apposta (unico asset di quella cartella sopravvissuto), per non
    // dover spostare il font e aggiornare questo percorso hardcoded in più punti.
    $font = '/var/www/html/assets/themes/garden-anomaly/fonts/SpaceGrotesk-SemiBold.ttf';

    // function_exists('imagettftext') NON basta a garantire che funzioni davvero: la funzione
    // resta definita anche quando GD è stato compilato senza FreeType, e in quel caso fallisce
    // silenziosamente (warning + false) invece di generare un errore bloccante — da qui il
    // controllo esplicito su gd_info(), l'unico modo affidabile per saperlo in anticipo.
    $gdInfo = function_exists('gd_info') ? gd_info() : [];
    $hasFreeType = !empty($gdInfo['FreeType Support']);
    if (!$hasFreeType) {
        error_log('[getFeedShareImage] GD senza supporto FreeType: impossibile scrivere il testo, uso la foto originale per ' . $imagePath);
        return $imagePath;
    }
    if (!is_file($font)) {
        error_log('[getFeedShareImage] Font non trovato (' . $font . '), uso la foto originale per ' . $imagePath);
        return $imagePath;
    }

    $img = @imagecreatefromstring((string) file_get_contents($srcFile));
    if ($img === false) {
        error_log('[getFeedShareImage] Impossibile decodificare l\'immagine sorgente ' . $imagePath);
        return $imagePath;
    }
    imagealphablending($img, true);

    $width = imagesx($img);
    $height = imagesy($img);
    $text = 'Link Album in Descrizione';

    // Dimensione del testo proporzionale alla larghezza della foto (min/max per non diventare
    // illeggibile o sproporzionata su foto molto piccole o molto grandi), fascia semitrasparente
    // sotto per restare leggibile su qualunque sfondo.
    $fontSize = max(14, min(34, (int) round($width / 22)));
    $barHeight = (int) round($fontSize * 2.8);
    $overlay = imagecolorallocatealpha($img, 0, 0, 0, 40);
    imagefilledrectangle($img, 0, $height - $barHeight, $width, $height, $overlay);

    $white = imagecolorallocate($img, 255, 255, 255);
    $box = @imagettfbbox($fontSize, 0, $font, $text);
    if ($box === false) {
        error_log('[getFeedShareImage] imagettfbbox() fallita (font/FreeType non utilizzabile), uso la foto originale per ' . $imagePath);
        imagedestroy($img);
        return $imagePath;
    }
    $textWidth = $box[2] - $box[0];
    $x = (int) round(($width - $textWidth) / 2);
    $y = $height - (int) round($barHeight / 2) + (int) round($fontSize / 3);
    $drawn = @imagettftext($img, $fontSize, 0, $x, $y, $white, $font, $text);
    if ($drawn === false) {
        error_log('[getFeedShareImage] imagettftext() fallita, uso la foto originale per ' . $imagePath);
        imagedestroy($img);
        return $imagePath;
    }

    if (!is_dir(dirname($shareFile))) {
        @mkdir(dirname($shareFile), 0775, true);
    }
    $ok = imagejpeg($img, $shareFile, 88);
    imagedestroy($img);

    if (!$ok) {
        error_log('[getFeedShareImage] imagejpeg() non è riuscita a scrivere ' . $shareFile . ' (permessi/spazio disco?), uso la foto originale per ' . $imagePath);
    }

    return $ok ? $shareRelPath : $imagePath;
}


// Stato di getFeedShareImage(), leggibile dall'Area Admin senza bisogno di accesso alla shell del
// server (docker exec) — utile per chi aggiorna solo tramite Portainer collegato al repository e
// non ha un terminale a disposizione. Include un test dal vivo (genera davvero un'immagine di
// prova con GD, bypassando la cache) invece di limitarsi a controllare che i pezzi siano
// presenti: è l'unico modo per essere certi che il risultato finale abbia davvero la scritta,
// dato che GD può "sembrare" funzionante (nessun errore fatale) pur non riuscendo a disegnare
// testo se manca il supporto FreeType.
function diagnoseFeedShareImage(): array {
    $gdInfo = function_exists('gd_info') ? gd_info() : [];
    $hasFreeType = !empty($gdInfo['FreeType Support']);
    // Percorso assoluto fisso, NON relativo a __DIR__: in produzione (Dockerfile) src/ e public/
    // finiscono in due cartelle indipendenti, /var/www/src/ e /var/www/html/ — non sorelle come
    // nel checkout di sviluppo (entrambe dentro app/), dove "risali di uno da src/ ed entra in
    // public/" combacia per coincidenza. Bug reale riscontrato in produzione: il font veniva
    // cercato in /var/www/public/... (inesistente), mai trovato, testo mai disegnato — nessun
    // errore visibile, la funzione ricadeva in silenzio sulla foto originale.
    // "garden-anomaly" nel percorso è solo il nome della cartella: quel tema è stato rimosso,
    // il font no (vedi lo stesso percorso in getFeedShareImage() qui sopra).
    $font = '/var/www/html/assets/themes/garden-anomaly/fonts/SpaceGrotesk-SemiBold.ttf';
    $fontExists = is_file($font);

    $testRelPath = 'uploads/images/_diagnostica/test.jpg';
    $testFile = '/var/www/html/' . $testRelPath;
    $testDir = dirname($testFile);
    $previewUrl = null;

    if ($hasFreeType && $fontExists) {
        if (!is_dir($testDir) && !@mkdir($testDir, 0775, true)) {
            return [
                'has_freetype' => $hasFreeType, 'font_exists' => $fontExists,
                'preview_url' => null, 'error' => 'Impossibile creare la cartella ' . $testDir . ' (permessi di scrittura?).',
            ];
        }
        $img = imagecreatetruecolor(600, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 70, 130, 180));
        imagejpeg($img, $testFile, 90);
        imagedestroy($img);
        // Bypassa la cache di getFeedShareImage() apposta: qui serve rigenerare SEMPRE, per
        // riflettere lo stato reale in questo momento, non un tentativo precedente (magari con
        // una versione più vecchia del codice, o con GD in uno stato diverso).
        @unlink(dirname($testFile) . '/test__share.jpg');
        $resultPath = getFeedShareImage($testRelPath);
        $previewUrl = assetUrl('/' . $resultPath);
    }

    return [
        'has_freetype' => $hasFreeType,
        'font_exists' => $fontExists,
        'preview_url' => $previewUrl,
        'error' => null,
    ];
}


// Ripulisce tutte le copie "per la condivisione social" già generate (vedi getFeedShareImage()):
// serve a forzare la rigenerazione di tutte quelle esistenti dopo una correzione al codice che le
// genera, dato che la cache su disco altrimenti le considera valide finché la FOTO ORIGINALE non
// cambia — non capisce da sola che è cambiata la logica di generazione. Raggiungibile dall'Area
// Admin, così non serve un accesso diretto al server (docker exec) per pulirle. Ritorna quante ne
// ha cancellate.
function clearFeedShareImageCache(): int {
    $count = 0;
    $files = glob('/var/www/html/uploads/images/*/*__share.jpg') ?: [];
    foreach ($files as $file) {
        if (@unlink($file)) {
            $count++;
        }
    }
    return $count;
}


// Upload multiplo (fino a $maxFiles foto) — usato SOLO dai post Timeline con più foto (carosello
// stile Instagram): la prima foto resta salvata come al solito su timeline_posts.image_path
// (quella che compare nel Feed), le eventuali altre finiscono in timeline_post_photos. Ogni file
// passa dalla stessa compressione di handleCoverUpload() (sempre .jpg, mai oltre 250KB); un file
// non valido viene scartato in silenzio senza bloccare gli altri. Restituisce l'elenco dei
// percorsi relativi salvati, nell'ordine di caricamento (array vuoto se nessun file valido).
function handleMultiCoverUpload(string $slug, string $fileInputName, int $maxFiles = 10): array {
    if (empty($_FILES[$fileInputName]['name']) || !is_array($_FILES[$fileInputName]['name'])) {
        return [];
    }
    $files = $_FILES[$fileInputName];
    $dir = '/var/www/html/uploads/images/' . $slug;
    $paths = [];
    for ($i = 0; $i < count($files['name']) && count($paths) < $maxFiles; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $raw = file_get_contents($files['tmp_name'][$i]);
        if ($raw === false) {
            continue;
        }
        $jpeg = compressImageToJpeg($raw);
        if ($jpeg === null) {
            continue;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fname = bin2hex(random_bytes(6)) . '.jpg';
        if (file_put_contents($dir . '/' . $fname, $jpeg) !== false) {
            $paths[] = 'uploads/images/' . $slug . '/' . $fname;
        }
    }
    return $paths;
}


// Foto aggiuntive (dalla 2 alla 10) di un post Timeline, nell'ordine di caricamento — vedi
// handleMultiCoverUpload(). La prima foto non è qui: resta su timeline_posts.image_path.
function getTimelinePostPhotos(int $postId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM timeline_post_photos WHERE post_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$postId]);
    return array_column($stmt->fetchAll(), 'image_path');
}


// Come getTimelinePostPhotos(), ma per il modulo "Viaggi che amo" (fan_favorite_trips): foto
// aggiuntive (dalla 2 alla 10) di un viaggio, nell'ordine di caricamento. La prima foto non è
// qui: resta su fan_favorite_trips.image_path, come sempre.
function getTripPhotos(int $tripId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM fan_favorite_trip_photos WHERE trip_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$tripId]);
    return array_column($stmt->fetchAll(), 'image_path');
}


// Come getTimelinePostPhotos()/getTripPhotos(), per il modulo "Album" (photo_albums): foto
// aggiuntive (dalla 2 alla 50) di un album, nell'ordine di caricamento. La prima foto non è qui:
// resta su photo_albums.cover_path, come sempre.
function getAlbumPhotos(int $albumId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM photo_album_photos WHERE album_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$albumId]);
    return array_column($stmt->fetchAll(), 'image_path');
}


// Tutte le foto dei post Timeline pubblici e già pubblicati di un profilo (la copertina di
// ciascun post più le eventuali foto extra del carosello), più recenti prima — usata dalla
// sezione pubblica "Foto" (foto.php), che è una semplice VETRINA aggregata: nessuna tabella
// propria, nessun dato nuovo da gestire, solo le foto già presenti nei post Timeline. Ogni foto
// resta collegata al post di provenienza (post_id), per il link di apertura.
function getPublicTimelinePhotos(int $userId, int $limit = 200): array {
    $stmt = getDB()->prepare("
        (SELECT tp.id AS post_id, tp.image_path AS photo, COALESCE(tp.publish_at, tp.created_at) AS data
         FROM timeline_posts tp
         WHERE tp.user_id = ? AND tp.visibility = 'public' AND (tp.publish_at IS NULL OR tp.publish_at <= NOW())
           AND tp.image_path IS NOT NULL)
        UNION ALL
        (SELECT tpp.post_id, tpp.image_path AS photo, COALESCE(tp.publish_at, tp.created_at) AS data
         FROM timeline_post_photos tpp
         JOIN timeline_posts tp ON tp.id = tpp.post_id
         WHERE tp.user_id = ? AND tp.visibility = 'public' AND (tp.publish_at IS NULL OR tp.publish_at <= NOW()))
        ORDER BY data DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}


// Come getAlbumPhotos()/getTimelinePostPhotos(), per il modulo "Servizi" (galleria fotografica
// del singolo servizio). La prima foto resta su services.cover_path, come sempre.
function getServicePhotos(int $serviceId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM service_photos WHERE service_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$serviceId]);
    return array_column($stmt->fetchAll(), 'image_path');
}


// Elimina il file di copertina dal disco, se presente (usato quando si elimina un link/post/evento)
function deleteCoverFile(?string $coverPath): void {
    if ($coverPath) {
        @unlink('/var/www/html/' . $coverPath);
    }
}


// Ripulisce l'eventuale copia "per la condivisione social" (vedi getFeedShareImage()) generata per
// questa foto, se ne esiste una — va richiamata insieme a deleteCoverFile() ogni volta che
// l'originale viene eliminato, altrimenti resterebbe un file orfano sul disco.
function deleteFeedShareImage(?string $coverPath): void {
    if (!$coverPath) {
        return;
    }
    $dir = dirname($coverPath);
    $base = pathinfo($coverPath, PATHINFO_FILENAME);
    @unlink('/var/www/html/' . ($dir !== '.' ? $dir . '/' : '') . $base . '__share.jpg');
}


// Elimina UNA foto sola di un contenuto con più foto (post Timeline, Viaggio, Album, Servizio),
// senza toccare le altre né dover ricaricare l'intero set come nell'unica modalità finora
// disponibile in modifica. $photoId vale 0 per la copertina, altrimenti l'id della riga nella
// tabella delle foto extra ($childTable). Eliminare proprio la copertina promuove la prima foto
// extra rimasta al suo posto (un contenuto ha sempre bisogno di almeno una foto per essere
// mostrato in Home/Feed/RSS) — se non ce n'è nessuna, l'eliminazione viene rifiutata.
function deleteSingleGalleryPhoto(string $parentTable, string $coverColumn, string $childTable, string $parentIdColumn, int $parentId, int $userId, int $photoId, ?string $coverThumbColumn = null): array {
    $db = getDB();
    $selectCols = $coverThumbColumn ? "{$coverColumn} AS cover, {$coverThumbColumn} AS cover_thumb" : "{$coverColumn} AS cover";
    $stmt = $db->prepare("SELECT {$selectCols} FROM {$parentTable} WHERE id=? AND user_id=?");
    $stmt->execute([$parentId, $userId]);
    $parent = $stmt->fetch();
    if (!$parent) {
        return ['ok' => false, 'error' => 'Non trovato.'];
    }

    if ($photoId === 0) {
        $stmt = $db->prepare("SELECT id, image_path FROM {$childTable} WHERE {$parentIdColumn}=? ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$parentId]);
        $nextPhoto = $stmt->fetch();
        if (!$nextPhoto) {
            return ['ok' => false, 'error' => 'Non puoi eliminare l\'unica foto rimasta — carica una nuova foto per sostituirla.'];
        }
        deleteCoverFile($parent['cover']);
        deleteFeedShareImage($parent['cover']);
        if ($coverThumbColumn) {
            deleteCoverFile($parent['cover_thumb'] ?? null);
            $db->prepare("UPDATE {$parentTable} SET {$coverColumn}=?, {$coverThumbColumn}=NULL WHERE id=?")->execute([$nextPhoto['image_path'], $parentId]);
        } else {
            $db->prepare("UPDATE {$parentTable} SET {$coverColumn}=? WHERE id=?")->execute([$nextPhoto['image_path'], $parentId]);
        }
        $db->prepare("DELETE FROM {$childTable} WHERE id=?")->execute([$nextPhoto['id']]);
        return ['ok' => true];
    }

    $stmt = $db->prepare("SELECT image_path FROM {$childTable} WHERE id=? AND {$parentIdColumn}=?");
    $stmt->execute([$photoId, $parentId]);
    $photo = $stmt->fetch();
    if (!$photo) {
        return ['ok' => false, 'error' => 'Foto non trovata.'];
    }
    deleteCoverFile($photo['image_path']);
    $db->prepare("DELETE FROM {$childTable} WHERE id=?")->execute([$photoId]);
    return ['ok' => true];
}


// Blocco foto di un elemento condivisibile (post Timeline, viaggio...): una foto singola (come
// sempre) oppure, se ce n'è più di una, un carosello scorrevole con un pulsante per aprirlo a
// tutto schermo. Usata sia per l'elemento principale di una pagina sia per ciascun "altro della
// stessa giornata" mostrato sotto, quindi ogni istanza porta il proprio $itemId nell'attributo
// data-post: sulla stessa pagina possono comparirne più di una, servono id univoci perché lo
// script condiviso (assets/js/ig-carousel.js) le trovi una per una senza confondersi.
function renderPhotoCarousel(array $photos, int $itemId): string {
    if (!$photos) {
        return '';
    }
    if (count($photos) === 1) {
        // Una sola foto: niente frecce/puntini (non ci sarebbe nulla tra cui scorrere) — l'intera
        // immagine è il pulsante che apre la stessa lightbox a tutto schermo delle gallerie con
        // più foto, riusando ig-carousel.js/css senza bisogno di codice JS dedicato. Il pulsante
        // .ig-expand-btn deve restare un DISCENDENTE di .ig-carousel (non lo stesso elemento):
        // ig-carousel.js lo cerca con carousel.querySelector(), che non trova mai l'elemento di
        // partenza stesso.
        $html = '<div class="ig-carousel ig-carousel-single" data-post="' . $itemId . '">'
              . '<button type="button" class="ig-expand-btn ig-expand-btn-cover" aria-label="Vedi a tutto schermo">'
              . '<img src="/' . e($photos[0]) . '" alt="" class="ig-carousel-single-img">'
              . '<span class="ig-expand-icon"><i class="fa-solid fa-expand"></i></span>'
              . '</button>'
              . '</div>';
        $html .= '<div class="ig-lightbox" data-post="' . $itemId . '">'
              . '<button type="button" class="ig-lightbox-close" aria-label="Chiudi">✕</button>'
              . '<div class="ig-lightbox-track"><img src="/' . e($photos[0]) . '" alt="" loading="lazy"></div>'
              . '</div>';
        return $html;
    }

    $slides = '';
    foreach ($photos as $ph) {
        $slides .= '<img src="/' . e($ph) . '" alt="" loading="lazy">';
    }
    $dots = '';
    foreach ($photos as $i => $ph) {
        $dots .= '<span class="ig-dot' . ($i === 0 ? ' active' : '') . '" data-index="' . $i . '"></span>';
    }

    $html = '<div class="ig-carousel" data-post="' . $itemId . '">'
          . '<button type="button" class="ig-expand-btn" aria-label="Vedi a tutto schermo"><i class="fa-solid fa-expand"></i></button>'
          . '<div class="ig-carousel-track">' . $slides . '</div>'
          . '<button type="button" class="ig-arrow ig-arrow-prev" aria-label="Foto precedente">‹</button>'
          . '<button type="button" class="ig-arrow ig-arrow-next" aria-label="Foto successiva">›</button>'
          . '<div class="ig-carousel-dots">' . $dots . '</div>'
          . '</div>';

    $html .= '<div class="ig-lightbox" data-post="' . $itemId . '">'
          . '<button type="button" class="ig-lightbox-close" aria-label="Chiudi">✕</button>'
          . '<div class="ig-lightbox-track">' . $slides . '</div>'
          . '<button type="button" class="ig-arrow ig-arrow-prev" aria-label="Foto precedente">‹</button>'
          . '<button type="button" class="ig-arrow ig-arrow-next" aria-label="Foto successiva">›</button>'
          . '<div class="ig-carousel-dots ig-lightbox-dots">' . $dots . '</div>'
          . '</div>';

    return $html;
}
