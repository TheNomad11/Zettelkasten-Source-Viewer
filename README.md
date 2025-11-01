# Zettelkasten Source Viewer

**vibe coded with Claude Sonnet 4.5, use on your own risk**

A minimal, fast, and secure PHP-based document viewer for managing hundreds or thousands of Markdown and HTML source documents. Perfect for Zettelkasten systems that need a simple way to view and link to reference materials.

## Features

### 📚 Core Functionality
- **Fast document viewing** - Optimized for 1000+ documents
- **Markdown & HTML support** - View both formats seamlessly
- **Folder organization** - Recursive subdirectory scanning
- **Full-text search** - Quick filename search with optional deep content search
- **Pagination** - Browse large document collections efficiently
- **Direct linking** - Create permanent links to specific documents

### 🚀 Performance
- **Automatic caching** - JSON index rebuilds only when files change
- **Lazy loading** - Documents loaded only when viewed
- **Smart search** - Fast filename search by default, deep search on demand
- **Index rebuild** - Manual refresh option available

### 🔒 Security
- **Path traversal protection** - Prevents directory traversal attacks
- **Input validation** - Sanitizes all user inputs
- **Size limits** - Configurable max file sizes (10MB documents, 5MB images)
- **Error logging** - Tracks security events and issues
- **Internal images only** - Images must be in `sources/images/` directory

### 🌍 Internationalization
- **UTF-8 support** - Full support for international characters
- **Special characters** - Handles umlauts (ä, ö, ü), accents, apostrophes, etc.
- **Spaces in filenames** - Works with natural file naming

### 🎨 Features
- **Clean, modern UI** - Responsive design with dark accents
- **Auto-linking URLs** - Plain URLs in Markdown automatically become clickable
- **Image support** - Display images from internal `images/` folder
- **Folder paths** - Shows document location in sidebar
- **Document count** - Displays total number of indexed documents

## Installation

### Requirements
- PHP 7.4 or higher
- Web server (Apache, Nginx, etc.)
- File system permissions for cache directory

### Quick Setup

1. **Clone  the repository or download latest release**

2. **Upload files to your web server**
```
/var/www/html/zettelkasten/
├── index.php
├── style.css
├── sources/          # Create this directory
└── cache/            # Auto-created
```

3. **Create the sources directory**
```bash
mkdir sources
mkdir sources/images  # For image support
chmod 775 sources
chmod 775 sources/images
```

4. **Set proper permissions** (recommended)
```bash
# Add your user to www-data group
sudo usermod -a -G www-data yourusername

# Set group ownership
sudo chgrp -R www-data /path/to/zettelkasten

# Set permissions
sudo find /path/to/zettelkasten -type d -exec chmod 775 {} \;
sudo find /path/to/zettelkasten -type f -exec chmod 664 {} \;

# Set SGID bit (new files inherit group)
sudo find /path/to/zettelkasten -type d -exec chmod g+s {} \;

# Log out and back in for group changes to take effect
```

5. **Add your documents**
- Place `.md`, `.html`, or `.htm` files in the `sources/` directory
- Organize with subdirectories as needed (e.g., `sources/2024/research/`)
- Add images to `sources/images/`

6. **Access via browser**
```
https://yoursite.com/zettelkasten/
```

## Usage

### Adding Documents

Simply place your Markdown or HTML files in the `sources/` directory:

```
sources/
├── 2024/
│   ├── research/
│   │   └── article.md
│   └── notes.md
├── 2025/
│   └── january.md
└── images/
    └── diagram.png
```

The app will automatically scan all subdirectories.

### Linking from Your Zettelkasten

Create direct links using the `?doc=` parameter:

```markdown
[Source Article](https://yoursite.com/zettelkasten/?doc=2024/research/article.md)
[Quick Note](https://yoursite.com/zettelkasten/?doc=notes.md)
```

### Using Images

Place images in `sources/images/` and reference them in Markdown:

```markdown
![Diagram](images/diagram.png)
![Nested](images/2024/screenshot.jpg)
```

**Security note:** Only images in the `sources/images/` directory will be displayed. External images are blocked for security.

### Search

- **Quick search** (default): Searches filenames only - instant results
- **Deep search**: Check "Search content" to search inside documents - slower but thorough

### Rebuilding Index

Click the "🔄 Rebuild Index" link in the sidebar to manually refresh the document index. The index automatically rebuilds when files are added, modified, or deleted.

## Configuration

Edit constants at the top of `index.php`:

```php
define('SOURCES_DIR', __DIR__ . '/sources');  // Source documents directory
define('CACHE_DIR', __DIR__ . '/cache');      // Cache directory
define('CACHE_TTL', 3600);                     // Cache lifetime (1 hour)
define('DOCS_PER_PAGE', 50);                   // Documents per page
define('MAX_SEARCH_LENGTH', 200);              // Max search query length
define('MAX_FILE_SIZE', 10485760);             // Max document size (10MB)
define('MAX_IMAGE_SIZE', 5242880);             // Max image size (5MB)
```

## File Structure

```
zettelkasten-viewer/
├── index.php           # Main application
├── style.css           # Styles
├── README.md           # This file
├── sources/            # Your documents (create this)
│   ├── images/        # Images directory (optional)
│   └── *.md, *.html   # Your documents
├── cache/              # Auto-generated
│   ├── documents.json # Document index
│   └── errors.log     # Error log
```

## Supported Markdown Features

- **Headers**: `#` through `#####`
- **Bold**: `**text**` or `__text__`
- **Italic**: `*text*` or `_text*`
- **Links**: `[text](url)` 
- **Auto-links**: Plain URLs like `https://example.com`
- **Images**: `![alt](images/file.jpg)`
- **Code**: `` `inline` `` and ` ```blocks``` `
- **Lists**: `* item`

## Troubleshooting

### Documents not loading
- Check file permissions: `chmod 664 sources/*.md`
- Check directory permissions: `chmod 775 sources/`
- View error log: `cache/errors.log`

### Permission denied errors
- Ensure web server user can read files
- Check that cache directory is writable
- See Installation section for proper permission setup

### Index not updating
- Click "🔄 Rebuild Index" manually
- Check cache directory permissions
- Verify `CACHE_TTL` setting

### Images not displaying
- Ensure images are in `sources/images/` directory
- Check image file permissions
- Verify image format is supported (jpg, png, gif, webp, svg)
- Check browser console for 403/404 errors

## Security

This application implements several security measures:

- **Path traversal protection** - Validates all file paths
- **Input sanitization** - Cleans all user inputs
- **Allowed extensions** - Only specified file types are served
- **Size limits** - Prevents reading of extremely large files
- **Internal images only** - External images blocked
- **Error logging** - Security events tracked in `cache/errors.log`

### Best Practices

- Keep the application updated
- Restrict access with HTTP authentication if needed
- Regularly review `cache/errors.log`
- Use HTTPS in production
- Don't expose error logs publicly

## Performance

Designed to handle large document collections efficiently:

- **100 files**: ~50-100ms load time
- **500 files**: ~100-200ms load time
- **1000+ files**: ~150-300ms load time (after initial index)

Initial index build takes 1-2 seconds for 1000 files, then uses cached index.


## Contributing

Contributions are welcome! Please feel free to submit issues or pull requests.

## License

MIT License - feel free to use this for personal or commercial projects.

## Credits

Created for Zettelkasten users who need a simple, fast way to view and link to source documents.

