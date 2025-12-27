# UnityGameTranslator Website

Community platform for sharing Unity game translation files with API for mod synchronization.

**Live site:** [unitygametranslator.asymptomatikgames.com](https://unitygametranslator.asymptomatikgames.com)

## Features

### Web Platform
- **Browse translations** by game, language, and popularity
- **Upload translation files** from the Unity mod
- **Fork translations** to improve existing work
- **Vote system** to highlight quality translations
- **Report system** for moderation
- **Automatic fork detection** via file UUID lineage
- **Multi-language UI** (12 languages with flag emojis for 112 translation languages)
- **Modern gamer UI** - organic animated background, glassmorphism cards, responsive design

### API for Unity Mod
- **Search translations** by Steam ID, game name, or language
- **Download translations** with ETag caching
- **Check for updates** without downloading full file
- **Upload translations** with authentication
- **Device Flow authentication** (like Netflix/Spotify)
- **Rate limiting** per endpoint

## Tech Stack

- **Framework:** Laravel 12
- **Database:** SQLite (dev) / MySQL (prod)
- **Auth:** Laravel Socialite (Steam, Epic Games, Google, GitHub, Discord, Twitch)
- **Frontend:** Tailwind CSS 4, Alpine.js
- **I18n:** 12 languages with language flags (emoji)

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+
- SQLite or MySQL

## Installation

```bash
# Clone and install
git clone https://github.com/djethino/UnityGameTranslator-website.git
cd UnityGameTranslator-website

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Create database
touch database/database.sqlite
php artisan migrate

# Build assets
npm run build
```

## Configuration

### OAuth Providers

Configure in `.env`:

```env
# Steam (Web API key, no client_id)
STEAM_CLIENT_SECRET=your_web_api_key

# Epic Games
EPICGAMES_CLIENT_ID=your_client_id
EPICGAMES_CLIENT_SECRET=your_client_secret

# Google
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret

# GitHub
GITHUB_CLIENT_ID=your_client_id
GITHUB_CLIENT_SECRET=your_client_secret

# Discord
DISCORD_CLIENT_ID=your_client_id
DISCORD_CLIENT_SECRET=your_client_secret

# Twitch
TWITCH_CLIENT_ID=your_client_id
TWITCH_CLIENT_SECRET=your_client_secret
```

### Where to get credentials

| Provider | Console |
|----------|---------|
| Steam | [Steam Partner Network](https://partner.steamgames.com/) → Web API key |
| Epic Games | [Epic Games Developer Portal](https://dev.epicgames.com/portal) |
| Google | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |
| GitHub | [GitHub Developer Settings](https://github.com/settings/developers) |
| Discord | [Discord Developer Portal](https://discord.com/developers/applications) |
| Twitch | [Twitch Developer Console](https://dev.twitch.tv/console/apps) |

## Development

```bash
# Start dev server (runs server, queue, logs, and Vite)
composer dev

# Or start individually
php artisan serve
npm run dev
```

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

## API Reference

All endpoints are prefixed with `/api/v1/`.

### Public Endpoints (No Auth)

#### Search Translations
```http
GET /api/v1/translations?steam_id=367520&lang=fr
GET /api/v1/translations?game=hollow-knight&lang=fr

Response 200:
{
  "available": true,
  "translations": [
    {
      "id": 123,
      "uuid": "abc-123",
      "uploader": "user1",
      "votes": 42,
      "entries_count": 1500,
      "type": "ai_reviewed",
      "hash": "sha256:abc123",
      "updated_at": "2025-01-15T10:30:00Z"
    }
  ],
  "recommended": 123
}
```
Rate limit: 60/min

#### Check for Updates
```http
GET /api/v1/translations/{id}/check
If-None-Match: "sha256:abc123"

Response 200:
{
  "hash": "sha256:def456",
  "updated_at": "2025-01-15T10:30:00Z",
  "entries_count": 1687,
  "has_update": true
}

Response 304: (no changes)
```
Rate limit: 120/min

#### Download Translation
```http
GET /api/v1/translations/{id}/download
Accept-Encoding: gzip

Response 200:
Content-Type: application/json
Content-Encoding: gzip
ETag: "sha256:abc123"

{ ... translations.json ... }
```
Rate limit: 30/min

#### List Games
```http
GET /api/v1/games?q=hollow

Response 200:
{
  "games": [
    { "id": 1, "name": "Hollow Knight", "steam_id": "367520", ... }
  ]
}
```
Rate limit: 60/min

### Device Flow Authentication

#### Initiate Login
```http
POST /api/v1/auth/device
Content-Type: application/json

{ "client_id": "unity-mod" }

Response 200:
{
  "device_code": "xyz789",
  "user_code": "ABC-123",
  "verification_uri": "https://unitygametranslator.asymptomatikgames.com/link",
  "expires_in": 900,
  "interval": 5
}
```
Rate limit: 10/min

#### Poll for Completion
```http
POST /api/v1/auth/device/poll
Content-Type: application/json

{ "device_code": "xyz789" }

Response 200 (pending):
{ "status": "pending" }

Response 200 (complete):
{
  "status": "complete",
  "access_token": "ugt_abc123...",
  "user": { "id": 42, "name": "MonPseudo" }
}

Response 400 (expired):
{ "error": "expired_token" }
```
Rate limit: 12/min

### Authenticated Endpoints

All require `Authorization: Bearer ugt_xxx` header.

#### Get Current User
```http
GET /api/v1/me

Response 200:
{
  "id": 42,
  "name": "MonPseudo",
  "email": "user@example.com"
}
```
Rate limit: 60/min

#### Get User's Translations
```http
GET /api/v1/me/translations

Response 200:
{
  "translations": [ ... ]
}
```
Rate limit: 60/min

#### Check UUID
```http
POST /api/v1/translations/check-uuid
Content-Type: application/json

{ "uuid": "abc-123-def" }

Response 200 (not found):
{ "exists": false }

Response 200 (user owns it):
{
  "exists": true,
  "is_owner": true,
  "translation": { "id": 123, "type": "ai", ... }
}

Response 200 (another user owns it):
{
  "exists": true,
  "is_owner": false,
  "original": { "id": 456, "uploader": "user1", ... }
}
```
Rate limit: 60/min

#### Upload Translation
```http
POST /api/v1/translations
Content-Type: application/json

{
  "steam_id": "367520",
  "game_name": "Hollow Knight",
  "source_language": "English",
  "target_language": "French",
  "type": "ai",
  "status": "in_progress",
  "notes": "Complete translation",
  "content": "{\"_uuid\":\"abc-123\",\"Hello\":\"Bonjour\",...}"
}

Response 201 (new/fork):
{
  "success": true,
  "translation": {
    "id": 456,
    "file_hash": "sha256:...",
    "line_count": 1500,
    "is_fork": false
  }
}

Response 200 (update):
{
  "success": true,
  "translation": {
    "id": 123,
    "file_hash": "sha256:...",
    "is_update": true
  }
}
```

**Upload behavior:**
- `_uuid` in content determines action (call check-uuid first)
- NEW: if UUID not found → user becomes owner
- UPDATE: if user owns the UUID → replaces content
- FORK: if another user owns UUID → creates new translation with same UUID, `parent_id` set

**Languages:** On UPDATE/FORK, server keeps original languages (ignores request).

Rate limit: 10/min

#### Revoke Token
```http
DELETE /api/v1/auth/token

Response 200:
{ "message": "Token revoked" }
```
Rate limit: 60/min

### Rate Limiting

All responses include headers:
```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1703505600
```

When rate limited (429):
```http
Retry-After: 60
```

## Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── GameController.php          # Web: game listing
│   │   ├── TranslationController.php   # Web: upload, download, fork
│   │   ├── AuthController.php          # Web: OAuth authentication
│   │   ├── Admin/                      # Admin moderation
│   │   └── Api/                        # API endpoints
│   │       ├── TranslationController.php  # search, check, download, store
│   │       ├── GameController.php         # index, show
│   │       ├── DeviceFlowController.php   # Device Flow auth
│   │       └── UserController.php         # me, translations
│   └── Middleware/
│       └── AuthenticateApi.php         # Bearer token validation
├── Models/
│   ├── Game.php                        # Games with steam_id
│   ├── Translation.php                 # Translation files with file_hash
│   ├── User.php                        # Users with OAuth
│   ├── Report.php                      # Content reports
│   ├── ApiToken.php                    # Permanent API tokens
│   └── DeviceCode.php                  # Temporary Device Flow codes
routes/
├── web.php                             # Web routes
└── api.php                             # API v1 routes
resources/
├── views/
│   └── auth/
│       └── link.blade.php              # Device Flow code entry page
└── lang/                               # UI translations (12 languages)
```

## Database Schema

### Key Tables

#### games
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | string | Game name |
| slug | string | URL-friendly name |
| steam_id | string | Steam App ID (nullable) |
| rawg_id | string | RAWG API ID (nullable) |

#### translations
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| game_id | bigint | Foreign key to games |
| user_id | bigint | Uploader |
| parent_id | bigint | Fork source (nullable) |
| file_uuid | string | Unique file identifier |
| file_hash | string | SHA256 of content |
| source_language | string | e.g., "en" |
| target_language | string | e.g., "fr" |
| type | enum | ai_unreviewed, ai_reviewed, human |
| entries_count | int | Number of translations |

#### api_tokens
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Token owner |
| token | string | Hashed token (prefix: ugt_) |
| name | string | Token name |
| last_used_at | timestamp | Last API call |

#### device_codes
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| device_code | string | Secret code for polling |
| user_code | string | User-facing code (ABC-123) |
| user_id | bigint | Linked user when validated |
| expires_at | timestamp | Expiration time |

## Related

- **Unity Mod:** [github.com/djethino/UnityGameTranslator](https://github.com/djethino/UnityGameTranslator)

## Acknowledgments

This project is built with amazing open-source technologies:

### Backend
- **[Laravel](https://laravel.com/)** - The PHP framework for web artisans
- **[Laravel Socialite](https://laravel.com/docs/socialite)** - OAuth authentication

### Frontend
- **[Tailwind CSS](https://tailwindcss.com/)** - Utility-first CSS framework
- **[Alpine.js](https://alpinejs.dev/)** - Lightweight JavaScript framework
- **[Font Awesome](https://fontawesome.com/)** - Icon library

### OAuth Providers
- **[SocialiteProviders](https://socialiteproviders.com/)** - Community Socialite providers for Steam, Discord, Twitch, Epic Games

Special thanks to the Laravel and open-source community.

## License

MIT License - see [LICENSE](LICENSE) for details.
