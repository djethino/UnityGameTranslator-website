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

## Contributing

API documentation and project structure details are available in the codebase for contributors.

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

This project is dual-licensed:

- **Open Source:** [AGPL-3.0](LICENSE) - Free for open source use
- **Commercial:** Contact us for proprietary/commercial use

See [LICENSING.md](../LICENSING.md) for details.
