<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Logging;

use App\Exception\ApiHttpException;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

/** Adds a request ID and removes invitation secrets from HTTP logs. */
final class RequestProcessor
{
    /** @param RequestStack $requests */
    public function __construct(private readonly RequestStack $requests)
    {
    }

    /**
     * @param LogRecord $record
     *
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return $record;
        }

        $exception = $record->context['exception'] ?? null;
        if ('request' === $record->channel && str_starts_with($record->message, 'Uncaught PHP Exception ')
            && $exception instanceof ApiHttpException && $exception->getStatusCode() >= 400 && $exception->getStatusCode() < 500) {
            $record = $record->with(level: Level::Warning);
        }

        $id = $request->attributes->get('_log_request_id');
        if (!is_string($id)) {
            $id = bin2hex(random_bytes(16));
            $request->attributes->set('_log_request_id', $id);
        }
        $extra = $record->extra;
        $extra['request_id'] = $id;
        if (preg_match('~^/api/invite/join/([^/]+)~', $request->getPathInfo(), $matches)) {
            $secret = rawurldecode($matches[1]);

            return $record->with(message: str_replace($secret, '[redacted]', $record->message), context: $this->redact($record->context, $secret), extra: $extra);
        }

        return $record->with(extra: $extra);
    }

    /**
     * @param array<array-key, mixed> $context
     * @param string                  $secret
     *
     * @return array<array-key, mixed>
     */
    private function redact(array $context, string $secret): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = str_replace($secret, '[redacted]', $value);
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value, $secret);
            } elseif ($value instanceof Throwable) {
                $context[$key] = $value::class.': '.str_replace($secret, '[redacted]', $value->getMessage());
            }
        }

        return $context;
    }
}
