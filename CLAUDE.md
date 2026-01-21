# CLAUDE.md - OJS Development Guide

This file provides guidance for AI assistants working with Open Journal Systems (OJS).

## Project Overview

OJS (Open Journal Systems) is open-source journal management software by the Public Knowledge Project (PKP). It's a PHP 8.2+ / Vue 3 application supporting MySQL, MariaDB, and PostgreSQL.

**Version**: 3.6.0
**License**: GNU GPL v3

## Quick Reference

### Build Commands

```bash
# Frontend development (watch mode)
npm run dev                  # Backend build with Vite
npm run dev:frontend         # Frontend-only build

# Production builds
npm run build                # Full build (backend + frontend)
npm run build:backend        # Backend Vue components only
npm run build:frontend       # Frontend reader UI only

# Code quality
npm run lint                 # ESLint fix for JS/Vue files
npm run format               # Prettier formatting
npm run php-fix              # PHP CS Fixer (PSR-12)
```

### Testing Commands

```bash
# Cypress E2E tests
npx cypress run              # Run all tests
npx cypress open             # Interactive mode
npx cypress run --spec "cypress/tests/integration/*.cy.js"

# PHP unit tests
php lib/pkp/lib/vendor/bin/phpunit tests/
```

### Composer (PHP Dependencies)

```bash
composer -d lib/pkp install
composer -d lib/pkp check-platform-reqs
```

## Architecture

### Directory Structure

```
ojs/
├── api/v1/              # REST API endpoints (38+ routes)
├── classes/             # PHP application classes
│   ├── core/            # Application, Request, PageRouter, ServiceProvider
│   ├── article/         # Article/submission management
│   ├── issue/           # Journal issue management
│   ├── journal/         # Journal context management
│   ├── migration/       # Database migrations
│   └── [domain]/        # Other domain classes
├── pages/               # Page request handlers
├── controllers/         # API/Grid controllers
├── js/                  # Vue frontend
│   ├── load.js          # Main entry point (backend UI)
│   ├── load_frontend.js # Frontend-only entry
│   └── build.js         # Compiled output
├── lib/
│   ├── pkp/             # PKP framework (git submodule)
│   └── ui-library/      # Shared Vue components (git submodule)
├── plugins/             # Plugin system
├── templates/           # Smarty templates
├── locale/              # i18n (79+ languages)
├── cypress/             # E2E tests
├── tests/               # PHP unit tests
└── jobs/                # Background job classes
```

### Key Patterns

**PHP Namespacing**: All classes use `APP\` or `PKP\` namespaces
```php
namespace APP\core;
use PKP\core\PKPApplication;
```

**Request Flow**:
```
index.php → lib/pkp/includes/bootstrap.php → Application::get()->execute()
  → PageRouter → Request Handler → Template + Vue Components
```

**Dependency Injection**: Laravel-style service container
```php
app()->get('service_name')  // Preferred
Services::get()             // Legacy (deprecated)
```

**Vue Components**: Registered globally via VueRegistry
```javascript
import Container from '@/components/Container/Container.vue';
VueRegistry.registerComponent('ComponentName', Component);
// '@/' aliases to 'lib/ui-library/src/'
```

## Code Conventions

### PHP
- PSR-12 coding standard
- Namespace pattern: `APP\{directory}\{Class}` or `PKP\{directory}\{Class}`
- Database access via Eloquent-style ORM and DAO pattern
- Events via observer pattern in `classes/observers/`

### JavaScript/Vue
- Vue 3 Composition API
- Pinia for state management
- Tailwind CSS for styling
- Components in `lib/ui-library/src/components/`

### Pre-commit Hooks
Husky runs lint-staged automatically on commit:
- PHP files: PHP CS Fixer validation
- JS/Vue files: ESLint + Prettier

## Configuration

Copy `config.TEMPLATE.inc.php` to `config.inc.php` and configure:
- Database connection (`[database]` section)
- Base URL (`[general]` section)
- Email settings (`[mail]` section)

## Git Submodules

Key external repositories:
- `lib/pkp` - PKP framework core
- `lib/ui-library` - Shared Vue UI components
- Various plugins in `plugins/generic/` and `plugins/blocks/`

## API

REST API available at `/api/v1/{resource}`:
- Submissions, users, contexts, issues, publications
- JSON request/response format
- Authentication via session or API key

## Plugin System

Plugin types in `plugins/`:
- `generic/` - General purpose
- `themes/` - Presentation themes
- `blocks/` - Homepage blocks
- `reports/` - Analytics reports
- `paymethod/` - Payment integrations

## File Issues

Report bugs at: https://github.com/pkp/pkp-lib/issues/
