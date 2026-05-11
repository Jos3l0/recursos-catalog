<?php
/**
 * Exportador de posts del WordPress de Recursos Educativos
 * Genera JSON en la misma estructura que el catálogo original
 *
 * Uso: php export-material.php
 */

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '1234');
define('DB_NAME', 'recursos_db');
define('DB_PREFIX', 'exfc47jf_');
define('OUTPUT_DIR', __DIR__ . '/material-json');

// Configuración
define('POSTS_PER_FILE', 400);
define('WP_URL', 'http://localhost/recursos');

// ---- Conexión a la BD ----
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_error) {
    die("Error de conexión: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

// ---- Funciones auxiliares ----
function clean_html($html) {
    if (!$html) return '';
    // Remover CSS de Elementor del inicio
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    // Remover <link rel="stylesheet"> que son de plugins
    $html = preg_replace('/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', '', $html);
    // Remover iframes de viewer.html (se procesan aparte)
    $html = preg_replace('/<iframe[^>]*src=["\'][^"\']*viewer\.html[^"\']*["\'][^>]*>.*?<\/iframe>/is', '', $html);
    return trim($html);
}

function strip_tags_content($html) {
    if (!$html) return '';
    // Remover scripts y estilos
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', ' ', $html);
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', ' ', $html);
    // Remover tags HTML pero preservar texto
    $html = strip_tags($html);
    // Limpiar entidades HTML
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Remover espacios múltiples
    $html = preg_replace('/\s+/', ' ', $html);
    return trim($html);
}

function get_post_thumbnail($post_id, $mysqli) {
    $thumb_id = get_post_meta($post_id, '_thumbnail_id', $mysqli);
    if (!$thumb_id) return null;
    $sql = "SELECT guid FROM " . DB_PREFIX . "posts WHERE ID = ? AND post_type = 'attachment'";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $thumb_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return str_replace(WP_URL, '', $row['guid']);
    }
    return null;
}

function get_post_meta($post_id, $meta_key, $mysqli) {
    $sql = "SELECT meta_value FROM " . DB_PREFIX . "postmeta WHERE post_id = ? AND meta_key = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('is', $post_id, $meta_key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['meta_value'];
    }
    return null;
}

function get_post_categories($post_id, $mysqli) {
    $sql = "SELECT t.name, t.slug, tt.taxonomy
            FROM " . DB_PREFIX . "term_relationships tr
            JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN " . DB_PREFIX . "terms t ON tt.term_id = t.term_id
            WHERE tr.object_id = ? AND tt.taxonomy IN ('category', 'post_tag')
            ORDER BY tt.taxonomy, t.name";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $post_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $categories = [];
    $tags = [];
    while ($row = $result->fetch_assoc()) {
        $term = ['name' => $row['name'], 'slug' => $row['slug']];
        if ($row['taxonomy'] === 'category') {
            $categories[] = $term;
        } else {
            $tags[] = $term;
        }
    }
    return ['categories' => $categories, 'tags' => $tags];
}

function sanitize_search_text($text) {
    // Limpiar texto para búsqueda
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

// ---- Obtener posts ----
echo "Obteniendo posts publicados...\n";
$sql = "SELECT ID, post_title, post_content, post_date, post_name, post_excerpt
        FROM " . DB_PREFIX . "posts
        WHERE post_type = 'post' AND post_status = 'publish'
        ORDER BY post_date DESC, ID DESC";
$result = $mysqli->query($sql);
$posts = $result->fetch_all(MYSQLI_ASSOC);
echo "Total de posts: " . count($posts) . "\n";

// ---- Crear directorio de salida ----
if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0755, true);
}

// ---- Exportar posts en chunks ----
echo "Exportando posts...\n";
$postChunks = array_chunk($posts, POSTS_PER_FILE);
$postFiles = [];
$allPostsIds = [];

foreach ($postChunks as $chunkIndex => $chunk) {
    $postsData = [];
    foreach ($chunk as $post) {
        $post_id = (int)$post['ID'];
        $allPostsIds[] = $post_id;

        // Obtener categorías y tags
        $taxonomies = get_post_categories($post_id, $mysqli);

        // Obtener imagen destacada
        $thumb_path = get_post_thumbnail($post_id, $mysqli);
        $featured_image_url = $thumb_path ? WP_URL . $thumb_path : null;

        // Generar search_blob (metadatos para búsqueda)
        $cat_names = array_column($taxonomies['categories'], 'name');
        $tag_names = array_column($taxonomies['tags'], 'name');
        $search_blob = implode(' ', array_merge($cat_names, $tag_names));

        $postData = [
            'id' => $post_id,
            'title' => $post['post_title'],
            'slug' => $post['post_name'],
            'date' => $post['post_date'],
            'featured_image_url' => $featured_image_url,
            'content_html_clean' => clean_html($post['post_content']),
            'content_text' => strip_tags_content($post['post_content']),
            'search_blob' => $search_blob,
            'categories' => $taxonomies['categories'],
            'tags' => $taxonomies['tags'],
        ];

        $postsData[] = $postData;
    }

    $fileName = 'posts-' . ($chunkIndex + 1) . '.json';
    file_put_contents(OUTPUT_DIR . '/' . $fileName, json_encode($postsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $postFiles[] = $fileName;
    echo "  - Guardado: $fileName (" . count($postsData) . " posts)\n";
}

// ---- Generar índice de categorías (simula area_tematica) ----
echo "Generando índice de categorías...\n";
$sql = "SELECT t.name, t.slug, tt.taxonomy, tt.count
        FROM " . DB_PREFIX . "terms t
        JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy IN ('category', 'post_tag') AND tt.count > 0
        ORDER BY tt.taxonomy, tt.count DESC";
$result = $mysqli->query($sql);
$categories = $result->fetch_all(MYSQLI_ASSOC);

// Estructura como en el original: slug -> { name, total_posts, ids[] }
$categoryIndex = [];
$postTagIndex = [];

foreach ($categories as $cat) {
    // Obtener IDs de posts para esta categoría
    $sql = "SELECT tr.object_id FROM " . DB_PREFIX . "term_relationships tr
            JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id = (SELECT term_id FROM " . DB_PREFIX . "terms WHERE slug = ? LIMIT 1)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $cat['slug']);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['object_id'];
    }

    $entry = [
        'name' => $cat['name'],
        'total_posts' => (int)$cat['count'],
        'ids' => $ids,
    ];

    if ($cat['taxonomy'] === 'category') {
        $categoryIndex[$cat['slug']] = $entry;
    } else {
        $postTagIndex[$cat['slug']] = $entry;
    }
}

// Guardar categorías
file_put_contents(OUTPUT_DIR . '/categories.json', json_encode($categoryIndex, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  - categories.json: " . count($categoryIndex) . " categorías\n";

// Guardar tags
file_put_contents(OUTPUT_DIR . '/tags.json', json_encode($postTagIndex, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  - tags.json: " . count($postTagIndex) . " tags\n";

// ---- Generar index principal ----
$index = [
    'version' => '1.0',
    'generated_at' => date('c'),
    'source' => [
        'site_url' => WP_URL,
        'db_name' => DB_NAME,
    ],
    'counts' => [
        'posts' => count($posts),
        'categories' => count($categoryIndex),
        'tags' => count($postTagIndex),
    ],
    'files' => [
        'posts' => $postFiles,
        'categories' => 'categories.json',
        'tags' => 'tags.json',
    ],
];

file_put_contents(OUTPUT_DIR . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  - index.json creado\n";

// ---- Resumen ----
echo "\n=== Exportación completa ===\n";
echo "Posts exportados: " . count($posts) . "\n";
echo "Archivos generados en: " . OUTPUT_DIR . "\n";
echo "Archivos de posts: " . count($postFiles) . "\n";
echo "Categorías: " . count($categoryIndex) . "\n";
echo "Tags: " . count($postTagIndex) . "\n";

$mysqli->close();