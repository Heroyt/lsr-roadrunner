# LSR RoadRunner

`lsr/roadrunner` connects the LSR application runtime to RoadRunner HTTP and jobs workers. It provides Nette DI wiring, HTTP error handlers, task production and payload serialization under the `Lsr\Roadrunner\` namespace.

## Requirements

- PHP `>=8.4` with `fileinfo`, `gettext`, `simplexml`, `ctype`, `mbstring` and `pdo_sqlite`.
- LSR Core and Routing `^0.3 || ^0.4 || ^0.5`, Interfaces `^0.3.5`, and the Logging, Request, DB, Serializer, Cache and ORM `^0.3` packages.
- Nette DI `^3.2.4` (native lazy logger services), Latte `^3.0`, Nette PHP Generator `^4.1` and phpdotenv `^5.6`.
- RoadRunner `^2025`, worker `^3.6`, HTTP `^4.1` and jobs `^4.6` PHP packages.
- A PSR-3 logger contract from `psr/log` `^1.0 || ^2.0 || ^3.0`; the default implementation remains `lsr/logging`.
- A running RoadRunner server with the appropriate plugins, RPC endpoint and job pipeline configured outside this library. The application must bootstrap LSR Core, including its services, runtime paths, session and translations.

## Installation

```shell
composer require lsr/roadrunner
```

## Runtime integration

Register the extension in the application's Nette DI configuration:

```neon
extensions:
    roadrunner: Lsr\Roadrunner\DI\RoadrunnerExtension

roadrunner:
    rpc:
        host: tcp://localhost
        port: 6001
    jobs:
        queue: tasks
```

The RPC address and `tasks` queue must match the application's RoadRunner configuration. These settings connect PHP to an existing server; they do not create a RoadRunner configuration or provision a broker.

Configure RoadRunner to launch the application's PHP worker bootstrap. After initializing Core and its DI container, that bootstrap resolves `Lsr\Roadrunner\Server` and calls `run()`. The server reads RoadRunner's environment mode and selects the configured HTTP or jobs worker; invoking it as an ordinary CLI command without the RoadRunner environment is not a substitute for starting the server.

The default worker map is defined in [RoadrunnerExtension](src/DI/RoadrunnerExtension.php). Custom workers implement [`Lsr\Roadrunner\Workers\Worker`](src/Workers/Worker.php) and can replace entries through the `workers` configuration map.

The [HTTP worker](src/Workers/HttpWorker.php) converts incoming PSR requests using the application's `Lsr\Interfaces\RequestFactoryInterface`. The factory must return an LSR `RequestInterface`; the worker then sets that request on `App` and runs the application. The worker is long-lived, so application services must not assume that a new PHP process is created for every request. ORM instance caches are cleared between handled requests/tasks, but that does not reset arbitrary application state.

## Logging

**Unreleased:** logger setters and the configuration below are working-tree changes, not features of an existing published version. Check installed source before using them.

Both built-in workers accept any `Psr\Log\LoggerInterface` through `setLogger(LoggerInterface $logger): static`. Existing constructors are unchanged, including subclasses with their own constructor dependencies:

```php
$httpWorker = new Lsr\Roadrunner\Workers\HttpWorker(
    $error500Handler,
    $error403Handler,
    $error404Handler,
    $error405Handler,
);
$jobsWorker = new Lsr\Roadrunner\Workers\JobsWorker($serializer);

// $logger is an application-owned PSR-3 logger; both workers keep the same instance.
$httpWorker->setLogger($logger);
$jobsWorker->setLogger($logger);
```

Without injection, HTTP and jobs workers lazily create their own `Lsr\Logging\Logger` using runtime `LOG_DIR` and the names `worker` and `worker-jobs`, respectively. Their dated file outputs remain separate. Constructing a worker or compiling the DI container does not initialize these default loggers or require `LOG_DIR`; define it before the first default log write.

The DI extension accepts native Nette service references. For example, to explicitly reuse the application's existing `@logger` for HTTP and use a separately registered PSR logger for jobs:

```neon
roadrunner:
    logger: @logger
    loggers:
        http: null
        jobs: @jobsLogger
```

The application defines `@logger` and `@jobsLogger`; either may be any PSR-3 implementation. Selection is **purpose override, then common `logger`, then the dedicated default**. An omitted or `null` purpose override inherits the common setting rather than disabling logging. With all settings omitted or `null`, the extension never selects the unrelated global `@logger`.

The selected shared services are exposed as `<extension>.logger.http` and `<extension>.logger.jobs` (`roadrunner.logger.http` and `roadrunner.logger.jobs` above). These definitions are not autowired by type, so they do not compete with the application's logger. Both references return the same original instance when configured to select the same service; otherwise their defaults are independent and lazy. No common `<extension>.logger` service is added.

The extension explicitly calls `setLogger()` after application service replacements have been applied, rather than adding constructor arguments. Built-in workers and their subclasses retain this wiring when replacing `worker.http`/`worker.jobs` or selecting a subclass service through `workers.http`/`workers.jobs`. Unrelated custom `Worker` implementations remain valid and manage their own logging; they are not required to implement `setLogger()`.

Each reported exception still emits exactly an `error` summary (`Thrown Exception (<code>): <message>`) followed by a `debug` stack trace, both with empty context. PSR loggers do not need the LSR-specific `exception()` helper. Tracy reporting and HTTP stderr reporting remain separate and unchanged, as do acknowledgement, negative acknowledgement and flush policies. Logging remains synchronous: logger failures can propagate through normal error reporting and prevent subsequent debug/Tracy/stderr output. The existing HTTP post-response cleanup safeguard still catches reporting failures rather than sending a second response. Use a logger with its own failure policy if different behavior is required.

## Background tasks

The task name is the DI service name of its dispatcher, not an arbitrary event label:

1. Implement [`TaskDispatcherInterface`](src/Tasks/TaskDispatcherInterface.php). `getDiName()` returns the registered service name; `process()` accepts the received RoadRunner task and an optional `TaskPayloadInterface` payload.
2. Register that dispatcher in the same application container used by jobs workers.
3. Inject [`TaskProducer`](src/Tasks/TaskProducer.php) and call `push(DispatcherClass::class, $payload)` for immediate dispatch. Use `plan()` followed by `dispatch()` to dispatch a batch. Optional RoadRunner job options can be passed to `push()` or `plan()`.
4. The [jobs worker](src/Workers/JobsWorker.php) resolves the dispatcher by service name and deserializes a nonempty payload. Successful processing is acknowledged if the dispatcher has not already completed the task; failures are negatively acknowledged and logged.

This package does not define the application's retry or dead-letter policy. Configure those policies in the chosen RoadRunner pipeline and make tasks safe to execute more than once.

### Payload safety and serializer configuration

The default [`PhpTaskSerializer`](src/Tasks/Serializers/PhpTaskSerializer.php) uses PHP `unserialize()` with classes allowed. Only trusted producers may submit payloads to queues consumed by this serializer. Do not expose such queues to untrusted input.

Producer and consumer must use compatible serializers and have the same payload classes available. The extension's `jobs.serializer` setting selects the producer serializer, while the jobs worker receives `TaskSerializerInterface` by autowiring. When overriding serialization, configure both sides consistently rather than changing only the producer option. Additional implementations are in [src/Tasks/Serializers](src/Tasks/Serializers).

Request, worker, task-consumption and task-dispatch lifecycle hooks are available for instrumentation; their contracts are in [src/Lifecycle](src/Lifecycle).

## Development

GitHub Actions runs CS, PHPStan and PHPUnit on PHP 8.4 and 8.5. Run the same checks locally:

```shell
composer install --prefer-dist --no-interaction --no-progress
composer cs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --no-coverage
```

`composer cs` checks coding style without changing files. Run `composer cs:fix` (or `composer cbf`) to apply PHP CS Fixer rules from [.php-cs-fixer.php](.php-cs-fixer.php).

The development manifest requires PHPUnit `^13`; use a current PHP patch release that satisfies its platform requirements. The lifecycle tests use in-process doubles and do not require a running RoadRunner server, Redis or MySQL. CI runs without a coverage driver.

## AI coding assistance

See [LSR Skills](https://github.com/Heroyt/lsr-skills) for AI agent skills for working with the LSR framework.

## License

Licensed under the [MIT License](LICENSE).
