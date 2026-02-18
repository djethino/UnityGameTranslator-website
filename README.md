# UnityGameTranslator Website

Community platform for sharing Unity game translation files with API for mod synchronization.

**Live site:** [unitygametranslator.asymptomatikgames.com](https://unitygametranslator.asymptomatikgames.com)

## Features

### Web Platform
- **Browse translations** by game, language, and popularity
- **Upload translation files** with automatic game detection
- **Fork translations** to improve existing work
- **Merge contributions** for translation owners to consolidate branches
- **Vote system** to highlight quality translations
- **Report system** for moderation
- **Profile management** with GDPR data export
- **Multi-language UI** (19 languages)
- **Admin dashboard** with analytics and moderation tools

### Collaboration Model (Main/Branch/Fork)

The website uses a Main/Branch model for collaborative translation:

| Term | Description |
|------|-------------|
| **Main** | The original translation. First uploader becomes the Main owner. |
| **Branch** | A contributor's version, linked to the Main. |
| **Fork** | The action of copying a translation to create your own Branch. |

**Workflow:**
1. User A uploads → becomes **Main** owner
2. User B downloads, improves, uploads → **forks** and creates a **Branch**
3. User A sees all Branches on their translation page
4. User A can review and merge contributions from Branches into Main

**Constraints:**
- One Main per UUID (first uploader wins)
- One Branch per user per UUID (updating replaces your Branch)
- Languages locked after first upload (source/target immutable)
- UUID links all translations in a "lineage" (Main + all Branches)

### Translation Quality System (H/V/A Tags)

Each translation entry has a quality tag stored in the JSON:

```json
{
  "Hello": { "v": "Bonjour", "t": "H" },
  "Play": { "v": "Jouer", "t": "A" }
}
```

| Tag | Name | Score | Description |
|-----|------|-------|-------------|
| **H** | Human | 3 pts | Written by a human |
| **V** | Validated | 2 pts | AI translation approved by human |
| **A** | AI | 1 pt | Translated by Ollama |

> **Note:** Entries with tag `H` but empty value are displayed as "C" (Capture) and count as 0 pts until translated.

**Additional tags (excluded from quality score):**

| Tag | Name | Description |
|-----|------|-------------|
| **S** | Skip | Text intentionally not translated (alien language, Klingon, etc.). AI decides. *Experimental.* |
| **M** | Mod | Mod UI translations. *Internal use.* |

**Quality Score** (0-3): `(H×3 + V×2 + A×1) / (H + V + A)`

| Score | Label |
|-------|-------|
| 2.5+ | Excellent |
| 2.0+ | Good |
| 1.5+ | Fair |
| 1.0+ | Basic |
| <1.0 | Raw AI |

**Editing on the website:**
- Translation owners can **edit** entries online → tag becomes `H`
- Owners can **validate** AI translations → tag becomes `V`
- Bulk validation available for reviewing multiple entries

The website displays H/V/A counts and quality score on each translation card.

### API for Unity Mod
- **Search translations** by Steam ID, game name, or language
- **Download translations** with ETag caching
- **Check for updates** without downloading full file
- **Upload translations** with authentication
- **Device Flow authentication** (enter code on website to link mod)
- **Real-time sync** via Server-Sent Events (SSE)
- **Rate limiting** per endpoint

## Tech Stack

- **Framework:** Laravel 12
- **Real-time:** Node.js SSE micro-server + Redis pub/sub
- **Database:** SQLite (dev) / MySQL (prod)
- **Auth:** Laravel Socialite (Steam, Epic Games, Google, GitHub, Discord, Twitch)
- **Frontend:** Tailwind CSS 4, Alpine.js (CSP build), Font Awesome

## Architecture

The website consists of two processes communicating via Redis:

```
Unity Mod ──► Laravel API (PHP)  ◄──► Redis pub/sub ◄──► SSE Server (Node.js) ◄── Unity Mod
              (business logic,           (signaling)      (real-time streaming)
               auth, DB, uploads)
```

- **Laravel** handles all business logic: authentication, database, uploads, merges, API responses
- **Node.js SSE server** is a lightweight transport layer that streams real-time events to connected clients
- **Redis pub/sub** bridges the two: when Laravel processes an action (upload, merge, auth), it publishes an event that the SSE server forwards to connected clients

This separation is necessary because PHP workers are blocked during SSE connections, making it impractical for long-lived connections. Node.js handles thousands of concurrent SSE connections efficiently via its event loop.

### SSE Endpoints

The Node.js server exposes three SSE streams:

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET /auth/device/:code/stream` | None | Device Flow: streams auth result when user enters code |
| `GET /sync/stream?uuid=xxx&hash=yyy` | Bearer | Multi-device sync: streams translation updates |
| `GET /merge-preview/:token/stream` | None | Merge: streams completion when user finishes merging in browser |
| `GET /health` | None | Health check |

The SSE server validates Bearer tokens by forwarding them to the Laravel API (one HTTP call per SSE connection).

## Requirements

- PHP 8.2+ with `phpredis` extension
- Composer
- Node.js 18+
- Redis 6+
- SQLite or MySQL

## Installation

```bash
composer setup
```

The `composer setup` command handles everything: dependencies, environment file, database migration, and asset building.

### Manual installation

```bash
# Laravel
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build

# SSE Server
cd sse-server
npm install
```

## Configuration

### OAuth Providers

Configure in `.env`:

```env
# Google
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=

# GitHub
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=

# Discord
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=

# Twitch
TWITCH_CLIENT_ID=
TWITCH_CLIENT_SECRET=

# Steam (Web API key only)
STEAM_API_KEY=

# Epic Games
EPICGAMES_CLIENT_ID=
EPICGAMES_CLIENT_SECRET=
```

### Redis

Both Laravel and the SSE server need access to the same Redis instance. Configure in `.env`:

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

For Unix socket connections (instead of TCP):

```env
REDIS_SOCKET=/path/to/redis.sock
```

> When `REDIS_SOCKET` is set, `REDIS_HOST` and `REDIS_PORT` are ignored.

### SSE Server

The SSE server is configured via environment variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `PORT` | `3000` | Listening port |
| `REDIS_URL` | `redis://127.0.0.1:6379` | Redis connection URL (TCP mode) |
| `REDIS_SOCKET` | *none* | Redis Unix socket path (overrides `REDIS_URL`) |
| `REDIS_PASSWORD` | *none* | Redis password if required |
| `LARAVEL_API_URL` | `http://localhost:8000/api/v1` | Laravel API base URL (used for auth validation) |
| `ALLOWED_ORIGIN` | *(see code)* | CORS origin for the main website |

In production, the SSE server should be accessible on a separate subdomain or port from the main website, with a reverse proxy (Nginx, Apache, Caddy) or a Node.js process manager (PM2, systemd, Passenger).

### Where to get credentials

| Provider | Console |
|----------|---------|
| Google | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |
| GitHub | [GitHub Developer Settings](https://github.com/settings/developers) |
| Discord | [Discord Developer Portal](https://discord.com/developers/applications) |
| Twitch | [Twitch Developer Console](https://dev.twitch.tv/console/apps) |
| Steam | [Steam Web API Key](https://steamcommunity.com/dev/apikey) |
| Epic Games | [Epic Games Developer Portal](https://dev.epicgames.com/portal) |

## Development

```bash
# Start Laravel dev server (runs server, queue, logs, and Vite)
composer dev

# Start SSE server (in a separate terminal)
cd sse-server
PORT=3001 REDIS_URL=redis://127.0.0.1:6379 LARAVEL_API_URL=http://localhost:8000/api/v1 node server.js
```

> The SSE server must run alongside Laravel for real-time features (Device Flow auth, multi-device sync, merge completion).

## Commands

```bash
# Run tests
composer test

# Clear caches
php artisan cache:clear
php artisan view:clear
php artisan config:clear

# Fresh database
php artisan migrate:fresh
```

## Related

- **Unity Mod:** [github.com/djethino/UnityGameTranslator](https://github.com/djethino/UnityGameTranslator)

## Acknowledgments

### Backend
- **[Laravel](https://laravel.com/)** - PHP framework
- **[Laravel Socialite](https://laravel.com/docs/socialite)** - OAuth authentication
- **[SocialiteProviders](https://socialiteproviders.com/)** - Community OAuth providers
- **[ioredis](https://github.com/redis/ioredis)** - Redis client for Node.js SSE server

### Frontend
- **[Tailwind CSS](https://tailwindcss.com/)** - Utility-first CSS framework
- **[Alpine.js](https://alpinejs.dev/)** - Lightweight JavaScript framework (CSP build)
- **[Font Awesome](https://fontawesome.com/)** - Icon library

## License

This project is dual-licensed:

- **Open Source:** [AGPL-3.0](LICENSE) - Free for open source use
- **Commercial:** Contact us for proprietary/commercial use

See [LICENSING.md](LICENSING.md) for details.
