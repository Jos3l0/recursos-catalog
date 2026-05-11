<?php
/**
 * Exportador de recursos educativos - Estructura idéntica al original
 * 3 grupos de filtros: Área Temática, Nivel Educativo, Destinatarios
 *
 * Uso: php export-material.php
 */

// Configuración BD
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '1234');
define('DB_NAME', 'recursos_db');
define('DB_PREFIX', 'exfc47jf_');
define('OUTPUT_DIR', __DIR__ . '/material-json');
define('POSTS_PER_FILE', 400);
define('WP_URL', 'https://test-1.mendoza.edu.ar');

// ---- Conexión ----
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_error) {
    die("Error de conexión: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

// ---- Configuración de filtros ----
$FILTER_GROUPS = [
    'area_tematica' => [
        'ciencias-naturales',
        'ciencias-sociales',
        'educacion-artistica',
        'educacion-fisica',
        'lengua-espacio-curricular',
        'lenguas-extranjeras',
        'matematica-espacio-curricular',
        'tecnologia',
    ],
    'nivel_educativo' => [
        'educacion-especial',
        'inicial',
        'jovenes-y-adultos',
        'primario',
        'primera-infancia-cepi',
        'secundario',
        'superior',
    ],
    'destinatarios' => [
        'docentes-destinatarios',
        'estudiantes-destinatarios',
        'padres',
    ],
];

// ---- Funciones ----
function clean_html($html) {
    if (!$html) return '';
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', '', $html);
    $html = preg_replace('/<iframe[^>]*src=["\'][^"\']*viewer\.html[^"\']*["\'][^>]*>.*?<\/iframe>/is', '', $html);
    return trim($html);
}

function strip_tags_content($html) {
    if (!$html) return '';
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', ' ', $html);
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', ' ', $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\s+/', ' ', $html);
    return trim($html);
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

// ---- Exportar posts ----
echo "Obteniendo posts publicados...\n";
$sql = "SELECT ID, post_title, post_content, post_date, post_name
        FROM " . DB_PREFIX . "posts
        WHERE post_type = 'post' AND post_status = 'publish'
        ORDER BY post_date DESC, ID DESC";
$result = $mysqli->query($sql);
$posts = $result->fetch_all(MYSQLI_ASSOC);
echo "Total posts: " . count($posts) . "\n";

// Crear directorio
if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0755, true);
}

// Exportar posts en chunks
echo "Exportando posts...\n";
$postChunks = array_chunk($posts, POSTS_PER_FILE);
$postFiles = [];
$allPostIds = [];

foreach ($postChunks as $chunkIndex => $chunk) {
    $postsData = [];
    foreach ($chunk as $post) {
        $post_id = (int)$post['ID'];
        $allPostIds[] = $post_id;

        // Imagen destacada
        $thumb_id = get_post_meta($post_id, '_thumbnail_id', $mysqli);
        $featured_image_url = null;
        if ($thumb_id) {
            $sql = "SELECT guid FROM " . DB_PREFIX . "posts WHERE ID = ? AND post_type = 'attachment'";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('i', $thumb_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $local_url = $row['guid'];
                $featured_image_url = str_replace('http://localhost/recursos', WP_URL, $local_url);
            }
        }

        // Taxonomy terms para este post
        $sql = "SELECT t.name, t.slug, tt.taxonomy
                FROM " . DB_PREFIX . "term_relationships tr
                JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                JOIN " . DB_PREFIX . "terms t ON tt.term_id = t.term_id
                WHERE tr.object_id = ? AND tt.taxonomy = 'category'";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $post_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $categories = [];
        while ($row = $res->fetch_assoc()) {
            $categories[] = ['name' => $row['name'], 'slug' => $row['slug']];
        }

        $postData = [
            'id' => $post_id,
            'title' => $post['post_title'],
            'slug' => $post['post_name'],
            'date' => $post['post_date'],
            'featured_image_url' => $featured_image_url,
            'content_html_clean' => clean_html($post['post_content']),
            'content_text' => strip_tags_content($post['post_content']),
            'area_tematica' => [],
            'nivel_educativo' => [],
            'destinatarios' => [],
        ];

        // Clasificar categorías en los 3 grupos
        $allCatSlugs = array_column($categories, 'slug');
        $searchBlobParts = [];
        foreach ($FILTER_GROUPS as $group => $slugs) {
            foreach ($slugs as $slug) {
                if (in_array($slug, $allCatSlugs)) {
                    $catData = array_values(array_filter($categories, fn($c) => $c['slug'] === $slug));
                    if (!empty($catData)) {
                        $postData[$group][] = $catData[0];
                        $searchBlobParts[] = $catData[0]['name'];
                    }
                }
            }
        }
        $postData['search_blob'] = implode(' ', $searchBlobParts);

        $postsData[] = $postData;
    }

    $fileName = 'posts-' . ($chunkIndex + 1) . '.json';
    file_put_contents(OUTPUT_DIR . '/' . $fileName, json_encode($postsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $postFiles[] = $fileName;
    echo "  - $fileName (" . count($postsData) . " posts)\n";
}

// ---- Generar los 3 JSON de filtros ----
echo "Generando índices de filtros...\n";

foreach ($FILTER_GROUPS as $group => $slugs) {
    $index = [];
    foreach ($slugs as $slug) {
        $sql = "SELECT t.name, t.slug, tt.count
                FROM " . DB_PREFIX . "terms t
                JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id
                WHERE t.slug = ? AND tt.taxonomy = 'category'";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $term = $res->fetch_assoc();

        if (!$term) {
            echo "  [WARN] Categoría no encontrada: $slug\n";
            continue;
        }

        // Obtener IDs de posts
        $sql2 = "SELECT tr.object_id FROM " . DB_PREFIX . "term_relationships tr
                 JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 WHERE tt.term_id = (SELECT term_id FROM " . DB_PREFIX . "terms WHERE slug = ? LIMIT 1)";
        $stmt2 = $mysqli->prepare($sql2);
        $stmt2->bind_param('s', $slug);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $ids = [];
        while ($row = $res2->fetch_assoc()) {
            $ids[] = (int)$row['object_id'];
        }

        $index[$slug] = [
            'name' => $term['name'],
            'total_posts' => (int)$term['count'],
            'ids' => $ids,
        ];
    }

    $fileName = 'material-' . str_replace('_', '-', $group) . '.json';
    file_put_contents(OUTPUT_DIR . '/' . $fileName, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "  - $fileName (" . count($index) . " términos)\n";
}

// ---- Generar index.json ----
$index = [
    'version' => '1.0',
    'generated_at' => date('c'),
    'source' => [
        'tag_slug' => 'material',
        'post_status' => 'publish',
    ],
    'counts' => [
        'posts' => count($posts),
        'area_tematica' => count($FILTER_GROUPS['area_tematica']),
        'nivel_educativo' => count($FILTER_GROUPS['nivel_educativo']),
        'destinatarios' => count($FILTER_GROUPS['destinatarios']),
    ],
    'files' => [
        'posts' => $postFiles,
        'area_tematica' => 'material-area-tematica.json',
        'nivel_educativo' => 'material-nivel-educativo.json',
        'destinatarios' => 'material-destinatarios.json',
    ],
];

file_put_contents(OUTPUT_DIR . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  - index.json\n";

echo "\n=== Exportación completa ===\n";
echo "Posts: " . count($posts) . "\n";
echo "Archivos de posts: " . count($postFiles) . "\n";
echo "Área Temática: " . count($FILTER_GROUPS['area_tematica']) . " términos\n";
echo "Nivel Educativo: " . count($FILTER_GROUPS['nivel_educativo']) . " términos\n";
echo "Destinatarios: " . count($FILTER_GROUPS['destinatarios']) . " términos\n";

$mysqli->close();