# 🗃️ AIrchive

[![MIT License](https://img.shields.io/github/license/Astrotomic/airchive.svg?label=License&color=blue&style=for-the-badge)](https://github.com/Astrotomic/airchive/blob/master/LICENSE)
[![Offset Earth](https://img.shields.io/badge/Treeware-%F0%9F%8C%B3-green?style=for-the-badge)](https://plant.treeware.earth/Astrotomic/airchive)
[![Larabelles](https://img.shields.io/badge/Larabelles-%F0%9F%A6%84-lightpink?style=for-the-badge)](https://www.larabelles.com/)

[![composer](https://img.shields.io/github/actions/workflow/status/Astrotomic/airchive/composer.yml?style=flat-square&logoColor=white&logo=github&label=composer)](https://github.com/Astrotomic/airchive/actions?query=workflow%3Acomposer)
[![phpunit](https://img.shields.io/github/actions/workflow/status/Astrotomic/airchive/phpunit.yml?style=flat-square&logoColor=white&logo=github&label=phpunit)](https://github.com/Astrotomic/airchive/actions?query=workflow%3Aphpunit)
[![pint](https://img.shields.io/github/actions/workflow/status/Astrotomic/airchive/pint.yml?style=flat-square&logoColor=white&logo=github&label=pint)](https://github.com/Astrotomic/airchive/actions?query=workflow%3Apint)
[![phpstan](https://img.shields.io/github/actions/workflow/status/Astrotomic/airchive/phpstan.yml?style=flat-square&logoColor=white&logo=github&label=phpstan)](https://github.com/Astrotomic/airchive/actions?query=workflow%3Aphpstan)
[![prettier](https://img.shields.io/github/actions/workflow/status/Astrotomic/airchive/prettier.yml?style=flat-square&logoColor=white&logo=github&label=prettier)](https://github.com/Astrotomic/airchive/actions?query=workflow%3Aprettier)

Private Laravel app for importing, searching, browsing, and exporting ChatGPT and Cursor chat exports.

## Stack

- Laravel 13, PHP 8.4+
- Livewire 4 + Tailwind CSS 4
- PostgreSQL 18 (full-text search via native `whereFullText`)
- Passkeys (`laravel/passkeys`) + Fortify TOTP (no passwords)
- Database queue for async imports

## Quick start

```bash
# Start PostgreSQL
docker compose up -d

# Configure environment (copy example, then generate key)
cp .env.example .env
php artisan key:generate

# Install dependencies
composer install
npm install && npm run build

# Migrate and run queue worker in a second terminal
php artisan migrate
php artisan queue:work

# Provision a user (interactive TOTP setup + signed enrollment URL)
php artisan chat-archive:user-create you@example.com --name="Your Name"

# Serve the app
php artisan serve
```

Open the signed enrollment URL from the command output on your device, confirm email + TOTP, then register a passkey.

For passkeys to work, `APP_URL` must match how you access the app (HTTPS in production).

ChatGPT exports can exceed 1 GB. The application accepts imports up to 2 GB by default; ensure PHP's `upload_max_filesize` and `post_max_size` and any reverse-proxy body-size limit are at least as large. The application limit can be changed with `IMPORT_MAX_UPLOAD_KILOBYTES`.

External-source favicons use Google's gstatic service by default. Set `FAVICON_DRIVER` to `gstatic`, `unavatar`, or `logo_dev`; Logo.dev additionally requires a publishable `LOGO_DEV_TOKEN`. Tracking-parameter and related-host normalization rules can be extended in `config/external-sources.php`.

To bypass HTTP uploads and the queue, import a local export synchronously:

```bash
php artisan archive:import /absolute/path/to/chatgpt-export.zip --user=user@example.com
php artisan archive:import /absolute/path/to/cursor-export.zip --user=user@example.com
php artisan archive:import /absolute/path/to/cursor-export-directory --user=user@example.com
php artisan archive:import /absolute/path/to/transcript.jsonl --user=user@example.com
```

`--user` accepts either an email address or user ID. It can be omitted when the application contains exactly one user. An interrupted import can reuse its existing batch with `--retry=<batch-id>`.

## Features

- **Import** — ChatGPT `.json` / complete `.zip` exports (sharded chats, Codex chats, images, uploaded/generated files, Library files, shares, and feedback), individual Cursor agent `.jsonl`, and complete Cursor ZIP/directory exports (transcripts, Canvas, agent-tool output, terminals, images, plans, and other workspace artifacts with message links where Cursor recorded a source path)
- **Search** — PostgreSQL full-text over message content and conversation titles
- **Browse** — canonical thread view with optional branch toggle for ChatGPT edits
- **Library** — private previews, metadata, filtering, downloads, and links back to source chats
- **Export** — Markdown, HTML, or JSON per conversation
- **Auth** — passkey login + TOTP; users provisioned via Artisan only

## Tests

```bash
php artisan test
```

Search full-text behavior is exercised against PostgreSQL in production. Tests use SQLite with a LIKE fallback for portability.
