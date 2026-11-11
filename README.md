<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/logo-dark.svg">
    <img alt="Databasify" src=".github/assets/logo-light.svg" height="48">
  </picture>
</h1>

**Open-source, self-hostable DBaaS alternative to DigitalOcean Managed Databases, Amazon RDS & Aiven.**

[![Tests](https://github.com/databasifylabs/databasify/actions/workflows/tests.yml/badge.svg)](https://github.com/databasifylabs/databasify/actions/workflows/tests.yml)
[![Laravel 13.x](https://img.shields.io/badge/Laravel-13.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/docs/13.x)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.5/)
[![React 19](https://img.shields.io/badge/React-19-149ECA?logo=react&logoColor=white)](https://react.dev)
[![GitHub stars](https://img.shields.io/github/stars/databasifylabs/databasify?style=social)](https://github.com/databasifylabs/databasify)

An open-source, self-hostable DBaaS alternative to DigitalOcean Managed Databases, Amazon RDS & Aiven that lets you run PostgreSQL, MySQL, MariaDB, MongoDB, Valkey and Redis, from a single node to a highly available cluster with automatic failover, point-in-time backups and TLS, on your own servers.

**[Read the documentation →](https://docs.databasify.io)**

<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".github/assets/dashboard-dark.png">
  <img alt="The Databasify dashboard" src=".github/assets/dashboard-light.png">
</picture>

## Features

- MySQL 8.4 and 9.7, MariaDB 10.11, 11.4, 11.8 and 12.3, PostgreSQL 15 to 18, MongoDB 7.0, 8.0 and 9.0, Valkey 7, 8 and 9, Redis 7.2 and 8
- A single primary, or a cluster with standbys, automatic failover and read-only nodes
- Hostnames on your own domain and TLS everywhere
- Connection pools, users, metrics, logs and email alerts
- Migrations from an existing database
- Automatic updates in a weekly maintenance window
- Servers over SSH, or created on DigitalOcean, Hetzner, Vultr or Linode
- Plugins for more DNS providers, server providers and community database engines

## Engines

Each engine lives in its own repository and ships as container images built on the public [engine SDK](https://github.com/databasifylabs/engine-sdk): [PostgreSQL](https://github.com/databasifylabs/engine-postgres), [MySQL](https://github.com/databasifylabs/engine-mysql), [MariaDB](https://github.com/databasifylabs/engine-mariadb), [MongoDB](https://github.com/databasifylabs/engine-mongodb), [Valkey](https://github.com/databasifylabs/engine-valkey) and [Redis](https://github.com/databasifylabs/engine-redis). To add your own, start from [engine-template](https://github.com/databasifylabs/engine-template) and register it with a plugin made from [plugin-example-database](https://github.com/databasifylabs/plugin-example-database).

## Installation

On a Linux server with at least 1 GB of memory (2 GB recommended):

```bash
curl -fsSL https://get.databasify.io/install.sh | sudo bash
```

Update or uninstall the same way with `https://get.databasify.io/update.sh` and `https://get.databasify.io/uninstall.sh`.

## Development

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate && php artisan migrate
composer run dev
```

Run the checks with `php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint` and `npm run check`.

---

If Databasify saves you time, give it a ⭐ on [GitHub](https://github.com/databasifylabs/databasify). It helps others find it.
