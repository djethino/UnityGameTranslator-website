# UnityGameTranslator Website

Community platform for sharing Unity game translation files.

**Live site:** [unitygametranslator.asymptomatikgames.com](https://unitygametranslator.asymptomatikgames.com)

## Features

- **Browse translations** by game, language, and popularity
- **Upload translation files** from the Unity mod
- **Fork translations** to improve existing work
- **Vote system** to highlight quality translations
- **Report system** for moderation
- **Automatic fork detection** via file UUID lineage
- **Multi-language UI** (12 languages supported)

## Tech Stack

- **Framework:** Laravel 12
- **Database:** SQLite (dev) / MySQL (prod)
- **Auth:** Laravel Socialite (Steam, Epic Games, Discord, Twitch, GitHub, Google)
- **Frontend:** Tailwind CSS, Alpine.js

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
# Steam
STEAM_CLIENT_SECRET=your_steam_api_key

# Epic Games
EPICGAMES_CLIENT_ID=your_client_id
EPICGAMES_CLIENT_SECRET=your_client_secret

# Discord
DISCORD_CLIENT_ID=your_client_id
DISCORD_CLIENT_SECRET=your_client_secret

# Twitch
TWITCH_CLIENT_ID=your_client_id
TWITCH_CLIENT_SECRET=your_client_secret

# GitHub
GITHUB_CLIENT_ID=your_client_id
GITHUB_CLIENT_SECRET=your_client_secret

# Google
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
```

### Where to get credentials

| Provider | Console |
|----------|---------|
| Steam | [Steam Web API](https://steamcommunity.com/dev/apikey) |
| Epic Games | [Epic Developer Portal](https://dev.epicgames.com/portal) |
| Discord | [Discord Developer Portal](https://discord.com/developers/applications) |
| Twitch | [Twitch Developer Console](https://dev.twitch.tv/console/apps) |
| GitHub | [GitHub Developer Settings](https://github.com/settings/developers) |
| Google | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |

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

## Project Structure

```
app/
├── Http/Controllers/
│   ├── GameController.php      # Game listing and search
│   ├── TranslationController.php # Upload, download, fork
│   ├── AuthController.php      # OAuth authentication
│   └── Admin/                  # Admin moderation
├── Models/
│   ├── Game.php               # Steam/Epic games
│   ├── Translation.php        # Translation files
│   ├── User.php               # Users with OAuth
│   └── Report.php             # Content reports
resources/
├── views/                     # Blade templates
└── lang/                      # UI translations (12 languages)
```

## Related

- **Unity Mod:** [github.com/djethino/UnityGameTranslator](https://github.com/djethino/UnityGameTranslator)

## License

MIT License - see [LICENSE](LICENSE) for details.
