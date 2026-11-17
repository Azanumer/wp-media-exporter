# WP Media Exporter

Export part or all of the WordPress Media Library to a downloadable ZIP — without phpMyAdmin, FTP, or a backup plugin.

## Features

- **Filters** — by file type (images, videos, audio, documents), upload date range, or title search.
- **Batched building** — files are added 100 at a time with an auto-refreshing progress page, so a 5,000-file library never hits PHP's execution timeout.
- **Uploads-relative paths** — the ZIP keeps the familiar `2026/11/photo.jpg` folder structure.
- **Missing files reported** — files whose disk copy is gone are counted as skipped, not silently dropped.
- **Self-cleaning** — exports live in `uploads/media-exports/` (random 16-character token in the filename) and are deleted after 24 hours.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Tools → Media Exporter**, pick your filters, and build the ZIP.

## Uninstall

Deleting the plugin removes its export records. ZIP files already in `uploads/media-exports/` are left on disk and expire on their own after 24 hours.
