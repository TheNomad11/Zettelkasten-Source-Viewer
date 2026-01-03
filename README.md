# Zettelkasten Source Viewer (vibe coded with Claude Sonnet 4.5)

A minimal, lightweight document viewer for HTML and Markdown files optimized for hundreds to thousands of documents.

## Features

### Core Functionality
- **Markdown & HTML Support** - View `.md`, `.html`, and `.htm` files with proper rendering using Parsedown
- **Smart Caching** - Index rebuilds only when files change, with configurable TTL (1 hour default)
- **Fast Search** - Filename search by default, optional deep content search
- **Pagination** - Shows 50 documents per page by default
- **Lazy Loading** - Content loaded only when viewing documents

### Document Management
- **Upload from URL** - Fetch web articles and convert to Markdown automatically
- **Paste Content** - Upload Markdown or HTML directly via textarea
- **Optional Image Download** - Download up to 5 images (max 500KB each) when uploading articles
- **Automatic Title Extraction** - Sidebar displays first H1 header instead of filenames
- **Newest First Sorting** - Documents sorted by modification time

### Security
- **Password Authentication** - Simple session-based login
- **Path Traversal Protection** - Validates all file paths
- **Image Sandboxing** - Only serves images from `sources/images/` directory
- **Input Sanitization** - Validates search queries and file paths
- **Safe Mode** - Parsedown runs with `setSafeMode(true)`

### Design
- **Responsive Layout** - Sidebar + content area with mobile support
- **Optimized Typography** - 680px max-width, 18px font, 1.7 line-height for comfortable reading
- **Clean UI** - Minimalist design with subtle file metadata
- **Dark/Light Ready** - CSS custom properties for easy theming

## Installation

1. **Clone the repository**
```bash
git clone https://github.com/yourusername/zettelkasten-viewer.git
cd zettelkasten-viewer
```

2. **Install Parsedown**
```bash
wget https://raw.githubusercontent.com/erusev/parsedown/master/Parsedown.php
```

3. **Set up directories**
```bash
mkdir -p sources/images cache
chmod 755 sources cache
chmod 755 sources/images
```

4. **Configure authentication**

Edit `index.php` and change the default password hash (line ~20):
```php
define('AUTH_PASSWORD_HASH', password_hash('your-secure-password', PASSWORD_DEFAULT));
```

Generate a new hash:
```bash
php -r "echo password_hash('your-secure-password', PASSWORD_DEFAULT);"
```

5. **Upload to your web server**

Point your web server to the directory containing `index.php`.

## Usage

### Adding Documents

**Option 1: Manual Upload**
- Place `.md` or `.html` files in the `sources/` directory
- Subdirectories are supported
- Index rebuilds automatically when files change

**Option 2: Upload via URL**
- Click "Upload Document" button
- Enter website URL
- Choose filename
- Optionally check "Download images"
- Click Upload

**Option 3: Paste Content**
- Click "Upload Document" button
- Switch to "Paste Content" tab
- Paste Markdown or HTML
- Choose filename
- Click Upload

### Direct Links

Access specific documents directly:
```
https://yoursite.com/?doc=filename.md
https://yoursite.com/?doc=folder/document.html
```

### Search

- **Quick search**: Type in search box (searches filenames only)
- **Deep search**: Check "Search content" for full-text search (slower)

## Configuration

Edit constants in `index.php` (lines 40-50):

```php
define('SOURCES_DIR', __DIR__ . '/sources');           // Document directory
define('CACHE_DIR', __DIR__ . '/cache');               // Cache directory
define('CACHE_TTL', 3600);                             // Cache time (seconds)
define('DOCS_PER_PAGE', 50);                           // Pagination limit
define('MAX_FILE_SIZE', 10485760);                     // Max file size (10MB)
define('MAX_IMAGE_SIZE', 5242880);                     // Max image size (5MB)
define('MAX_SEARCH_LENGTH', 200);                      // Max search query
```

## Requirements

- PHP 7.4+ (8.0+ recommended)
- Web server (Apache, Nginx, etc.)
- Parsedown library (included via download)
- Write permissions on `cache/` and `sources/images/` directories

## File Structure

```
zettelkasten-viewer/
├── index.php              # Main viewer application
├── upload.php             # Upload endpoint
├── style.css              # Styles
├── Parsedown.php          # Markdown parser library
├── sources/               # Your documents
│   ├── images/           # Local images
│   └── *.md              # Markdown files
└── cache/                # Generated cache
    ├── documents.json    # Document index
    └── errors.log        # Error log
```

## Customization

### Typography

Edit `.document` and `.document-content` in `style.css`:

```css
.document {
    max-width: 680px;      /* Line length */
}

.document-content {
    font-size: 18px;       /* Base font size */
    line-height: 1.7;      /* Line height */
}
```

### Color Scheme

Modify CSS custom properties in `:root`:

```css
:root {
    --primary: #2563eb;
    --bg-main: #ffffff;
    --text-primary: #0f172a;
}
```

## Security Notes

- Change default password immediately
- Run behind HTTPS in production
- Images are sandboxed to `sources/images/` only
- All file paths validated against directory traversal
- Session-based authentication with logout support

## Troubleshooting

**Documents not appearing?**
- Check file permissions on `sources/` directory
- Click "Rebuild Index" in sidebar footer
- Check `cache/errors.log` for issues

**Uploads failing?**
- Verify write permissions on `sources/` and `sources/images/`
- Check `cache/errors.log`
- Ensure PHP `file_get_contents()` can access external URLs

**Images not displaying?**
- Images must be in `sources/images/` directory
- Reference as `![alt](images/filename.jpg)` in markdown
- External images are blocked for security

## License

MIT License - use freely for personal or commercial projects.

## Credits

- **Parsedown** - Markdown parser by Emanuil Rusev
- Built for personal Zettelkasten and knowledge management workflows

