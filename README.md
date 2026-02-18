# MonkeysLegion DI

A production-ready **PSR-11** dependency injection container for PHP 8.4+ with auto-wiring, PHP attributes, interface binding, and compiled container support.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Installation

```bash
composer require monkeyscloud/monkeyslegion-di
```

## Quick Start

```php
use MonkeysLegion\DI\Container;

$container = new Container([
    LoggerInterface::class => fn() => new FileLogger('/var/log/app.log'),
]);

// Auto-wires dependencies automatically
$service = $container->get(UserService::class);
```

## Features

### Auto-Wiring

The container resolves constructor dependencies automatically by inspecting type hints — no configuration needed for concrete classes:

```php
class UserService {
    public function __construct(
        private UserRepository $repo,
        private LoggerInterface $logger,
    ) {}
}

// Just works — both dependencies are resolved recursively
$service = $container->get(UserService::class);
```

### Factory Definitions

Register services with factory closures for full control over instantiation:

```php
$container = new Container([
    PDO::class => fn() => new PDO('mysql:host=localhost;dbname=app', 'user', 'pass'),
    CacheInterface::class => fn(ContainerInterface $c) => new RedisCache(
        $c->get(Redis::class),
    ),
]);
```

### Runtime Registration

Register or override services at runtime:

```php
$container->set('mailer', fn() => new SmtpMailer($config));
$container->set(LoggerInterface::class, new NullLogger());
```

### Interface Binding

Map abstractions to concrete implementations:

```php
$container->bind(LoggerInterface::class, FileLogger::class);
$container->bind(CacheInterface::class, RedisCache::class);

// Now any class depending on LoggerInterface gets FileLogger
$service = $container->get(UserService::class);
```

### PHP 8.4 Attributes

#### `#[Inject]` — Override Parameter Resolution

```php
class NotificationService {
    public function __construct(
        #[Inject('slack.logger')]
        private LoggerInterface $logger,
    ) {}
}
```

#### `#[Transient]` — Fresh Instance Every Time

```php
#[Transient]
class RequestContext {
    public readonly string $id;
    
    public function __construct() {
        $this->id = uniqid('req_', true);
    }
}

// Each call returns a new instance
$a = $container->get(RequestContext::class);
$b = $container->get(RequestContext::class);
assert($a !== $b);
```

#### `#[Singleton]` — Explicit Singleton (Default Behavior)

```php
#[Singleton]
class DatabaseConnection {
    // Cached after first resolution (this is the default lifecycle,
    // but the attribute makes intent explicit)
}
```

#### `#[Tagged]` — Service Aggregation

```php
#[Tagged('event.listener')]
class UserCreatedListener { /* ... */ }

#[Tagged('event.listener')]
class AuditLogListener { /* ... */ }

// Retrieve all tagged services
$listeners = $container->getTagged('event.listener');
```

### Service Tagging (Programmatic)

```php
$container->tag(UserCreatedListener::class, 'event.listener');
$container->tag(AuditLogListener::class, ['event.listener', 'loggable']);

$listeners = $container->getTagged('event.listener');
```

### Transient Lifecycle (Programmatic)

```php
$container->transient(RequestContext::class);
```

### Builder Pattern

Use `ContainerBuilder` for structured setup with providers:

```php
use MonkeysLegion\DI\ContainerBuilder;

$builder = new ContainerBuilder();

$builder
    ->addDefinitions([
        PDO::class => fn() => new PDO($dsn, $user, $pass),
    ])
    ->set('app.debug', fn() => true)
    ->bind(LoggerInterface::class, FileLogger::class)
    ->tag(UserCreatedListener::class, 'event.listener')
    ->transient(RequestContext::class);

$container = $builder->build();
```

### Compiled Container (Production)

Pre-compile the container for faster boot times in production:

```php
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\DI\ContainerDumper;

// Build and dump (deploy step)
$builder = new ContainerBuilder();
$builder->addDefinitions([/* ... */]);
$container = $builder->build();

$dumper = new ContainerDumper();
$dumper->dump($container, '/var/cache/compiled_container.php');

// Load in production (fast boot)
$builder = new ContainerBuilder();
$builder
    ->addDefinitions([/* same definitions */])
    ->enableCompilation('/var/cache');

$container = $builder->build(); // Returns CompiledContainer if cache exists
```

### Testing Support

```php
// Reset cached instances between tests
$container->reset();
```

## API Reference

### `Container`

| Method | Description |
|---|---|
| `get(string $id): mixed` | Resolve a service (PSR-11) |
| `has(string $id): bool` | Check if service exists (PSR-11) |
| `set(string $id, callable\|object $def): void` | Register/override at runtime |
| `bind(string $abstract, string $concrete): void` | Map interface → implementation |
| `tag(string $id, string\|array $tags): void` | Tag a service |
| `getTagged(string $tag): array` | Get all services with a tag |
| `transient(string $id): void` | Mark as non-singleton |
| `reset(): void` | Clear cached instances |
| `getDefinitions(): array` | Get registered definitions |

### `ContainerBuilder`

| Method | Description |
|---|---|
| `addDefinitions(array $defs): self` | Merge definitions (won't overwrite) |
| `set(string $id, callable\|object $def): self` | Register single definition |
| `bind(string $abstract, string $concrete): self` | Map interface → implementation |
| `tag(string $id, string\|array $tags): self` | Tag a service |
| `transient(string $id): self` | Mark as non-singleton |
| `enableCompilation(string $dir): self` | Enable compiled container |
| `build(): Container` | Build the container |

### Attributes

| Attribute | Target | Description |
|---|---|---|
| `#[Inject('id')]` | Parameter | Override auto-wired parameter |
| `#[Singleton]` | Class | Explicit singleton (default) |
| `#[Transient]` | Class | New instance per `get()` |
| `#[Tagged('tag')]` | Class | Auto-register tag (repeatable) |

## Error Handling

The container throws PSR-11 compliant exceptions:

- **`ServiceNotFoundException`** — service ID not found (`Psr\Container\NotFoundExceptionInterface`)
- **`ServiceResolveException`** — circular dependency or unresolvable parameter (`Psr\Container\ContainerExceptionInterface`)

## Requirements

- PHP 8.4+
- `psr/container ^2.0`

## Testing

```bash
composer install
vendor/bin/phpunit --testdox
```

## License

MIT
