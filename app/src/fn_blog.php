<?php
// Blog: slug, URL, categorie, dati strutturati.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


function slugExists(string $slug): bool {
    $stmt = getDB()->prepare('SELECT id FROM users WHERE slug = ?');
    $stmt->execute([$slug]);
    return (bool) $stmt->fetch();
}


// Genera uno slug univoco per un articolo di un dato utente (title -> slug, con suffisso -2, -3... se già esistente)
function generateUniquePostSlug(int $userId, string $title, ?int $excludePostId = null): string {
    $base = slugify($title) ?: 'articolo';
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM blog_posts WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];
        if ($excludePostId) {
            $sql .= ' AND id != ?';
            $params[] = $excludePostId;
        }
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}


// Stesso principio di generateUniquePostSlug(), per le categorie del blog — usato per la pagina
// pubblica filtrata per categoria (/nomeutente/blog/categoria/slug-categoria).
function generateUniqueBlogCategorySlug(int $userId, string $name, ?int $excludeCategoryId = null): string {
    $base = slugify($name) ?: 'categoria';
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM blog_categories WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];
        if ($excludeCategoryId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeCategoryId;
        }
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}


// Costruisce il permalink SEO di un articolo: /nomeutente/blog/anno.mese.giorno.slug-articolo
function blogPostUrl(string $userSlug, array $post): string {
    $datePart = date('Y.m.d', strtotime($post['published_at']));
    return '/' . $userSlug . '/blog/' . $datePart . '.' . $post['slug'];
}


// URL della pagina pubblica di una categoria del blog (elenco filtrato).
function blogCategoryUrl(string $userSlug, array $category): string {
    return '/' . $userSlug . '/blog/categoria/' . $category['slug'];
}


// JSON-LD BreadcrumbList — aiuta Google a mostrare il percorso (es. "Nome > Blog > Categoria")
// nei risultati di ricerca invece del solo URL. $items: elenco ordinato di ['name'=>.., 'url'=>..].
// Il default di json_encode (senza JSON_UNESCAPED_SLASHES) lascia "/" escapato apposta: evita che
// un titolo contenente "</script>" possa interrompere il blocco <script> che lo racchiude.
function breadcrumbJsonLd(array $items): string {
    $list = [];
    foreach ($items as $i => $item) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $item['name'], 'item' => $item['url']];
    }
    $data = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE) . "</script>\n";
}


// JSON-LD BlogPosting per la pagina di un articolo — headline/autore/immagine/data di
// pubblicazione: rende l'articolo idoneo ai rich result "Articolo" di Google, oltre agli
// Open Graph/Twitter Card già presenti (che servono solo per le anteprime sui social).
function blogPostingJsonLd(array $post, string $displayName, string $permalink, ?string $ogImage): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'],
        'description' => $post['excerpt'] ?: textExcerpt($post['content']),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $permalink],
        'url' => $permalink,
        'datePublished' => date(DATE_ATOM, strtotime($post['published_at'])),
        'author' => ['@type' => 'Person', 'name' => $displayName],
        'publisher' => ['@type' => 'Organization', 'name' => siteName()],
    ];
    if ($ogImage) {
        $data['image'] = [$ogImage];
    }
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE) . "</script>\n";
}


// JSON-LD Blog — per l'indice del blog e per le pagine categoria: elenca gli articoli visibili
// come tipo Blog/blogPost, così Google riconosce la pagina come un indice di articoli correlati
// (non solo un elenco generico di link). Limitato ai primi 20 per non gonfiare inutilmente il
// markup su blog molto popolati.
function blogListJsonLd(string $pageUrl, string $name, string $description, array $posts, string $userSlug): string {
    $items = [];
    foreach (array_slice($posts, 0, 20) as $post) {
        $items[] = ['@type' => 'BlogPosting', 'headline' => $post['title'], 'url' => siteUrl(blogPostUrl($userSlug, $post))];
    }
    $data = ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => $name, 'description' => $description, 'url' => $pageUrl];
    if ($items) {
        $data['blogPost'] = $items;
    }
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE) . "</script>\n";
}


// Categorie assegnate a un articolo, in ordine alfabetico — usata sia in dashboard (per
// precompilare le checkbox in modifica) sia lato pubblico (badge sotto il titolo).
function getBlogPostCategories(int $postId): array {
    $stmt = getDB()->prepare('SELECT c.* FROM blog_categories c JOIN blog_post_categories pc ON pc.category_id = c.id WHERE pc.post_id = ? ORDER BY c.name ASC');
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}


// Tag effettivamente in uso da questo profilo, con quante volte compare ciascuno — a differenza
// delle categorie, i tag sono un campo di testo libero per articolo (blog_posts.tags, separati
// da virgola), non una tabella a sé: qui li aggreghiamo per poterli gestire (rinominarli o
// eliminarli ovunque compaiano) come se lo fossero. Confronto case-sensitive: "Napoli" e "napoli"
// restano due tag distinti, esattamente come sono stati scritti negli articoli.
function getBlogTagCounts(int $userId): array {
    $stmt = getDB()->prepare("SELECT tags FROM blog_posts WHERE user_id=? AND tags IS NOT NULL AND tags <> ''");
    $stmt->execute([$userId]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        foreach (array_filter(array_map('trim', explode(',', $row['tags']))) as $tag) {
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }
    }
    ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
    return $counts;
}
