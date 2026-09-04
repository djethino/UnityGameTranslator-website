# UnityGameTranslator Website

Community platform for sharing Unity game translation files with API for mod synchronization.

**Live site:** [unitygametranslator.asymptomatikgames.com](https://unitygametranslator.asymptomatikgames.com) — [browse game translations](https://unitygametranslator.asymptomatikgames.com/games) · [user documentation](https://unitygametranslator.asymptomatikgames.com/docs)

> This README covers the technical side (stack, architecture, installation, configuration). For the user guide — what the mod does, editors, collaboration — see the [documentation on the website](https://unitygametranslator.asymptomatikgames.com/docs).

## Features

### Web Platform
- **Browse translations** by game, language, and popularity
- **Upload translation files** with automatic game detection (Steam, Epic, GOG)
- **Fork translations** to improve existing work
- **Merge contributions** — Main owners review and merge Branches
- **Branch rating** — Main owners rate contributor quality
- **Inline editing** — edit translations directly on the website with tag selection
- **Live edit sessions** — edit your LOCAL translation file in the browser while playing: search & replace, filters, quality bar, keyboard review; saves are hot-reloaded in-game via SSE, no account needed
- **Private AI retranslation** — the browser asks the mod (through the session stream) to retranslate a line with the player's own backend; no API key or LLM config is ever stored on the site
- **Merge preview** — visual diff between local (mod) and server translations
- **Vote system** to highlight quality translations
- **Report system** for moderation
- **Profile management** with GDPR data export and account deletion
- **Multi-language UI** (see `config/locales.php` for the current list — the count is read from
  there everywhere, never written down)
- **Admin dashboard** with analytics, user management, and moderation

### Collaboration Model (Main/Branch/Fork)

| Term | Description |
|------|-------------|
| **Main** | The reference translation, owned by its creator and public on the website. |
| **Branch** | A contributor's improvements, linked to the Main and reviewed by its owner. One per user per UUID. |
| **Fork** | An independent translation (new lineage): its creator becomes Main owner, no longer linked to the original. |

**Workflow:**
1. User A uploads → becomes **Main** owner
2. User B downloads, improves, uploads → creates a **Branch**
3. User A reviews Branches, rates contributors, and merges contributions

**Constraints:**
- One Main per UUID (first uploader wins)
- One Branch per user per UUID (updating replaces your Branch)
- Languages locked after first upload (source/target immutable)

### Translation tags (H/V/A/S)

| Tag | Name | Description |
|-----|------|-------------|
| **H** | Human | Written by a human |
| **V** | Validated | Machine wording a human read and accepted |
| **A** | AI | Machine wording nobody has read yet |
| **S** | Skip | A human ruled that this line stays as it is — a fictional language, a proper name, text that must not change. Counts as settled, never as work left to do |
| **M** | Mod | The mod's own interface. Counted nowhere, arbitrated by no merge. Current mods keep it in a file of their own and never send it; the tag stays valid for files published before that |

### The four measures

Published in full at `/docs`, formulas and constants included: every figure the site shows about a
translation comes from one of these, and whoever is being measured is entitled to read the measure.

| Measure | Formula | Answers | Shown to |
|---------|---------|---------|----------|
| Review stage | `(H + V + S) / (H + V + S + A)` | Has a human been through it? | Everyone |
| Review rate | `(H + S + c × V) / (H + V + S + A)`, `c` from 0.8 to 1.0 | How well evidenced is that? | The author |
| Game coverage | resolved lines ÷ largest of the game's translations | How much of the game does it reach? | Everyone |
| Ordering | `coverage × (0.5 + 0.5 × rate)`, then reception and freshness | Which one first? | Nobody — it only sorts |

The 0-3 average this replaced answered "where does each line come from" when the question is "has
anyone read this": untouched machine output scored a third of the scale, a file reviewed line by
line stopped at two thirds unless its author retyped what the machine had right, and it was blind
to how much of the game a file reached.

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

**OAuth providers:** Google, GitHub, Discord, Twitch, Steam. Epic Games is wired in code but disabled on the live site (no API credentials — pending Epic developer approval); self-hosters with their own Epic credentials can enable it.

**Device Flow** for Unity mod: mod displays a code, user enters it at `/link`, mod receives API token via SSE stream.

## Tech Stack

- **Framework:** Laravel 12 (PHP 8.2+)
- **Real-time:** Node.js SSE micro-server + Redis pub/sub
- **Database:** MySQL / MariaDB (SQLite also works, for a small local install)
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
| `GET /edit-session/:token/stream` | Token | Live edit session: browser saves and retranslate requests streamed to the mod |
| `GET /health` | None | Health check |

## Requirements

- PHP 8.2+ with `phpredis` extension
- Composer
- Node.js 18+
- Redis 6+
- MySQL 8 / MariaDB 10.6+ — or SQLite for a small local install

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
# Point DB_* at your MySQL/MariaDB server, or keep the SQLite default:
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

# Optional — provider wired in code but disabled without credentials
EPICGAMES_CLIENT_ID=
EPICGAMES_CLIENT_SECRET=
```

| Provider | Console |
|----------|---------|
| Google | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |
| GitHub | [GitHub Developer Settings](https://github.com/settings/developers) |
| Discord | [Discord Developer Portal](https://discord.com/developers/applications) |
| Twitch | [Twitch Developer Console](https://dev.twitch.tv/console/apps) |
| Steam | [Steam Web API Key](https://steamcommunity.com/dev/apikey) |
| Epic Games | [Epic Games Developer Portal](https://dev.epicgames.com/portal) |

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
| `HOST` | `127.0.0.1` | Bind address. Loopback by default — this server belongs behind a TLS-terminating reverse proxy. Set `0.0.0.0` when the proxy is on another host or in a container. |
| `REDIS_URL` | `redis://127.0.0.1:6379` | Redis connection (TCP) |
| `REDIS_SOCKET` | — | Redis Unix socket (overrides URL) |
| `LARAVEL_API_URL` | `http://localhost:8000/api/v1` | Laravel API for token validation |
| `ALLOWED_ORIGIN` | — | CORS origin |
| `PER_IP_LIMIT` | `30` | Max open SSE streams per IP. One player runs one stream per game plus one per live edit session, and shared NAT adds up. |
| `MAX_CONNECTIONS` | `1000` | Global limit on open streams. A guard rail against a runaway reconnection loop, not a prediction: measured on the host, nothing caps concurrency in the low hundreds (see the note in `server.js`). |
| `PER_IP_ATTEMPTS_PER_MINUTE` | `60` | Stream *attempts* per IP per minute, counted on arrival whatever their outcome — what bounds a flood of junk tokens. Sixty is what one machine reaches with three streams reconnecting at the client's `retry: 3000`; beyond it the client gets a 429 and simply retries later. |
| `HEALTH_TOKEN` | — | Shared secret. When set, `/health` answers its capacity figures (open streams, limits, refusal counters) only to a request carrying it as `X-Health-Token`; everybody else gets the status and uptime alone. The site sends it from `SSE_HEALTH_TOKEN` in its `.env` — same value on both sides. Unset, the figures are public. |
| `REVALIDATE_INTERVAL_MS` | `300000` | How often an open sync stream re-checks that its access still exists, so a revoked access is cut within that time. |
| `HEARTBEAT_INTERVAL_MS` | `15000` | SSE heartbeat, which also renews a live edit session's presence key. |

The Laravel side of the health check is `SSE_HEALTH_URL` (where the admin analytics page reads the relay's figures) and `SSE_HEALTH_TOKEN` (the same secret as the relay's `HEALTH_TOKEN`), both in the site's `.env`. Leave them empty and the analytics page simply shows no stream counters.

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

### Tests and the database engine

The suite runs against **MySQL/MariaDB by default**, on the engine production runs on, using a
database of its own (`unitygametranslator_test` on `127.0.0.1:33306`). Create it once, and the
suite takes care of the rest — it rebuilds the schema on every run.

`phpunit.xml` only sets defaults: PHPUnit never overwrites a variable the environment already
carries. To run the suite on SQLite instead, with no file to edit:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

## Supported Languages

Arabic, Chinese, Dutch, English, French, German, Hebrew, Hindi, Indonesian, Italian, Japanese, Korean, Polish, Portuguese, Russian, Spanish, Thai, Turkish, Vietnamese

## Related

Five repositories, one product — [see it live][live].

- [UnityGameTranslator][mod] — the mod that translates a game while you play
- [unitygametranslator-manager][manager] — the desktop tool that finds your games and sets the mod up
- [unitygametranslator-common][common] — the rules the mod and the Manager both answer to, written once
- [unitygametranslator-catalogs][catalogs] — reference data this site reads too: languages, AI models, mod loaders

[mod]: https://github.com/djethino/UnityGameTranslator
[manager]: https://github.com/djethino/unitygametranslator-manager
[common]: https://github.com/djethino/unitygametranslator-common
[catalogs]: https://github.com/djethino/unitygametranslator-catalogs
[live]: https://unitygametranslator.asymptomatikgames.com

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
