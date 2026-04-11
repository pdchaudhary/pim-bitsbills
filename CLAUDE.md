# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Pimcore 12.3+ CMS/PIM skeleton** built on PHP 8.3/8.4 + Symfony 6.2/7.2+. The project uses Twig for templating, MariaDB for storage, Redis for caching, and RabbitMQ for async messaging.

## Commands

### Installation
```bash
composer install
vendor/bin/pimcore-install   # Initialize Pimcore with DB
```

### Docker (recommended)
```bash
docker compose up -d
docker compose exec php vendor/bin/pimcore-install
```

### Testing
```bash
vendor/bin/codecept run -vv              # All suites
vendor/bin/codecept run Functional       # Functional suite only
vendor/bin/codecept run Unit             # Unit suite only
vendor/bin/codecept run Unit Controller  # Single test folder
```

### Code Style
```bash
vendor/bin/php-cs-fixer fix src/         # Auto-fix PSR-1/2 style
vendor/bin/php-cs-fixer check src/       # Check without fixing
```

### Symfony Console
```bash
bin/console list                         # List all commands
bin/console cache:clear                  # Clear Symfony cache
bin/console debug:router                 # Show registered routes
```

### Product Class Setup
```bash
# Create/update all Pimcore data classes and object folders (run once after install)
bin/console app:setup-product-classes

# Regenerate PHP model files after any class change
bin/console pimcore:deployment:classes-rebuild
```

## Architecture

### Request Flow
```
public/index.php → Pimcore\Bootstrap → App\Kernel → Router → Controller → Twig
```

- `src/Kernel.php` extends `Pimcore\Kernel` and registers bundles
- Routes are defined via PHP attributes on controllers and `config/routes.yaml`
- Templates live in `templates/` (Twig)
- Generated Pimcore DataObject classes live in `var/classes/DataObject/` — do not edit these manually

### Key Source Files
- `src/Controller/DefaultController.php` — homepage route, renders `templates/default/default.html.twig`
- `src/Controller/Web2printController.php` — PDF/print rendering (uses Pimcore Print Manager)
- `src/EventSubscriber/BundleSetupSubscriber.php` — registers bundles during `pimcore-install`
- `src/Command/SetupProductClassesCommand.php` — creates all Pimcore data classes programmatically (see below)

### Data Model (Product Classes)
Three Pimcore DataObject classes are defined via `SetupProductClassesCommand`:

**Product** — central class for all product types with tabs:
- General: `name`, `sku`, `active`, `productGroup`, `countryOfOrigin`
- Descriptions: `shortDescription`, `extendedDescription`
- Pricing: `standardPrice`, `listPrice`, `mapPrice` (2 decimal places)
- Inventory: `availableQuantity`, `dropShipStatus` (N/Y/O), `plusFreight`, `avgLeadTime`
- Dimensions: product and shipping L/W/H, `itemWeight`, `itemNetWeight`
- Identifiers: `upcCode`, `dateEstablished`, `poDate`
- Media: `mainImage`, `images` (gallery)
- Relations: `categories` → Category, `supplier` / `additionalSuppliers` → Supplier
- Type Attributes: `typeAttributes` (ClassificationStore — see below)

**Category** — `name`, `description`, `image`, `categoryLevel` (Major/Minor), `parentCategory` (self-relation)

**Supplier** — `name`, `code`, `active`, drop-ship/wholesale flags, `defaultLeadTime`; Contact tab (email, phone, website); Address tab

**Classification Store: `ProductTypeAttributes`** — product-type-specific fields, activated per product:
- `DME` group: `hcpcCode`, `fdaClass`, `sterile`, `disposable`, `latexFree`, `rxRequired`, `medicareEligible`, `medicaidEligible`, `weightCapacity`, `warrantyPeriod`, `plusFreight`
- `Fragrances` group: `concentration`, `gender`, `scentFamily`, `topNotes`, `middleNotes`, `baseNotes`, `longevity`, `sillage`, `season`, `occasion`
- `Cosmetics` group: `skinType`, `formulaType`, `shade`, `spf`, cruelty-free/vegan/organic flags, `ingredients`, `usageInstructions`

Object folder tree created: `/Products/{DME,Fragrances,Cosmetics}`, `/Categories`, `/Suppliers`

### Configuration
- `config/services.yaml` — service autowiring; controllers and commands are auto-registered
- `config/packages/security.yaml` — firewall rules; admin at `/admin` requires `ROLE_PIMCORE_USER`
- `config/packages/messenger.yaml` — RabbitMQ async queue configuration
- `.php-cs-fixer.dist.php` — code style rules; note `no_php4_constructor` and `no_self_accessor` are disabled (required for Pimcore-generated models)

### Web2Print
The `Web2printController` handles PDF generation. Templates in `templates/web2print/` use CSS `@page` rules for print layout. Pimcore's Print Manager converts these to PDF.

### Environment Setup
Docker services: PHP-FPM, Nginx, MariaDB 10.11, Redis, RabbitMQ. The `.docker/supervisord.conf` manages the Symfony Messenger worker for async jobs. Environment-specific configs are in `config/packages/dev/`, `prod/`, `test/`.
