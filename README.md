# CheckyBot

<p align="center">
  <strong>A comprehensive monitoring platform for websites, APIs, servers, and more.</strong>
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#configuration">Configuration</a> •
  <a href="#testing">Testing</a> •
  <a href="#contributing">Contributing</a> •
  <a href="#license">License</a>
</p>

---

## Features

### Website Monitoring
- **Uptime Monitoring** - Track website availability with customizable check intervals
- **SSL Certificate Monitoring** - Get notified before certificates expire
- **Outbound Link Checking** - Monitor external links for broken URLs
- **SEO Health Checks** - Comprehensive SEO auditing with automated crawling

### API Monitoring
- **Endpoint Testing** - Monitor API endpoints with custom assertions
- **Response Validation** - Validate JSON responses with flexible assertion types:
  - Existence checks
  - Type validation
  - Value comparisons
  - Array length checks
  - Regex pattern matching
- **Performance Tracking** - Monitor response times and status codes
- **Failed Response Storage** - Optionally save failed API responses for debugging

### Server Monitoring
- **Resource Monitoring** - Track CPU, RAM, and disk usage
- **Custom Rule Engine** - Define thresholds and get alerts when exceeded
- **Log File Monitoring** - Track and analyze server log files
- **Ploi Integration** - Import servers and sites from Ploi

### Notifications
- **Multi-Channel Support** - Email, webhooks, Slack, Discord
- **Flexible Scoping** - Global or per-website notification settings
- **Smart Filtering** - Choose which checks trigger notifications

### Additional Features
- **API Key Management** - Secure API authentication with Sanctum
- **User Management** - Role-based access control with Filament Shield
- **Queue System** - Background job processing with Laravel Horizon
- **Real-time Updates** - WebSocket support with Laravel Reverb
- **Beautiful Admin Panel** - Built with Filament v4
- **Telescope Integration** - Debug and monitor application performance

---

## Requirements

- **PHP:** 8.3 or higher
- **Database:** MySQL 8.0+ or PostgreSQL 13+
- **Redis:** For queues and caching
- **Node.js:** 18+ (for frontend assets)
- **Composer:** 2.5+

### PHP Extensions
- `ext-dom`
- `ext-curl`
- `ext-libxml`
- `ext-mbstring`
- `ext-zip`
- `ext-pcntl`
- `ext-pdo`
- `ext-bcmath`
- `ext-intl`
- `ext-gd`

---

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/userlip/checkybot.git
cd checkybot
```

### 2. Install Dependencies

```bash
composer install
npm install
```

### 3. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure your database, Redis, and mail settings:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=checkybot
DB_USERNAME=your_username
DB_PASSWORD=your_password

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
```

### 4. Database Setup

```bash
php artisan migrate --seed
```

### 5. Build Frontend Assets

```bash
npm run build
```

### 6. Start Queue Workers

```bash
php artisan horizon
```

### 7. Start Development Server

```bash
php artisan serve
```

Visit `http://localhost:8000` to access the application.

---

## Configuration

### Monitoring Intervals

Configure check intervals in your `.env`:

```env
# Supported intervals: 1m, 5m, 10m, 15m, 30m, 1h, 6h, 12h, 1d
UPTIME_CHECK_INTERVAL=5m
SSL_CHECK_INTERVAL=1d
```

### Queue Configuration

CheckyBot uses Laravel Horizon for queue management. Configure workers in `config/horizon.php`.

### Notification Channels

Configure notification channels in the admin panel under **Settings > Notification Channels**.

Supported webhook types:
- Slack
- Discord
- Generic webhooks with custom payloads

---

## Testing

CheckyBot uses **Pest 4** for testing with comprehensive test coverage.

### Run All Tests

```bash
vendor/bin/pest
```

### Run with Parallel Execution

```bash
vendor/bin/pest --parallel
```

### Run Specific Test Suite

```bash
vendor/bin/pest tests/Feature/Api/V1
vendor/bin/pest tests/Unit/Models
```

### Generate Coverage Report

```bash
vendor/bin/pest --coverage --min=80
```

### Test Statistics

- **553 tests** across Feature and Unit suites
- **1158+ assertions**
- **Full coverage** of monitoring, API, and server functionality

---

## API Usage

CheckyBot provides a REST API for programmatic access.

### Authentication

All API requests require an API key:

```bash
curl -H "Authorization: Bearer your-api-key" \
  https://your-domain.com/api/v1/websites
```

### Create API Key

Generate an API key in the admin panel under **Settings > API Keys**.

### Example: Sync Checks from Package

```bash
curl -X POST https://your-domain.com/api/v1/projects/{project}/checks/sync \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "uptime_checks": [
      {
        "name": "homepage",
        "url": "https://example.com",
        "interval": "5m"
      }
    ],
    "ssl_checks": [],
    "api_checks": []
  }'
```

### API Documentation

Full API documentation is available at `/api/documentation` when running the application.

---

## Scheduling

Add to your crontab for scheduled checks:

```cron
* * * * * cd /path-to-checkybot && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler handles:
- Uptime checks
- SSL certificate checks
- API monitoring
- SEO health checks
- Server resource monitoring
- Log file purging

---

## Architecture

CheckyBot is built on modern Laravel best practices:

- **Laravel 12** - Latest framework features
- **Filament 4** - Beautiful admin panel
- **Pest 4** - Modern PHP testing
- **Laravel Horizon** - Queue monitoring
- **Laravel Telescope** - Application debugging
- **Laravel Sanctum** - API authentication
- **Spatie Packages** - SSL checking, crawling, DNS resolution

### Key Components

- **Jobs:** Background tasks for monitoring checks
- **Commands:** Scheduled tasks via Artisan
- **Services:** Business logic layer
- **Resources:** Filament admin resources
- **Crawlers:** SEO and link checking

---

## Contributing

Contributions are welcome! Please follow these guidelines:

### Development Setup

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Make your changes
4. Run tests: `vendor/bin/pest`
5. Run code style: `vendor/bin/pint`
6. Commit changes: `git commit -m 'Add amazing feature'`
7. Push to branch: `git push origin feature/amazing-feature`
8. Open a Pull Request

### Code Style

CheckyBot uses Laravel Pint for code formatting:

```bash
vendor/bin/pint
```

### Testing Requirements

- All new features must include tests
- Maintain or improve code coverage
- Follow existing test patterns (Pest 4 syntax)

### Pull Request Process

1. Update documentation for any new features
2. Ensure all tests pass
3. Follow conventional commit messages
4. Request review from maintainers

---

## Security

If you discover a security vulnerability, please email security@checkybot.com instead of using the issue tracker.

---

## Roadmap

- [ ] Browser-based visual regression testing (Pest 4)
- [ ] Mobile app monitoring
- [ ] Synthetic transaction monitoring
- [ ] Advanced alerting rules
- [ ] Multi-tenant support
- [ ] Grafana integration
- [ ] Prometheus metrics export

---

## Support

- **Documentation:** [https://docs.checkybot.com](https://docs.checkybot.com)
- **Issues:** [GitHub Issues](https://github.com/userlip/checkybot/issues)
- **Discussions:** [GitHub Discussions](https://github.com/userlip/checkybot/discussions)

---

## License

CheckyBot is open-source software licensed under the [MIT license](LICENSE).

---

## Credits

Built with love by the CheckyBot Labs team.

Special thanks to:
- [Laravel](https://laravel.com) - The PHP framework for web artisans
- [Filament](https://filamentphp.com) - Beautiful admin panels
- [Pest](https://pestphp.com) - Elegant PHP testing
- [Spatie](https://spatie.be) - Amazing Laravel packages
- All our [contributors](https://github.com/userlip/checkybot/graphs/contributors)

---

<p align="center">
  Made with ❤️ for the developer community
</p>
