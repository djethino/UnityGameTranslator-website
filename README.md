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
- **Multi-language UI** (12 languages)
- **Admin dashboard** with analytics and moderation tools

### Collaboration Model (Main/Branch)

The website uses a Main/Branch model for collaborative translation:

| Role | Description |
|------|-------------|
| **Main** | Original uploader. Owns the translation and can merge branches. |
| **Branch** | Contributor who forked the Main to add improvements. |

**Workflow:**
1. User A uploads → becomes **Main** owner
2. User B downloads, improves, uploads → creates a **Branch**
3. User A sees branches on their translation page
4. User A can review and merge contributions from branches

**Constraints:**
- One Main per UUID (first uploader wins)
- One Branch per user per UUID (updating replaces your branch)
- Languages locked after first upload (source/target immutable)
- UUID links all translations in a "lineage" (Main + all Branches)

### API for Unity Mod
- **Search translations** by Steam ID, game name, or language
- **Download translations** with ETag caching
- **Check for updates** without downloading full file
- **Upload translations** with authentication
- **Device Flow authentication** (enter code on website to link mod)
- **Rate limiting** per endpoint

## Tech Stack

- **Framework:** Laravel 12
- **Database:** SQLite (dev) / MySQL (prod)
- **Auth:** Laravel Socialite (Steam, Epic Games, Google, GitHub, Discord, Twitch)
- **Frontend:** Tailwind CSS 4, Alpine.js, Font Awesome

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+
- SQLite or MySQL

## Installation

```bash
composer setup
```

The `composer setup` command handles everything: dependencies, environment file, database migration, and asset building.

### Manual installation

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
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

## Related

- **Unity Mod:** See the `UnityGameTranslator/` directory in this repository

## Acknowledgments

### Backend
- **[Laravel](https://laravel.com/)** - PHP framework
- **[Laravel Socialite](https://laravel.com/docs/socialite)** - OAuth authentication
- **[SocialiteProviders](https://socialiteproviders.com/)** - Community OAuth providers

### Frontend
- **[Tailwind CSS](https://tailwindcss.com/)** - Utility-first CSS framework
- **[Alpine.js](https://alpinejs.dev/)** - Lightweight JavaScript framework
- **[Font Awesome](https://fontawesome.com/)** - Icon library

## License

This project is dual-licensed:

- **Open Source:** [AGPL-3.0](LICENSE) - Free for open source use
- **Commercial:** Contact us for proprietary/commercial use

See [LICENSING.md](LICENSING.md) for details.
