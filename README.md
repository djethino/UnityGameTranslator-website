# UnityGameTranslator Website

Community platform for sharing Unity game translation files with API for mod synchronization.

**Live site:** [unitygametranslator.asymptomatikgames.com](https://unitygametranslator.asymptomatikgames.com)

## Features

### Web Platform
- **Browse translations** by game, language, and popularity
- **Upload translation files** with automatic game detection (Steam, Epic, GOG)
- **Fork translations** to improve existing work
- **Merge contributions** — Main owners review and merge Branches
- **Branch rating** — Main owners rate contributor quality
- **Inline editing** — edit translations directly on the website with tag selection
- **Merge preview** — visual diff between local (mod) and server translations
- **Vote system** to highlight quality translations
- **Report system** for moderation
- **Profile management** with GDPR data export and account deletion
- **Multi-language UI** (19 languages)
- **Admin dashboard** with analytics, user management, and moderation

### Collaboration Model (Main/Branch/Fork)

| Term | Description |
|------|-------------|
| **Main** | The original translation. First uploader becomes the owner. |
| **Branch** | A contributor's version, linked to the Main. One per user per UUID. |
| **Fork** | Copying a translation to create your own Branch. |

**Workflow:**
1. User A uploads → becomes **Main** owner
2. User B downloads, improves, uploads → creates a **Branch**
3. User A reviews Branches, rates contributors, and merges contributions

**Constraints:**
- One Main per UUID (first uploader wins)
- One Branch per user per UUID (updating replaces your Branch)
- Languages locked after first upload (source/target immutable)

### Translation Quality System (H/V/A Tags)

| Tag | Name | Score | Description |
|-----|------|-------|-------------|
| **H** | Human | 3 pts | Written by a human |
| **V** | Validated | 2 pts | AI translation approved by human |
| **A** | AI | 1 pt | Translated by AI |
| **S** | Skip | — | Intentionally not translated |
| **M** | Mod | — | Mod UI translations (internal) |

**Quality Score** (0-3): `(H×3 + V×2 + A×1) / (H + V + A)`

| Score | Label |
|-------|-------|
| 2.5+ | Excellent |
| 2.0+ | Good |
| 1.5+ | Fair |
| 1.0+ | Basic |
| <1.0 | Raw AI |

### API for Unity Mod
- **Search translations** by Steam ID, game name, or language
- **Download translations** with ETag caching
- **Check for updates** without downloading the full file
- **Upload translations** with gzip compression
- **UUID check** — detect if upload is New, Update, or Fork
- **Branch listing** — Main owners see all contributors
- **Device Flow authentication** — enter code on website to link mod
- **Merge preview** — mod sends local content, user resolves in browser
- **Vote** on translations
- **Real-time sync** via Server-Sent Events (SSE)
- **Rate limiting** per endpoint

### Authentication

**OAuth providers:** Google, GitHub, Discord, Twitch, Steam

**Device Flow** for Unity mod: mod displays a code, user enters it at `/link`, mod receives API token via SSE stream.

## Tech Stack

- **Framework:** Laravel 12 (PHP 8.2+)
- **Real-time:** Node.js SSE micro-server + Redis pub/sub
- **Database:** SQLite (dev) / MySQL (prod)
- **Auth:** Laravel Socialite (5 OAuth providers)
- **Frontend:** Tailwind CSS 4, Alpine.js (CSP build), Chart.js, Font Awesome, Flag-icons
- **Analytics:** Built-in event tracking with daily aggregation

## Architecture

Two processes communicating via Redis:

```
Unity Mod ──► Laravel API (PHP)  ◄──► Redis pub/sub ◄──► SSE Server (Node.js) ◄── Unity Mod
              (business logic,           (signaling)      (real-time streaming)
               auth, DB, uploads)
```

- **Laravel** handles business logic, authentication, database, uploads, merges, API
- **Node.js SSE server** streams real-time events to connected clients (lightweight transport layer)
- **Redis pub/sub** bridges the two: Laravel publishes events, SSE server forwards to clients

### SSE Endpoints

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET /auth/device/:code/stream` | None | Device Flow: streams auth result |
| `GET /sync/stream?uuid=xxx&hash=yyy` | Bearer | Multi-device sync: streams translation updates |
| `GET /merge-preview/:token/stream` | Token | Merge completion notification |
| `GET /health` | None | Health check |

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

Handles everything: dependencies, environment file, database migration, and asset building.

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
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=

GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=

DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=

TWITCH_CLIENT_ID=
TWITCH_CLIENT_SECRET=

STEAM_API_KEY=
```

| Provider | Console |
|----------|---------|
| Google | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |
| GitHub | [GitHub Developer Settings](https://github.com/settings/developers) |
| Discord | [Discord Developer Portal](https://discord.com/developers/applications) |
| Twitch | [Twitch Developer Console](https://dev.twitch.tv/console/apps) |
| Steam | [Steam Web API Key](https://steamcommunity.com/dev/apikey) |

### Redis

Both Laravel and the SSE server need the same Redis instance:

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

For Unix socket: set `REDIS_SOCKET=/path/to/redis.sock` (overrides host/port).

### SSE Server

| Variable | Default | Description |
|----------|---------|-------------|
| `PORT` | `3000` | Listening port |
| `REDIS_URL` | `redis://127.0.0.1:6379` | Redis connection (TCP) |
| `REDIS_SOCKET` | — | Redis Unix socket (overrides URL) |
| `LARAVEL_API_URL` | `http://localhost:8000/api/v1` | Laravel API for token validation |
| `ALLOWED_ORIGIN` | — | CORS origin |
| `PER_IP_LIMIT` | `10` | Max SSE connections per IP |
| `MAX_CONNECTIONS` | `1000` | Global connection limit |

## Development

```bash
# Start Laravel dev server (runs server, queue, logs, and Vite)
composer dev

# Start SSE server (separate terminal)
cd sse-server
PORT=3001 REDIS_URL=redis://127.0.0.1:6379 LARAVEL_API_URL=http://localhost:8000/api/v1 node server.js
```

## Commands

```bash
composer test                          # Run tests
php artisan analytics:aggregate        # Aggregate daily analytics
php artisan recalculate-hashes         # Recalculate translation file hashes
```

## Supported Languages

Arabic, Chinese, Dutch, English, French, German, Hebrew, Hindi, Indonesian, Italian, Japanese, Korean, Polish, Portuguese, Russian, Spanish, Thai, Turkish, Vietnamese

## Related

- **Unity Mod:** [github.com/djethino/UnityGameTranslator](https://github.com/djethino/UnityGameTranslator)

## Acknowledgments

### Backend
- **[Laravel](https://laravel.com/)** — PHP framework
- **[Laravel Socialite](https://laravel.com/docs/socialite)** — OAuth authentication
- **[ioredis](https://github.com/redis/ioredis)** — Redis client for Node.js

### Frontend
- **[Tailwind CSS](https://tailwindcss.com/)** — Utility-first CSS
- **[Alpine.js](https://alpinejs.dev/)** — Lightweight JS framework (CSP build)
- **[Chart.js](https://www.chartjs.org/)** — Analytics charts
- **[Font Awesome](https://fontawesome.com/)** — Icons
- **[Flag-icons](https://flagicons.lipis.dev/)** — Language flags

## License

Dual-licensed:
- **Open Source:** [AGPL-3.0](LICENSE)
- **Commercial:** Contact us for proprietary use

See [LICENSING.md](LICENSING.md) for details.
