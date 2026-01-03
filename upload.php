<?php
/**
 * Upload endpoint for document viewer
 * Uses PHP-Readability for article extraction
 * Session-based authentication
 */

// Start session for authentication
session_start();

define('SOURCES_DIR', __DIR__ . '/sources');
define('MAX_UPLOAD_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['md', 'html', 'htm']);

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$mode = $_POST['mode'] ?? 'content';
$filename = $_POST['filename'] ?? '';
$content = $_POST['content'] ?? '';
$url = $_POST['url'] ?? '';

// Validate filename
$originalFilename = $filename;
$filename = basename($filename);
$filename = preg_replace('/[^a-z0-9äöüß\-_\.]/i', '_', $filename);
$filename = trim($filename, '_'); // Remove leading/trailing underscores

if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'Filename required. Original filename "' . $originalFilename . '" contains only invalid characters. Use only: a-z, 0-9, äöüß, -, _, .']);
    exit;
}


// Ensure .md extension
if (!preg_match('/\.(md|html|htm)$/i', $filename)) {
    $filename .= '.md';
}

// Check if file exists
$targetPath = SOURCES_DIR . '/' . $filename;
if (file_exists($targetPath)) {
    http_response_code(409);
    echo json_encode(['error' => 'File already exists: ' . $filename]);
    exit;
}

// Handle URL mode
if ($mode === 'url' && !empty($url)) {
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid URL']);
        exit;
    }
    
    // Fetch URL content
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    $htmlContent = @file_get_contents($url, false, $context);
    
    if ($htmlContent === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch URL']);
        exit;
    }
    
    // Extract article using Readability
    $article = extractArticleReadability($htmlContent, $url);
    
    if (!$article) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to extract article']);
        exit;
    }
    
    // Build markdown content
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

// Validate content
if (empty($content)) {
    http_response_code(400);
    echo json_encode(['error' => 'Content required']);
    exit;
}

if (strlen($content) > MAX_UPLOAD_SIZE) {
    http_response_code(413);
    echo json_encode(['error' => 'Content too large']);
    exit;
}

// Save file
$result = file_put_contents($targetPath, $content);

if ($result === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

echo json_encode([
    'success' => true,
    'filename' => $filename,
    'size' => $result,
    'url' => '?doc=' . urlencode($filename)
]);

/**
 * Extract article using Readability-like algorithm
 */
function extractArticleReadability($html, $url) {
    // Load HTML with DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    // Fix encoding issues
    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Extract title
    $title = 'Untitled';
    $titleNodes = $xpath->query('//title');
    if ($titleNodes->length > 0) {
        $title = trim($titleNodes->item(0)->textContent);
        // Clean up title
        $title = preg_replace('/ [-|–—] .*$/', '', $title);
        $title = preg_replace('/ \| .*$/', '', $title);
    }
    
    // Try to find title from og:title or h1 if title is generic
    if (strlen($title) < 5 || stripos($title, 'untitled') !== false) {
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
    
    // Extract byline/author
    $byline = null;
    $authorQueries = [
        '//meta[@name="author"]/@content',
        '//meta[@property="article:author"]/@content',
        '//*[contains(@class, "author")]',
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
    
    // Extract excerpt/description
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
    
    // Find main content - try multiple strategies
    $contentNode = null;
    
    // Strategy 1: Look for article tag
    $articles = $xpath->query('//article');
    if ($articles->length > 0) {
        $contentNode = $articles->item(0);
    }
    
    // Strategy 2: Look for main tag
    if (!$contentNode) {
        $mains = $xpath->query('//main');
        if ($mains->length > 0) {
            $contentNode = $mains->item(0);
        }
    }
    
    // Strategy 3: Look for common content containers
    if (!$contentNode) {
        $contentQueries = [
            '//*[@id="mw-content-text"]', // Wikipedia
            '//*[contains(@class, "article-content")]',
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "content-body")]',
            '//*[contains(@class, "article-body")]',
            '//*[@id="content"]//div[contains(@class, "content")]',
        ];
        
        foreach ($contentQueries as $query) {
            $result = $xpath->query($query);
            if ($result->length > 0) {
                $contentNode = $result->item(0);
                break;
            }
        }
    }
    
    // Strategy 4: Score all divs and find best candidate
    if (!$contentNode) {
        $contentNode = findBestContentNode($xpath);
    }
    
    if (!$contentNode) {
        return null;
    }
    
    // Clean the content node
    cleanNode($contentNode, $xpath);
    
    // Get the HTML content
    $contentHtml = getInnerHTML($contentNode);
    
    return [
        'title' => $title,
        'byline' => $byline,
        'excerpt' => $excerpt,
        'content' => $contentHtml
    ];
}

/**
 * Find the best content node using scoring algorithm
 */
function findBestContentNode($xpath) {
    $candidates = $xpath->query('//div | //article | //section');
    $bestScore = 0;
    $bestNode = null;
    
    foreach ($candidates as $node) {
        $score = 0;
        $textLength = strlen(trim($node->textContent));
        
        // Skip if too short
        if ($textLength < 200) continue;
        
        // Base score on text length
        $score += min($textLength / 100, 50);
        
        // Positive signals
        $class = $node->getAttribute('class');
        $id = $node->getAttribute('id');
        
        if (preg_match('/article|content|post|entry|main|body/i', $class . ' ' . $id)) {
            $score += 25;
        }
        
        // Count paragraphs (good signal)
        $xpath2 = new DOMXPath($node->ownerDocument);
        $paragraphs = $xpath2->query('.//p', $node);
        $score += $paragraphs->length * 3;
        
        // Negative signals
        if (preg_match('/comment|sidebar|nav|menu|footer|header|ad|promo/i', $class . ' ' . $id)) {
            $score -= 50;
        }
        
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestNode = $node;
        }
    }
    
    return $bestNode;
}

/**
 * Clean unwanted elements from node
 */
function cleanNode($node, $xpath) {
    $unwantedSelectors = [
        './/nav',
        './/aside',
        './/footer',
        './/header[not(ancestor::article)]',
        './/*[contains(@class, "advertisement")]',
        './/*[contains(@class, "sidebar")]',
        './/*[contains(@class, "related")]',
        './/*[contains(@class, "comments")]',
        './/*[contains(@class, "social")]',
        './/*[contains(@id, "comments")]',
        './/script',
        './/style',
        './/noscript',
        './/iframe[not(contains(@src, "youtube") or contains(@src, "vimeo"))]',
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

/**
 * Get inner HTML of a node
 */
function getInnerHTML($node) {
    $innerHTML = '';
    foreach ($node->childNodes as $child) {
        $innerHTML .= $node->ownerDocument->saveHTML($child);
    }
    return $innerHTML;
}

/**
 * Convert HTML to Markdown
 */
/**
 * Convert HTML to Markdown
 */
function htmlToMarkdown($html) {
    // Pre-process: protect code blocks
    $codeBlocks = [];
    $html = preg_replace_callback('/<pre[^>]*>(.*?)<\/pre>/is', function($matches) use (&$codeBlocks) {
        $id = '___CODE_BLOCK_' . count($codeBlocks) . '___';
        $codeBlocks[$id] = $matches[1];
        return $id;
    }, $html);
    
// Convert tables to markdown BEFORE other processing
$html = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function($matches) {
    $tableContent = $matches[1];
    $markdown = "\n\n";
    
    $allRows = [];
    $headerRowCount = 0;
    
    // Check for thead
    if (preg_match('/<thead[^>]*>(.*?)<\/thead>/is', $tableContent, $theadMatch)) {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $theadMatch[1], $headerRowMatches);
        foreach ($headerRowMatches[1] as $rowContent) {
            $cells = [];
            preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $rowContent, $cellMatches);
            foreach ($cellMatches[1] as $cell) {
                $cells[] = cleanTableCell($cell);
            }
            if (!empty($cells)) {
                $allRows[] = $cells;
                $headerRowCount++;
            }
        }
    }
    
    // Get tbody or all tr elements
    $bodyContent = $tableContent;
    if (preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $tableContent, $tbodyMatch)) {
        $bodyContent = $tbodyMatch[1];
    }
    
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $bodyContent, $rowMatches);
    foreach ($rowMatches[1] as $rowContent) {
        $cells = [];
        preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $rowContent, $cellMatches);
        foreach ($cellMatches[1] as $cell) {
            $cells[] = cleanTableCell($cell);
        }
        if (!empty($cells)) {
            $allRows[] = $cells;
        }
    }
    
    if (empty($allRows)) {
        return '';
    }
    
    // Ensure all rows have same number of columns
    $maxCols = max(array_map('count', $allRows));
    foreach ($allRows as &$row) {
        while (count($row) < $maxCols) {
            $row[] = '';
        }
    }
    
    // If no explicit header, treat first row as header
    if ($headerRowCount === 0) {
        $headerRowCount = 1;
    }
    
    // Output table
    $rowIndex = 0;
    foreach ($allRows as $row) {
        $markdown .= '| ' . implode(' | ', $row) . ' |' . "\n";
        
        // Add separator after header rows
        if ($rowIndex === $headerRowCount - 1) {
            $markdown .= '|' . str_repeat(' --- |', count($row)) . "\n";
        }
        $rowIndex++;
    }
    
    return $markdown . "\n";
}, $html);

// Helper function for cleaning table cells
function cleanTableCell($cell) {
    // Remove nested tables
    $cell = preg_replace('/<table[^>]*>.*?<\/table>/is', '', $cell);
    
    // Remove sup/sub references like [1], [2]
    $cell = preg_replace('/<sup[^>]*>.*?<\/sup>/is', '', $cell);
    $cell = preg_replace('/<sub[^>]*>.*?<\/sub>/is', '', $cell);
    
    // Convert br to space
    $cell = preg_replace('/<br\s*\/?>/i', ' ', $cell);
    
    // Strip remaining tags
    $cell = strip_tags($cell);
    
    // Decode entities
    $cell = html_entity_decode($cell, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Clean whitespace
    $cell = preg_replace('/\s+/', ' ', $cell);
    $cell = trim($cell);
    
    // Escape pipe characters
    $cell = str_replace('|', '\\|', $cell);
    
    return $cell;
}
    
    // Convert headings
    $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n\n# $1\n\n", $html);
    $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n\n## $1\n\n", $html);
    $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n\n### $1\n\n", $html);
    $html = preg_replace('/<h4[^>]*>(.*?)<\/h4>/is', "\n\n#### $1\n\n", $html);
    $html = preg_replace('/<h5[^>]*>(.*?)<\/h5>/is', "\n\n##### $1\n\n", $html);
    $html = preg_replace('/<h6[^>]*>(.*?)<\/h6>/is', "\n\n###### $1\n\n", $html);
    
    // Convert formatting
    $html = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $html);
    $html = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '**$1**', $html);
    $html = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '*$1*', $html);
    $html = preg_replace('/<i[^>]*>(.*?)<\/i>/is', '*$1*', $html);
    
    // Convert links
    $html = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $html);
    
    // Convert lists
    $html = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "* $1\n", $html);
    $html = preg_replace('/<\/?ul[^>]*>/is', "\n", $html);
    $html = preg_replace('/<\/?ol[^>]*>/is', "\n", $html);
    
    // Convert inline code
    $html = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html);
    
    // Convert blockquotes
    $html = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n", $html);
    
    // Convert line breaks and paragraphs
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/p>/i', "\n\n", $html);
    $html = preg_replace('/<p[^>]*>/i', '', $html);
    
    // Convert divs to paragraph breaks
    $html = preg_replace('/<\/div>/i', "\n", $html);
    $html = preg_replace('/<div[^>]*>/i', '', $html);
    
    // Strip all remaining HTML tags
    $html = strip_tags($html);
    
    // Decode HTML entities
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Restore code blocks
    foreach ($codeBlocks as $id => $code) {
        $code = strip_tags($code);
        $code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace($id, "\n\n```\n" . trim($code) . "\n```\n\n", $html);
    }
    

// Clean up whitespace
$html = preg_replace('/\n{3,}/', "\n\n", $html);  // Keep this
$html = preg_replace('/ +/', ' ', $html);  // Keep this

// Trim each line BUT preserve empty lines for paragraph breaks
$lines = explode("\n", $html);
$lines = array_map(function($line) {
    // Only trim non-empty lines
    return trim($line) === '' ? '' : trim($line);
}, $lines);
$html = implode("\n", $lines);

return trim($html);

}
