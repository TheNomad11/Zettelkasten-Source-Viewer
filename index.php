<?php
/**
 * Zettelkasten Source Viewer
 * A minimal document viewer for HTML and Markdown files
 * Optimized for hundreds/thousands of documents
 *
 * @version 3.0
 * @author Zettelkasten Source Viewer
 */

// Start session
session_start();

// Configuration - CHANGE THIS PASSWORD!
define('AUTH_PASSWORD_HASH', password_hash('your-password', PASSWORD_DEFAULT));

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle login
if (isset($_POST['login_password'])) {
    if (password_verify($_POST['login_password'], AUTH_PASSWORD_HASH)) {
        $_SESSION['authenticated'] = true;
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Invalid password';
    }
}

// Check authentication
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    // Show login page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Zettelkasten Sources</title>
        <link rel="stylesheet" href="style.css">
        <style>
            .login-container {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                background: var(--bg-secondary);
            }
            .login-box {
                background: var(--bg-main);
                padding: 40px;
                border-radius: 12px;
                box-shadow: var(--shadow-lg);
                width: 100%;
                max-width: 400px;
            }
            .login-box h1 {
                margin-bottom: 8px;
                font-size: 1.8rem;
            }
            .login-box p {
                color: var(--text-secondary);
                margin-bottom: 24px;
            }
            .login-form input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 1px solid var(--border);
                border-radius: 8px;
                font-size: 14px;
                margin-bottom: 16px;
            }
            .login-form input[type="password"]:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            }
            .login-form button {
                width: 100%;
                padding: 12px;
                background: var(--primary);
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }
            .login-form button:hover {
                background: var(--primary-hover);
            }
            .login-error {
                background: #fee;
                color: #c33;
                padding: 12px;
                border-radius: 6px;
                margin-bottom: 16px;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-box">
                <h1>🔒 Login</h1>
                <p>Enter password to access your documents</p>

                <?php if (isset($login_error)): ?>
                    <div class="login-error"><?php echo htmlspecialchars($login_error); ?></div>
                <?php endif; ?>

                <form method="post" class="login-form">
                    <input
                        type="password"
                        name="login_password"
                        placeholder="Password"
                        required
                        autofocus
                    >
                    <button type="submit">Login</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Configuration
define('SOURCES_DIR', __DIR__ . '/sources');
define('CACHE_DIR', __DIR__ . '/cache');
define('IMAGES_DIR', SOURCES_DIR . '/images'); // Image subdirectory
define('INDEX_FILE', CACHE_DIR . '/documents.json');
define('ERROR_LOG_FILE', CACHE_DIR . '/errors.log');
define('CACHE_TTL', 3600); // Cache time-to-live in seconds (1 hour)
define('ALLOWED_EXTENSIONS', ['md', 'html', 'htm']);
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
define('DOCS_PER_PAGE', 50); // Pagination
define('MAX_SEARCH_LENGTH', 200); // Maximum search query length
define('MAX_FILE_SIZE', 10485760); // Maximum file size to read (10MB)
define('MAX_IMAGE_SIZE', 5242880); // Maximum image size (5MB)

// Initialize error tracking
$errors = [];

/**
 * Log error to file
 *
 * @param string $message Error message
 * @param string $level Error level (ERROR, WARNING, INFO)
 * @return void
 */
function logError($message, $level = 'ERROR') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";

    // Ensure cache directory exists
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }

    @file_put_contents(ERROR_LOG_FILE, $logMessage, FILE_APPEND);
}

/**
 * Validate and sanitize file path
 * Prevents directory traversal attacks
 * Supports UTF-8 characters (umlauts, accents, etc.)
 *
 * @param string $path User-provided path
 * @return string|null Sanitized path or null if invalid
 */
function validatePath($path) {
    if (empty($path)) {
        return null;
    }

    // Remove null bytes
    $path = str_replace("\0", '', $path);

    // Remove leading/trailing whitespace
    $path = trim($path);

    // Check for path traversal attempts
    if (strpos($path, '..') !== false) {
        logError("Path traversal attempt detected: {$path}", 'WARNING');
        return null;
    }

    // Check for absolute paths
    if (strpos($path, '/') === 0 || strpos($path, '\\') === 0 || preg_match('/^[a-zA-Z]:/', $path)) {
        logError("Absolute path attempt detected: {$path}", 'WARNING');
        return null;
    }

    // Only remove truly dangerous characters, keep UTF-8 characters
    // Remove: null bytes, control characters, but keep letters, numbers, spaces, and common punctuation
    $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);

    return $path;
}

/**
 * Validate search query
 *
 * @param string $query Search query
 * @return string|null Sanitized query or null if invalid
 */
function validateSearchQuery($query) {
    if (empty($query)) {
        return null;
    }

    // Remove null bytes
    $query = str_replace("\0", '', $query);

    // Trim whitespace
    $query = trim($query);

    // Check length
    if (strlen($query) > MAX_SEARCH_LENGTH) {
        logError("Search query too long: " . strlen($query) . " characters", 'WARNING');
        return substr($query, 0, MAX_SEARCH_LENGTH);
    }

    return $query;
}

/**
 * Check if directory is readable and writable
 *
 * @param string $dir Directory path
 * @param bool $needsWrite Whether write access is needed
 * @return bool True if accessible
 */
function checkDirectoryAccess($dir, $needsWrite = false) {
    if (!file_exists($dir)) {
        if ($needsWrite) {
            // Try to create directory
            if (!@mkdir($dir, 0755, true)) {
                logError("Failed to create directory: {$dir}", 'ERROR');
                return false;
            }
        } else {
            logError("Directory does not exist: {$dir}", 'ERROR');
            return false;
        }
    }

    if (!is_dir($dir)) {
        logError("Path is not a directory: {$dir}", 'ERROR');
        return false;
    }

    if (!is_readable($dir)) {
        logError("Directory is not readable: {$dir}", 'ERROR');
        return false;
    }

    if ($needsWrite && !is_writable($dir)) {
        logError("Directory is not writable: {$dir}", 'ERROR');
        return false;
    }

    return true;
}

/**
 * Parse Markdown to HTML using Parsedown library
 * Falls back to basic parsing if Parsedown not available
 * @param string $text Markdown text
 * @return string HTML output
 */
function parseMarkdown($text) {
    // Try to use Parsedown if available
    if (file_exists(__DIR__ . '/Parsedown.php')) {
        require_once __DIR__ . '/Parsedown.php';

        $Parsedown = new Parsedown();
        $Parsedown->setSafeMode(true); // Escapes HTML for security

        // Process images to only allow internal ones
        $html = $Parsedown->text($text);

        // Post-process to secure images - only allow images from images/ folder
        $html = preg_replace_callback('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', function($matches) {
            $fullTag = $matches[0];
            $src = $matches[1];

            // Only allow images from images subfolder
            if (strpos($src, 'images/') === 0) {
                $safeSrc = validatePath($src);
                if ($safeSrc) {
                    // Rebuild image tag with validated path
                    preg_match('/alt=["\']([^"\']*)["\']/', $fullTag, $altMatch);
                    $alt = isset($altMatch[1]) ? $altMatch[1] : '';
                    return '<img src="?img=' . urlencode($safeSrc) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
                }
            }
            // Remove external/invalid images
            return '';
        }, $html);

        return $html;
    }

    // Fallback to basic parsing if Parsedown not available
    return parseMarkdownBasic($text);
}

/**
 * Basic fallback markdown parser
 * @param string $text Markdown text
 * @return string HTML output
 */
function parseMarkdownBasic($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Headers
    $text = preg_replace('/^##### (.+)$/m', '<h5>$1</h5>', $text);
    $text = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);

    // Bold and italic
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);

    // Links
    $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2">$1</a>', $text);

    // Code
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);

    // Paragraphs
    $text = preg_replace('/\n\n/', '</p><p>', $text);
    $text = '<p>' . $text . '</p>';

    return $text;
}

/**
 * Get directory modification time (recursive)
 * Checks all subdirectories for changes
 *
 * @param string $dir Directory path
 * @return int Latest modification timestamp
 */
function getDirectoryMTime($dir) {
    if (!is_dir($dir)) {
        logError("Cannot get mtime for non-directory: {$dir}", 'WARNING');
        return 0;
    }

    $mtime = @filemtime($dir);
    if ($mtime === false) {
        logError("Cannot get modification time for: {$dir}", 'WARNING');
        return 0;
    }

    $items = @scandir($dir);
    if ($items === false) {
        logError("Cannot scan directory: {$dir}", 'WARNING');
        return $mtime;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $mtime = max($mtime, getDirectoryMTime($path));
        } else {
            $itemMtime = @filemtime($path);
            if ($itemMtime !== false) {
                $mtime = max($mtime, $itemMtime);
            }
        }
    }

    return $mtime;
}

/**
 * Check if index needs rebuilding
 * Compares directory modification time with index file time
 *
 * @return bool True if rebuild needed
 */
function indexNeedsRebuild() {
    if (!file_exists(INDEX_FILE)) {
        return true;
    }

    $indexMTime = @filemtime(INDEX_FILE);
    if ($indexMTime === false) {
        logError("Cannot read index file modification time", 'WARNING');
        return true;
    }

    $dirMTime = getDirectoryMTime(SOURCES_DIR);

    // Rebuild if directory changed or cache expired
    return ($dirMTime > $indexMTime) || (time() - $indexMTime > CACHE_TTL);
}

/**
 * Build document index
 * Scans all directories and creates a JSON index file
 *
 * @return array Array of document metadata
 */
function buildIndex() {
    // Ensure cache directory exists and is writable
    if (!checkDirectoryAccess(CACHE_DIR, true)) {
        logError("Cache directory not accessible, using in-memory index", 'WARNING');
        return scanDocumentsRecursive(SOURCES_DIR);
    }

    $documents = scanDocumentsRecursive(SOURCES_DIR);

    // Save to JSON file
    $jsonData = json_encode($documents, JSON_PRETTY_PRINT);
    if ($jsonData === false) {
        logError("Failed to encode documents to JSON", 'ERROR');
        return $documents;
    }

    $result = @file_put_contents(INDEX_FILE, $jsonData);
    if ($result === false) {
        logError("Failed to write index file: " . INDEX_FILE, 'ERROR');
    } else {
        logError("Index rebuilt successfully with " . count($documents) . " documents", 'INFO');
    }

    return $documents;
}

/**
 * Load documents from index or rebuild if necessary
 *
 * @return array Array of document metadata
 */
function loadDocuments() {
    // Check if sources directory exists and is readable
    if (!checkDirectoryAccess(SOURCES_DIR, false)) {
        global $errors;
        $errors[] = "Sources directory is not accessible. Please check permissions.";
        return [];
    }

    // Check if we need to rebuild index
    if (indexNeedsRebuild()) {
        return buildIndex();
    }

    // Load from cache
    $jsonData = @file_get_contents(INDEX_FILE);
    if ($jsonData === false) {
        logError("Failed to read index file, rebuilding", 'WARNING');
        return buildIndex();
    }

    $documents = json_decode($jsonData, true);
    if (!is_array($documents)) {
        logError("Invalid JSON in index file, rebuilding", 'WARNING');
        return buildIndex();
    }

    return $documents;
}

function extractTitle($path, $fallback) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (!in_array($ext, ['md', 'html', 'htm'])) {
        return $fallback;
    }

    // Read only first 2KB - titles are at the top
    $handle = @fopen($path, 'r');
    if (!$handle) return $fallback;

    $content = fread($handle, 2048);
    fclose($handle);

    if ($ext === 'md') {
        // Extract first markdown header: # Title
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            $title = trim($matches[1]);
            // Extract link text from Markdown links [text](url)
            $title = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $title);
            return trim($title);
        }
    } else {
        // HTML: try <title> first, then <h1>
        if (preg_match('/<title>(.+?)<\/title>/is', $content, $matches)) {
            return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES, 'UTF-8'));
        }
        if (preg_match('/<h1[^>]*>(.+?)<\/h1>/is', $content, $matches)) {
            return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES, 'UTF-8'));
        }
    }

    return $fallback;
}
/**
 * Recursively scan directory for allowed documents
 *
 * @param string $dir Directory to scan
 * @param string|null $baseDir Base directory for relative paths
 * @return array Array of document metadata
 */
function scanDocumentsRecursive($dir, $baseDir = null) {
    if ($baseDir === null) {
        $baseDir = $dir;
    }

    $documents = [];

    if (!is_dir($dir)) {
        logError("Cannot scan non-directory: {$dir}", 'WARNING');
        return $documents;
    }

    $items = @scandir($dir);
    if ($items === false) {
        logError("Failed to scan directory: {$dir}", 'ERROR');
        return $documents;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        // Check if accessible
        if (!is_readable($path)) {
            logError("File not readable: {$path}", 'WARNING');
            continue;
        }

        if (is_dir($path)) {
            $documents = array_merge($documents, scanDocumentsRecursive($path, $baseDir));
        } else {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, ALLOWED_EXTENSIONS)) {
                $fileSize = @filesize($path);
                $fileMtime = @filemtime($path);

                if ($fileSize === false || $fileMtime === false) {
                    logError("Cannot get file info: {$path}", 'WARNING');
                    continue;
                }

                // Skip files that are too large
                if ($fileSize > MAX_FILE_SIZE) {
                    logError("File too large, skipping: {$path} ({$fileSize} bytes)", 'WARNING');
                    continue;
                }

                $relativePath = substr($path, strlen($baseDir) + 1);
                $documents[] = [
                    'name' => pathinfo($item, PATHINFO_FILENAME),
                    'title' => extractTitle($path, pathinfo($item, PATHINFO_FILENAME)),
                    'path' => $relativePath,
                    'ext' => $ext,
                    'size' => $fileSize,
                    'modified' => $fileMtime
                ];
            }
        }
    }

   usort($documents, function($a, $b) {
    return $b['modified'] - $a['modified'];  // Newest first
});

    return $documents;
}

/**
 * Search documents by filename (fast, no content loading)
 *
 * @param array $documents Array of document metadata
 * @param string $query Search query
 * @return array Filtered documents
 */
function searchDocumentsByName($documents, $query) {
    if (empty($query)) {
        return $documents;
    }

    $query = strtolower($query);

    return array_filter($documents, function($doc) use ($query) {
        return stripos($doc['name'], $query) !== false;
    });
}

/**
 * Search documents by content (lazy - only when explicitly requested)
 *
 * @param array $documents Array of document metadata
 * @param string $query Search query
 * @return array Filtered documents
 */
function searchDocumentsByContent($documents, $query) {
    if (empty($query)) {
        return $documents;
    }

    $query = strtolower($query);
    $results = [];

    foreach ($documents as $doc) {
        // First check filename (fast)
        if (stripos($doc['name'], $query) !== false) {
            $results[] = $doc;
            continue;
        }

        // Then check content (slower)
        $fullPath = SOURCES_DIR . '/' . $doc['path'];

        // Security check
        $realPath = realpath($fullPath);
        $realSourcesDir = realpath(SOURCES_DIR);

        if (!$realPath || !$realSourcesDir || strpos($realPath, $realSourcesDir) !== 0) {
            logError("Path validation failed during search: {$fullPath}", 'WARNING');
            continue;
        }

        if (!is_readable($fullPath)) {
            logError("File not readable during search: {$fullPath}", 'WARNING');
            continue;
        }

        $content = @file_get_contents($fullPath);

        if ($content === false) {
            logError("Failed to read file during search: {$fullPath}", 'WARNING');
            continue;
        }

        if (stripos($content, $query) !== false) {
            $results[] = $doc;
        }
    }

    return $results;
}

/**
 * Serve an image file
 * Only serves images from sources/images/ directory
 *
 * @param string $imagePath Relative image path
 * @return void Outputs image or exits with error
 */
function serveImage($imagePath) {
    if (empty($imagePath)) {
        header('HTTP/1.0 404 Not Found');
        exit;
    }

    // Validate path
    $validatedPath = validatePath($imagePath);
    if ($validatedPath === null) {
        logError("Invalid image path requested: {$imagePath}", 'WARNING');
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    // Must be in images/ directory
    if (strpos($validatedPath, 'images/') !== 0) {
        logError("Image path outside images directory: {$imagePath}", 'WARNING');
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    $fullPath = SOURCES_DIR . '/' . $validatedPath;

    // Security: Ensure path is within SOURCES_DIR
    $realImagePath = realpath($fullPath);
    $realSourcesDir = realpath(SOURCES_DIR);

    if (!$realImagePath || !$realSourcesDir || strpos($realImagePath, $realSourcesDir) !== 0) {
        logError("Path traversal attempt for image: {$imagePath}", 'WARNING');
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    if (!file_exists($realImagePath)) {
        logError("Image not found: {$imagePath}", 'INFO');
        header('HTTP/1.0 404 Not Found');
        exit;
    }

    if (!is_file($realImagePath)) {
        logError("Image path is not a file: {$imagePath}", 'WARNING');
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    if (!is_readable($realImagePath)) {
        logError("Image not readable: {$imagePath}", 'WARNING');
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    // Check file size
    $fileSize = @filesize($realImagePath);
    if ($fileSize === false || $fileSize > MAX_IMAGE_SIZE) {
        logError("Image too large: {$imagePath}", 'WARNING');
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    // Validate extension
    $ext = strtolower(pathinfo($realImagePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_IMAGE_EXTENSIONS)) {
        logError("Invalid image extension: {$imagePath} (.{$ext})", 'WARNING');
        header('HTTP/1.0 403 Forbidden');
        exit;
    }

    // Set content type
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml'
    ];

    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

    // Output image
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: public, max-age=86400'); // Cache for 1 day

    readfile($realImagePath);
    exit;
}
/**
 * Includes multiple security checks
 *
 * @param string $docPath Relative document path
 * @return array|null Document data or null if not found/invalid
 */
function getDocumentContent($docPath) {
    if (empty($docPath)) {
        return null;
    }

    // Validate path
    $validatedPath = validatePath($docPath);
    if ($validatedPath === null) {
        logError("Invalid document path requested: {$docPath}", 'WARNING');
        return null;
    }

    $fullPath = SOURCES_DIR . '/' . $validatedPath;

    // Security: Ensure path is within SOURCES_DIR using realpath
    $realDocPath = realpath($fullPath);
    $realSourcesDir = realpath(SOURCES_DIR);

    if (!$realDocPath || !$realSourcesDir || strpos($realDocPath, $realSourcesDir) !== 0) {
        logError("Path traversal attempt or invalid path: {$docPath}", 'WARNING');
        return null;
    }

    if (!file_exists($realDocPath)) {
        logError("Document not found: {$docPath}", 'INFO');
        return null;
    }

    if (!is_file($realDocPath)) {
        logError("Path is not a file: {$docPath}", 'WARNING');
        return null;
    }

    if (!is_readable($realDocPath)) {
        logError("Document not readable: {$docPath}", 'WARNING');
        return null;
    }

    // Check file size
    $fileSize = @filesize($realDocPath);
    if ($fileSize === false) {
        logError("Cannot get file size: {$docPath}", 'ERROR');
        return null;
    }

    if ($fileSize > MAX_FILE_SIZE) {
        logError("Document too large: {$docPath} ({$fileSize} bytes)", 'WARNING');
        return null;
    }

    // Validate extension
    $ext = strtolower(pathinfo($realDocPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        logError("Invalid file extension: {$docPath} (.{$ext})", 'WARNING');
        return null;
    }

    // Read content
    $content = @file_get_contents($realDocPath);
    if ($content === false) {
        logError("Failed to read document: {$docPath}", 'ERROR');
        return null;
    }

    return [
        'content' => $content,
        'title' => pathinfo($realDocPath, PATHINFO_FILENAME),
        'ext' => $ext,
        'path' => $validatedPath
    ];
}

/**
 * Paginate array
 *
 * @param array $array Array to paginate
 * @param int $page Current page number
 * @param int $perPage Items per page
 * @return array Pagination data
 */
function paginateArray($array, $page, $perPage) {
    $total = count($array);
    $totalPages = max(1, ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($array, $offset, $perPage),
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages
    ];
}

// ============================================================================
// MAIN APPLICATION LOGIC
// ============================================================================

// Handle image serving (must be before any HTML output)
if (isset($_GET['img'])) {
    serveImage($_GET['img']);
    exit;
}

// Initialize documents
$documents = loadDocuments();

// Validate and sanitize inputs
$searchQuery = isset($_GET['q']) ? validateSearchQuery($_GET['q']) : '';
$searchContent = isset($_GET['deep']) && $_GET['deep'] === '1';
$viewDoc = isset($_GET['doc']) ? validatePath($_GET['doc']) : '';
$page = isset($_GET['page']) ? max(1, min(9999, intval($_GET['page']))) : 1;

// Handle index rebuild request
if (isset($_GET['rebuild']) && $_GET['rebuild'] === '1') {
    $documents = buildIndex();
    $redirectParams = array_filter([
        'q' => $searchQuery,
        'doc' => $viewDoc,
        'deep' => $searchContent ? '1' : null
    ]);
    header('Location: ?' . http_build_query($redirectParams));
    exit;
}

// Apply search filter
$filteredDocuments = $documents;
if (!empty($searchQuery)) {
    if ($searchContent) {
        // Deep search (content) - slower
        $filteredDocuments = searchDocumentsByContent($documents, $searchQuery);
    } else {
        // Fast search (filename only)
        $filteredDocuments = searchDocumentsByName($documents, $searchQuery);
    }
}

// Paginate results
$pagination = paginateArray($filteredDocuments, $page, DOCS_PER_PAGE);

// Load document if requested (lazy - only when viewing)
$doc = null;
$docError = null;
if (!empty($viewDoc)) {
    $doc = getDocumentContent($viewDoc);
    if ($doc && $doc['ext'] === 'md') {
        $doc['content'] = parseMarkdown($doc['content']);
    }

    if ($doc === null) {
        // Provide detailed error information
        $fullPath = SOURCES_DIR . '/' . $viewDoc;
        $realPath = realpath($fullPath);

        if (!file_exists($fullPath)) {
            $docError = "File does not exist: " . htmlspecialchars($viewDoc);
        } elseif (!is_readable($fullPath)) {
            $docError = "File is not readable. Check permissions: " . htmlspecialchars($viewDoc);
            $perms = substr(sprintf('%o', fileperms($fullPath)), -4);
            $docError .= " (Current permissions: " . $perms . ")";
        } elseif (!$realPath) {
            $docError = "Cannot resolve real path (broken symlink?): " . htmlspecialchars($viewDoc);
        } else {
            $docError = "Document could not be loaded: " . htmlspecialchars($viewDoc);
        }

        $errors[] = $docError;
        logError($docError, 'ERROR');
    }
}

// Check if this is a mobile search submission
$isMobileSearch = isset($_GET['mobile']) && $_GET['mobile'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $doc ? htmlspecialchars($doc['title']) . ' - ' : ''; ?>Zettelkasten Sources</title>
    <link rel="stylesheet" href="style.css">
    <script>
        // Detect mobile devices and set the mobile flag
        function isMobileDevice() {
            return window.innerWidth <= 768;
        }

        // Add mobile flag to search form submissions
        document.addEventListener('DOMContentLoaded', function() {
            const searchForm = document.querySelector('.search-form');
            if (searchForm) {
                const mobileInput = document.createElement('input');
                mobileInput.type = 'hidden';
                mobileInput.name = 'mobile';
                mobileInput.value = isMobileDevice() ? '1' : '0';
                searchForm.appendChild(mobileInput);
            }
        });
    </script>
</head>
<body>
    <?php if (!empty($errors)): ?>
        <div class="error-banner">
            <?php foreach ($errors as $error): ?>
                <div class="error-message">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="container">
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <aside class="sidebar <?php echo $isMobileSearch ? 'active' : ''; ?>" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-title-row">
                    <div>
                        <h1>📚 Sources</h1>
                        <div class="doc-count"><?php echo count($documents); ?> documents</div>
                    </div>
                    <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
            </div>

            <!-- Upload Button -->
            <button onclick="showUploadModal()" class="upload-button">
                ⬆️ Upload Document
            </button>

            <!-- Upload Modal -->
            <div id="uploadModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Upload Document</h2>
                        <button onclick="hideUploadModal()" class="modal-close">✕</button>
                    </div>

                    <div class="modal-body">
                        <div class="tab-buttons">
                            <button onclick="switchTab('url')" id="tab-url" class="tab-button active">From URL</button>
                            <button onclick="switchTab('content')" id="tab-content" class="tab-button">Paste Content</button>
                        </div>

                        <form id="uploadForm" onsubmit="handleUpload(event)">
                            <div id="url-tab" class="tab-content">
                                <label for="url">Website URL:</label>
                                <input type="url" id="url" placeholder="https://example.com/article">
                                <p class="help-text">The page will be fetched and converted to Markdown</p>
                            </div>

                            <div id="content-tab" class="tab-content" style="display: none;">
                                <label for="content">Content (Markdown or HTML):</label>
                                <textarea id="content" rows="10" placeholder="Paste your content here..."></textarea>
                            </div>

                            <label for="filename">Filename:</label>
                            <input type="text" id="filename" placeholder="my-document" required>
                            <p class="help-text">Without extension (will auto-add .md)</p>

                            <div id="uploadStatus" class="upload-status"></div>

                            <div class="modal-actions">
                                <button type="button" onclick="hideUploadModal()" class="btn-secondary">Cancel</button>
                                <button type="submit" class="btn-primary">Upload</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                let currentTab = 'url';

                function showUploadModal() {
                    document.getElementById('uploadModal').style.display = 'flex';
                }

                function hideUploadModal() {
                    document.getElementById('uploadModal').style.display = 'none';
                    document.getElementById('uploadForm').reset();
                    document.getElementById('uploadStatus').innerHTML = '';
                }

                function switchTab(tab) {
                    currentTab = tab;
                    document.getElementById('tab-url').classList.toggle('active', tab === 'url');
                    document.getElementById('tab-content').classList.toggle('active', tab === 'content');
                    document.getElementById('url-tab').style.display = tab === 'url' ? 'block' : 'none';
                    document.getElementById('content-tab').style.display = tab === 'content' ? 'block' : 'none';
                }

                async function handleUpload(e) {
                    e.preventDefault();

                    const status = document.getElementById('uploadStatus');
                    const formData = new FormData();
                    const filename = document.getElementById('filename').value;

                    // ✅ ADD THIS LINE - Send filename to server
                    formData.append('filename', filename);
                    formData.append('mode', currentTab);

                    if (currentTab === 'url') {
                        const url = document.getElementById('url').value;
                        if (!url) {
                            status.innerHTML = '<span class="error">Please enter a URL</span>';
                            return;
                        }
                        formData.append('url', url);
                    } else {
                        const content = document.getElementById('content').value;
                        if (!content) {
                            status.innerHTML = '<span class="error">Please enter content</span>';
                            return;
                        }
                        formData.append('content', content);
                    }

                    status.innerHTML = '<span class="loading">Uploading...</span>';

                    try {
                        const response = await fetch('upload.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (response.ok && result.success) {
                            status.innerHTML = '<span class="success">Upload successful!</span>';
                            setTimeout(() => {
                                window.location.href = result.url;
                            }, 1000);
                        } else {
                            status.innerHTML = '<span class="error">Error: ' + result.error + '</span>';
                        }
                    } catch (error) {
                        status.innerHTML = '<span class="error">Error: ' + error.message + '</span>';
                    }
                }

                // Toggle sidebar on mobile
                function toggleSidebar() {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('sidebarOverlay');
                    sidebar.classList.toggle('active');
                    if (overlay) {
                        overlay.classList.toggle('active');
                    }
                }

                // Close sidebar when clicking on overlay
                document.addEventListener('DOMContentLoaded', function() {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('sidebarOverlay');

                    if (overlay) {
                        overlay.addEventListener('click', function() {
                            sidebar.classList.remove('active');
                            overlay.classList.remove('active');
                        });
                    }

                    // Close sidebar when clicking on a document link on mobile
                    const docLinks = document.querySelectorAll('.doc-item');
                    docLinks.forEach(link => {
                        link.addEventListener('click', function() {
                            if (window.innerWidth <= 768) {
                                setTimeout(() => {
                                    sidebar.classList.remove('active');
                                    if (overlay) overlay.classList.remove('active');
                                }, 100);
                            }
                        });
                    });
                });

                // Keep sidebar open on mobile if this was a mobile search
                <?php if ($isMobileSearch): ?>
                    document.addEventListener('DOMContentLoaded', function() {
                        const sidebar = document.getElementById('sidebar');
                        const overlay = document.getElementById('sidebarOverlay');
                        sidebar.classList.add('active');
                        if (overlay) {
                            overlay.classList.add('active');
                        }
                    });
                <?php endif; ?>
            </script>

            <div class="search-container">
                <form method="get" class="search-form" action="">
                    <input
                        type="text"
                        name="q"
                        placeholder="Search documents..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                        class="search-input"
                        maxlength="<?php echo MAX_SEARCH_LENGTH; ?>"
                    >
                    <?php if (!empty($viewDoc)): ?>
                        <input type="hidden" name="doc" value="<?php echo htmlspecialchars($viewDoc); ?>">
                    <?php endif; ?>

                    <?php if (!empty($searchQuery)): ?>
                        <label class="search-option">
                            <input
                                type="checkbox"
                                name="deep"
                                value="1"
                                <?php echo $searchContent ? 'checked' : ''; ?>
                                onchange="this.form.submit()"
                            >
                            Search content (slower)
                        </label>
                    <?php endif; ?>
                </form>

                <?php if (!empty($searchQuery)): ?>
                    <div class="search-info">
                        <span>
                            <?php echo $pagination['total']; ?> result<?php echo $pagination['total'] !== 1 ? 's' : ''; ?>
                            <?php if ($searchContent): ?>
                                <span class="search-badge">deep</span>
                            <?php endif; ?>
                        </span>
                        <a href="?<?php echo !empty($viewDoc) ? 'doc=' . urlencode($viewDoc) : ''; ?>" class="clear-search">Clear</a>
                    </div>
                <?php endif; ?>
            </div>

            <nav class="doc-list">
                <?php if (!empty($pagination['items'])): ?>
                    <?php foreach ($pagination['items'] as $document): ?>
                        <?php
                            // Extract folder path if exists
                            $folderPath = dirname($document['path']);
                            $folderPath = ($folderPath === '.' || $folderPath === '') ? '' : $folderPath;
                        ?>
                        <a
                            href="?doc=<?php echo urlencode($document['path']); ?><?php echo !empty($searchQuery) ? '&q=' . urlencode($searchQuery) . ($searchContent ? '&deep=1' : '') : ''; ?>"
                            class="doc-item <?php echo $viewDoc === $document['path'] ? 'active' : ''; ?>"
                        >
                            <div class="doc-info">
                                <span class="doc-name"><?php echo htmlspecialchars($document['title'] ?? $document['name']) ?></span>

                                <?php if (!empty($folderPath)): ?>
                                    <span class="doc-folder"><?php echo htmlspecialchars($folderPath); ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="doc-ext">.<?php echo htmlspecialchars($document['ext']); ?></span>
                        </a>
                    <?php endforeach; ?>

                    <?php if ($pagination['totalPages'] > 1): ?>
                        <div class="pagination">
                            <?php if ($pagination['page'] > 1): ?>
                                <a href="?<?php echo http_build_query(array_filter(['q' => $searchQuery, 'deep' => $searchContent ? '1' : null, 'page' => $pagination['page'] - 1, 'doc' => $viewDoc])); ?>" class="page-link">← Prev</a>
                            <?php endif; ?>

                            <span class="page-info">
                                Page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?>
                            </span>

                            <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                                <a href="?<?php echo http_build_query(array_filter(['q' => $searchQuery, 'deep' => $searchContent ? '1' : null, 'page' => $pagination['page'] + 1, 'doc' => $viewDoc])); ?>" class="page-link">Next →</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <?php if (!empty($searchQuery)): ?>
                            No documents found for "<?php echo htmlspecialchars($searchQuery); ?>"
                        <?php else: ?>
                            No documents available
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="footer-label">Direct link format:</div>
                <code>?doc=path/to/file.md</code>
                <a href="?rebuild=1" class="rebuild-link" title="Rebuild document index">🔄 Rebuild Index</a>
                <a href="?logout=1" class="rebuild-link">🚪 Logout</a>
            </div>
        </aside>

        <main class="content">
            <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Open menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <?php if ($doc): ?>
                <article class="document">
                    <header class="document-header">
                        <h1 class="document-title"><?php echo htmlspecialchars($doc['title']); ?></h1>
                        <div class="document-meta">
                            <code class="document-path"><?php echo htmlspecialchars($doc['path']); ?></code>
                            <a href="?doc=<?php echo urlencode($doc['path']); ?>" class="permalink" title="Permalink">🔗</a>
                        </div>
                    </header>
                    <div class="document-content">
                        <?php echo $doc['content']; ?>
                    </div>
                </article>
            <?php else: ?>
                <div class="welcome">
                    <div class="welcome-icon">📝</div>
                    <h2>Welcome to Zettelkasten Sources</h2>
                    <p class="welcome-description">
                        Select a document from the sidebar to view it, or use the search function to find specific content.
                    </p>

                    <div class="welcome-section">
                        <h3>Performance & Security Features</h3>
                        <ul>
                            <li><strong>Automatic caching</strong> - Index rebuilds only when files change</li>
                            <li><strong>Fast search</strong> - Searches filenames by default</li>
                            <li><strong>Deep search</strong> - Enable content search when needed (slower)</li>
                            <li><strong>Pagination</strong> - Shows <?php echo DOCS_PER_PAGE; ?> documents per page</li>
                            <li><strong>Lazy loading</strong> - Content loaded only when viewing</li>
                            <li><strong>Security validation</strong> - Path traversal protection and input sanitization</li>
                            <li><strong>Error logging</strong> - Issues logged to cache/errors.log</li>
                        </ul>
                    </div>

                    <div class="welcome-section">
                        <h3>Getting Started</h3>
                        <p>Place your HTML and Markdown files in the <code>sources/</code> directory. Subdirectories are supported.</p>
                        <p>Maximum file size: <?php echo round(MAX_FILE_SIZE / 1048576, 1); ?>MB</p>
                    </div>

                    <div class="welcome-section">
                        <h3>Linking from Your Zettelkasten</h3>
                        <p>Create direct links using this format:</p>
                        <div class="code-example">
                            <code>https://yoursite.com/?doc=filename.md</code>
                        </div>
                        <div class="code-example">
                            <code>https://yoursite.com/?doc=folder/document.html</code>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
