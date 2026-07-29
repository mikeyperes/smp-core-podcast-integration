# Object Cache

Namespace:

```text
Hexa\PluginCore\ObjectCache
```

## Purpose

Provider adapters in this namespace report separately whether object caching is configured and whether it is actually working.

`LiteSpeedRedisService` checks:

- LiteSpeed Cache installation and activation.
- LiteSpeed object-cache and Redis settings.
- The object-cache drop-in.
- PHP Redis extension connectivity and PING.
- A WordPress cache set/get/delete round trip.

`enable()` activates LiteSpeed when needed, applies its Redis options, asks LiteSpeed to refresh managed files, flushes cache, and returns before/after evidence. The host owns only the AJAX guard and presentation surface.

```php
$service = new LiteSpeedRedisService();
$status  = $service->status();
$result  = $service->enable();
```

Do not report Redis as active from settings alone. `active` is true only when the provider is enabled, direct Redis connectivity succeeds, and the WordPress cache round trip succeeds.
