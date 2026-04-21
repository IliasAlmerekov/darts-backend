# Reference

## Strategy Pattern with Tagged Services

Use this file for implementation detail, not workflow.

## Decision Matrix

| Situation | Prefer | Why |
| --- | --- | --- |
| Stable explicit key like `csv`, `json`, `stripe` | `TaggedLocator` | Deterministic lookup by key |
| Dynamic rule like `supports()` over request or domain state | `AutowireIterator` | Runtime scanning fits variable matching |
| Only two tiny branches with low growth pressure | No strategy | Simpler code wins |
| Variation is config/data, not behavior | No strategy | Use mapping or config instead |

## Pattern 1: Tagged Lookup by Explicit Key

Use this when each strategy exposes a stable unique key.

```php
<?php

declare(strict_types=1);

namespace App\Export;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exporter')]
interface ExporterInterface
{
    public static function getFormat(): string;

    public function export(array $data): string;
    public function getContentType(): string;
    public function getFileExtension(): string;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Export;

final class JsonExporter implements ExporterInterface
{
    public static function getFormat(): string
    {
        return 'json';
    }

    public function export(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function getContentType(): string
    {
        return 'application/json';
    }

    public function getFileExtension(): string
    {
        return 'json';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Export;

use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class ExportService
{
    public function __construct(
        #[TaggedLocator('app.exporter', defaultIndexMethod: 'getFormat')]
        private ServiceLocator $exporters,
    ) {}

    public function export(array $data, string $format): ExportResult
    {
        if (false === $this->exporters->has($format)) {
            throw new UnsupportedFormatException($format);
        }

        /** @var ExporterInterface $exporter */
        $exporter = $this->exporters->get($format);

        return new ExportResult(
            content: $exporter->export($data),
            contentType: $exporter->getContentType(),
            filename: 'export.' . $exporter->getFileExtension(),
        );
    }
}
```

### Best for

- format selection
- named providers
- explicit modes

### Watch for

- duplicate keys
- weak unsupported-key handling
- caller bypassing the service and talking to concrete exporters directly

## Pattern 2: Iterator + `supports()`

Use this when selection depends on behavior, not just a key.

```php
<?php

declare(strict_types=1);

namespace App\Payment;

interface PaymentProcessorInterface
{
    public function supports(string $method): bool;

    public function process(Payment $payment): PaymentResult;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Payment\Processor;

use App\Payment\Payment;
use App\Payment\PaymentProcessorInterface;
use App\Payment\PaymentResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.payment_processor')]
final readonly class StripeProcessor implements PaymentProcessorInterface
{
    public function supports(string $method): bool
    {
        return in_array($method, ['card', 'stripe'], true);
    }

    public function process(Payment $payment): PaymentResult
    {
        // ...
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PaymentService
{
    /**
     * @param iterable<PaymentProcessorInterface> $processors
     */
    public function __construct(
        #[AutowireIterator('app.payment_processor')]
        private iterable $processors,
    ) {}

    public function process(Payment $payment, string $method): PaymentResult
    {
        return $this->getProcessor($method)->process($payment);
    }

    private function getProcessor(string $method): PaymentProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($method)) {
                return $processor;
            }
        }

        throw new UnsupportedPaymentMethodException($method);
    }
}
```

### Best for

- behavior-based matching
- provider capability checks
- policy selection from runtime state

### Watch for

- ambiguous matches
- evaluation order hidden in priority or registration order
- expensive `supports()` checks

## Priority

Use priority only when overlap is intentional and documented.

```php
#[AutoconfigureTag('app.payment_processor', ['priority' => 10])]
final class StripeProcessor implements PaymentProcessorInterface
{
}

#[AutoconfigureTag('app.payment_processor', ['priority' => 0])]
final class FallbackProcessor implements PaymentProcessorInterface
{
}
```

If priority changes correctness, test it explicitly.

## Project-Specific Guidance

For this repository:

- keep selector logic in services, not controllers
- do not use strategy pattern to avoid making a clear DTO, validator, or repository decision
- if a strategy changes response shape, include serializer and Nelmio review
- if a strategy changes persistence behavior, include migration and transaction analysis
- prefer `final` and explicit types
- keep implementation additions narrow and testable

## Test Checklist

- selector returns the correct strategy for valid input
- unsupported input throws the correct domain or API-facing failure
- overlapping strategies are impossible or deliberately tested
- each important strategy has at least one negative-path test
- adding a new strategy does not require changing caller branching logic

## Anti-Patterns

- strategy interface with unrelated methods
- controller choosing concrete strategies directly
- fallback implementation swallowing real unsupported cases
- strategy introduced before proving multiple implementations are needed
- repository or entity used as a behavior switchboard for non-persistence logic
