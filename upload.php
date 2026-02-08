<?php
/**
 * Upload endpoint - IMPROVED VERSION
 * Better article extraction with proper encoding
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/upload_errors.log');

ob_start();
session_start();

define('SOURCES_DIR', __DIR__ . '/sources');
define('MAX_UPLOAD_SIZE', 5242880);
define('ALLOWED_EXTENSIONS', ['md', 'html', 'htm']);

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$mode = $_POST['mode'] ?? 'content';
$filename = $_POST['filename'] ?? '';
$content = $_POST['content'] ?? '';
$url = $_POST['url'] ?? '';

$originalFilename = $filename;
$filename = basename($filename);
$filename = preg_replace('/[^a-z0-9äöüß\-_\.]/i', '_', $filename);
$filename = trim($filename, '_');

if (empty($filename)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Filename required']);
    exit;
}

if (!preg_match('/\.(md|html|htm)$/i', $filename)) {
    $filename .= '.md';
}

if (!is_dir(SOURCES_DIR)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Sources directory does not exist']);
    exit;
}

$targetPath = SOURCES_DIR . '/' . $filename;
if (file_exists($targetPath)) {
    ob_end_clean();
    http_response_code(409);
    echo json_encode(['error' => 'File already exists: ' . $filename]);
    exit;
}

if ($mode === 'url' && !empty($url)) {
    $validatedUrl = validateAndSanitizeUrl($url);
    
    if (!$validatedUrl['valid']) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => $validatedUrl['error']]);
        exit;
    }
    
    $fetchResult = safeFetchUrl($validatedUrl['url']);
    
    if (!$fetchResult['success']) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => $fetchResult['error']]);
        exit;
    }
    
    $htmlContent = $fetchResult['content'];
    
    // Extract article
    $article = extractArticleReadability($htmlContent, $url);
    
    if (!$article) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to extract article']);
        exit;
    }
    
    // Build markdown with proper encoding
    $content = "# " . $article['title'] . "\n\n";
    
    if (!empty($article['byline'])) {
        $content .= "*" . $article['byline'] . "*\n\n";
    }
    
    $content .= "**Source:** " . $url . "\n\n";
    
    if (!empty($article['excerpt'])) {
        $content .= "> " . $article['excerpt'] . "\n\n";
    }
    
    $content .= "---\n\n";
    $content .= htmlToMarkdown($article['content']);
}

if (empty($content)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Content required']);
    exit;
}

if (strlen($content) > MAX_UPLOAD_SIZE) {
    ob_end_clean();
    http_response_code(413);
    echo json_encode(['error' => 'Content too large']);
    exit;
}

$result = @file_put_contents($targetPath, $content);

if ($result === false) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'filename' => $filename,
    'size' => $result,
    'url' => '?doc=' . urlencode($filename)
], JSON_UNESCAPED_UNICODE);
exit;

function validateAndSanitizeUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['valid' => false, 'error' => 'Invalid URL format'];
    }
    
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return ['valid' => false, 'error' => 'Invalid URL structure'];
    }
    
    if (!in_array(strtolower($parsed['scheme']), ['http', 'https'])) {
        return ['valid' => false, 'error' => 'Only HTTP/HTTPS allowed'];
    }
    
    $host = strtolower($parsed['host']);
    $blocked_hosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
    if (in_array($host, $blocked_hosts)) {
        return ['valid' => false, 'error' => 'Cannot access localhost'];
    }
    
    $blocked_tlds = ['.local', '.localhost', '.internal', '.lan', '.home', '.corp'];
    foreach ($blocked_tlds as $tld) {
        if (substr($host, -strlen($tld)) === $tld) {
            return ['valid' => false, 'error' => 'Cannot access internal domains'];
        }
    }
    
    $ip = @gethostbyname($host);
    if ($ip !== $host) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['valid' => false, 'error' => 'Cannot access private IPs'];
        }
        
        if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|192\.168\.|169\.254\.|127\.)/', $ip)) {
            return ['valid' => false, 'error' => 'Cannot access private IP ranges'];
        }
    }
    
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['valid' => false, 'error' => 'Cannot access private IPs'];
        }
    }
    
    if (isset($parsed['user']) || isset($parsed['pass'])) {
        return ['valid' => false, 'error' => 'URLs with credentials not allowed'];
    }
    
    return ['valid' => true, 'url' => $url];
}

function safeFetchUrl($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'max_redirects' => 3,
            'follow_location' => 1,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    
    if ($content === false) {
        return ['success' => false, 'content' => null, 'error' => 'Failed to fetch URL'];
    }
    
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^Location:\s*(.+)$/i', $header, $matches)) {
                $redirectUrl = trim($matches[1]);
                $validated = validateAndSanitizeUrl($redirectUrl);
                if (!$validated['valid']) {
                    return ['success' => false, 'content' => null, 'error' => 'Blocked redirect'];
                }
            }
        }
    }
    
    if (strlen($content) > MAX_UPLOAD_SIZE * 2) {
        return ['success' => false, 'content' => null, 'error' => 'Content too large'];
    }
    
    return ['success' => true, 'content' => $content];
}

function extractArticleReadability($html, $url) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    // IMPROVED: Better encoding detection and handling
    $encoding = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $html = mb_convert_encoding($html, 'UTF-8', $encoding);
    }
    
    // Don't double-encode if already UTF-8
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    }
    
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Extract title
    $title = 'Untitled';
    $titleNodes = $xpath->query('//title');
    if ($titleNodes->length > 0) {
        $title = trim($titleNodes->item(0)->textContent);
        $title = preg_replace('/ [-|–—] .*$/', '', $title);
        $title = preg_replace('/ \| .*$/', '', $title);
    }
    
    if (strlen($title) < 5) {
        $ogTitle = $xpath->query('//meta[@property="og:title"]/@content');
        if ($ogTitle->length > 0) {
            $title = trim($ogTitle->item(0)->textContent);
        } else {
            $h1 = $xpath->query('//h1');
            if ($h1->length > 0) {
                $title = trim($h1->item(0)->textContent);
            }
        }
    }
    
    // Extract author
    $byline = null;
    $authorQueries = [
        '//meta[@name="author"]/@content',
        '//meta[@property="article:author"]/@content',
        '//*[contains(@class, "author") and not(contains(@class, "related"))]',
        '//*[contains(@class, "byline")]',
    ];
    
    foreach ($authorQueries as $query) {
        $result = $xpath->query($query);
        if ($result->length > 0) {
            $byline = trim($result->item(0)->textContent);
            if (strlen($byline) > 3 && strlen($byline) < 100) {
                break;
            }
        }
    }
    
    // Extract description
    $excerpt = null;
    $descQueries = [
        '//meta[@property="og:description"]/@content',
        '//meta[@name="description"]/@content',
    ];
    
    foreach ($descQueries as $query) {
        $result = $xpath->query($query);
        if ($result->length > 0) {
            $excerpt = trim($result->item(0)->textContent);
            if (strlen($excerpt) > 10) {
                break;
            }
        }
    }
    
    // Find content - improved selectors
    $contentNode = null;
    
    $articles = $xpath->query('//article[not(contains(@class, "teaser")) and not(contains(@class, "related"))]');
    if ($articles->length > 0) {
        $contentNode = $articles->item(0);
    }
    
    if (!$contentNode) {
        $mains = $xpath->query('//main');
        if ($mains->length > 0) {
            $contentNode = $mains->item(0);
        }
    }
    
    if (!$contentNode) {
        $contentQueries = [
            '//*[@id="mw-content-text"]',
            '//*[contains(@class, "article-content") and not(contains(@class, "related"))]',
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "article-body")]',
        ];
        
        foreach ($contentQueries as $query) {
            $result = $xpath->query($query);
            if ($result->length > 0) {
                $contentNode = $result->item(0);
                break;
            }
        }
    }
    
    if (!$contentNode) {
        $contentNode = findBestContentNode($xpath);
    }
    
    if (!$contentNode) {
        return null;
    }
    
    // IMPROVED: Better cleaning
    cleanNode($contentNode, $xpath);
    $contentHtml = getInnerHTML($contentNode);
    
    return [
        'title' => $title,
        'byline' => $byline,
        'excerpt' => $excerpt,
        'content' => $contentHtml
    ];
}

function findBestContentNode($xpath) {
    $candidates = $xpath->query('//div | //article | //section');
    $bestScore = 0;
    $bestNode = null;
    
    foreach ($candidates as $node) {
        $class = $node->getAttribute('class');
        $id = $node->getAttribute('id');
        
        // Skip navigation, sidebar, footer, etc.
        if (preg_match('/nav|sidebar|footer|header|menu|comment|ad|promo|related|teaser/i', $class . ' ' . $id)) {
            continue;
        }
        
        $score = 0;
        $textLength = strlen(trim($node->textContent));
        
        if ($textLength < 200) continue;
        
        $score += min($textLength / 100, 50);
        
        if (preg_match('/article|content|post|entry|main|body/i', $class . ' ' . $id)) {
            $score += 25;
        }
        
        $xpath2 = new DOMXPath($node->ownerDocument);
        $paragraphs = $xpath2->query('.//p', $node);
        $score += $paragraphs->length * 3;
        
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestNode = $node;
        }
    }
    
    return $bestNode;
}

function cleanNode($node, $xpath) {
    // IMPROVED: More aggressive cleaning
    $unwantedSelectors = [
        './/nav',
        './/aside',
        './/footer',
        './/header[not(ancestor::article)]',
        './/*[contains(@class, "advertisement")]',
        './/*[contains(@class, "ad-")]',
        './/*[contains(@class, "sidebar")]',
        './/*[contains(@class, "related")]',
        './/*[contains(@class, "teaser")]',
        './/*[contains(@class, "comments")]',
        './/*[contains(@class, "social")]',
        './/*[contains(@class, "share")]',
        './/*[contains(@class, "navigation")]',
        './/*[contains(@class, "meta")]',
        './/*[contains(@id, "comments")]',
        './/*[contains(@id, "related")]',
        './/*[@role="navigation"]',
        './/script',
        './/style',
        './/noscript',
        './/iframe[not(contains(@src, "youtube") or contains(@src, "vimeo"))]',
        './/form',
        './/button',
    ];
    
    foreach ($unwantedSelectors as $selector) {
        $elements = $xpath->query($selector, $node);
        foreach ($elements as $el) {
            if ($el->parentNode) {
                $el->parentNode->removeChild($el);
            }
        }
    }
}

function getInnerHTML($node) {
    $innerHTML = '';
    foreach ($node->childNodes as $child) {
        $innerHTML .= $node->ownerDocument->saveHTML($child);
    }
    return $innerHTML;
}

function htmlToMarkdown($html) {
    // IMPROVED: Better whitespace and formatting
    
    // Remove scripts and styles
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    
    // Convert headings
    $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n\n# $1\n\n", $html);
    $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n\n## $1\n\n", $html);
    $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n\n### $1\n\n", $html);
    $html = preg_replace('/<h4[^>]*>(.*?)<\/h4>/is', "\n\n#### $1\n\n", $html);
    
    // Convert formatting
    $html = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $html);
    $html = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '**$1**', $html);
    $html = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '*$1*', $html);
    $html = preg_replace('/<i[^>]*>(.*?)<\/i>/is', '*$1*', $html);
    
    // Convert links
    $html = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $html);
    
    // Convert lists
    $html = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $html);
    $html = preg_replace('/<\/?[uo]l[^>]*>/is', "\n", $html);
    
    // Convert code
    $html = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html);
    
    // Convert blockquotes
    $html = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n", $html);
    
    // Convert line breaks and paragraphs
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/p>/i', "\n\n", $html);
    $html = preg_replace('/<p[^>]*>/i', '', $html);
    
    // Strip remaining tags
    $html = strip_tags($html);
    
    // Decode entities PROPERLY
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // IMPROVED: Clean up excessive whitespace but preserve structure
    $html = preg_replace('/[ \t]+/', ' ', $html);  // Multiple spaces to single
    $html = preg_replace('/\n[ \t]+/', "\n", $html);  // Remove spaces after newlines
    $html = preg_replace('/[ \t]+\n/', "\n", $html);  // Remove spaces before newlines
    $html = preg_replace('/\n{4,}/', "\n\n\n", $html);  // Max 2 blank lines
    
    // Trim each line
    $lines = explode("\n", $html);
    $lines = array_map('trim', $lines);
    $html = implode("\n", $lines);
    
    // Remove lines that are just punctuation or very short
    $lines = explode("\n", $html);
    $lines = array_filter($lines, function($line) {
        $line = trim($line);
        // Keep empty lines for spacing
        if ($line === '') return true;
        // Remove lines with only punctuation/whitespace
        if (strlen($line) < 3 && !preg_match('/[a-zA-Z0-9äöüÄÖÜß]/', $line)) return false;
        return true;
    });
    $html = implode("\n", $lines);
    
    return trim($html);
}
