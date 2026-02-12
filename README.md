# WordPress Database Domain Search & Replace

A PHP tool that safely updates the domain name throughout a WordPress database. Ideal for migrating sites from staging to production, changing from HTTP to HTTPS, or moving between domains.

## Features

- **Serialized data safe** — Correctly handles PHP serialized data in `wp_options`, post meta, and other tables (WordPress stores URLs in serialized format)
- **Protocol-aware** — Replaces both `http://` and `https://` variants
- **Dry run mode** — Preview all changes before applying
- **CLI or browser** — Use from command line or via web interface

## Requirements

- PHP 7.4 or higher
- MySQL/MariaDB database access
- WordPress installation with `wp-config.php`

## Installation

### Option A: Browser (upload folder)

1. Download the repo and extract it. **Upload only the `search-replace` folder** to your WordPress site (e.g. as `search-replace` subfolder)
2. Edit `config.php` and set a unique `SECRET_KEY` (default is `my-secret-key`)
3. Visit `https://yoursite.com/search-replace/` in your browser
4. Enter the secret key to access the tool
5. **Delete the folder after use** for security

### Option B: Command line

1. Copy `wp-domain-replace.php` to your WordPress root directory, or
2. Run from the search-replace folder: `php wp-domain-replace.php` (uses parent dir for wp-config), or
3. Use `--wp-path` to point to your WordPress folder

## Usage

### Option 1: Command-line arguments

```bash
php wp-domain-replace.php --old=oldsite.com --new=newsite.com
```

### Option 2: Interactive mode (prompts for input)

```bash
php wp-domain-replace.php
```

### Option 3: Dry run (preview without changes)

```bash
php wp-domain-replace.php --old=staging.example.com --new=example.com --dry-run
```

### Option 4: Specify WordPress path

```bash
php wp-domain-replace.php --wp-path=/path/to/wordpress --old=localhost --new=example.com
```

### All options

| Option | Description |
|--------|-------------|
| `--old=DOMAIN` | Domain to find (e.g., `oldsite.com` or `localhost/wp`) |
| `--new=DOMAIN` | Domain to replace with |
| `--dry-run` | Preview changes without modifying the database |
| `--wp-path=PATH` | Path to WordPress root (default: parent of script directory) |
| `--skip-tables=` | Comma-separated list of tables to skip |
| `--help` | Show usage help |

## Before you run

1. **Backup your database** — Always create a full backup before running
2. **Test with dry run** — Use `--dry-run` first to see what will change
3. **Update wp-config.php** — After changing domains, update `WP_HOME` and `WP_SITEURL` in wp-config if you use them

## What gets updated

The script searches and replaces across all database tables, including:

- `wp_options` — `siteurl`, `home`, and other option values
- `wp_posts` — Post content, excerpts, GUIDs
- `wp_postmeta` — Serialized meta data (widgets, theme settings, etc.)
- `wp_comments` — Comment content and author URLs
- Plus any custom tables from plugins

## Example workflow (browser)

1. Backup your database
2. Upload the `search-replace` folder to `yoursite.com/search-replace/`
3. Set `SECRET_KEY` in `config.php`
4. Visit the URL, enter your key
5. Enter old/new domains, keep "Dry run" checked, click Run
6. Review the preview, then uncheck "Dry run" and run again to apply
7. Delete the folder when done

## Example workflow (CLI)

```bash
# From the search-replace folder (inside your WordPress root or with --wp-path):
# 1. Backup your database first!
# 2. Run a dry run to preview
php wp-domain-replace.php --old=staging.mysite.com --new=mysite.com --dry-run

# 3. If output looks correct, run for real
php wp-domain-replace.php --old=staging.mysite.com --new=mysite.com
```

## Security

- **Browser:** Set a strong `SECRET_KEY` in `config.php` and delete the folder after use
- **CLI:** Run from command line only; delete or move the script after use on production
- Uses your existing `wp-config.php` credentials (no additional config needed)
