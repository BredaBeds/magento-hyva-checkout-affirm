<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Logger;

use Monolog\LogRecord;

/**
 * Monolog processor that removes Affirm checkout tokens from log records.
 *
 * Affirm's client logs the pre-authorization URI (/api/v2/checkout/<token>) and full API responses, which may
 * carry checkout_id; a return URL may carry checkout_token in its query. Message, context and extra are
 * redacted recursively; objects are left as they are.
 *
 * A plain invokable on purpose: Monolog 2 passes arrays and Monolog 3 passes LogRecord objects, and
 * ProcessorInterface's signature differs between the two.
 */
class RedactCheckoutTokenProcessor
{
    public const REDACTED = '[redacted]';

    private const PATH_PATTERN = '#(/api/v2/checkout/)[A-Za-z0-9_\-]+#';

    private const QUERY_PATTERN = '#([?&](?:checkout_token|checkout_id)=)[^&\s"\'<>]+#i';

    private const SENSITIVE_KEYS = ['checkout_token', 'checkout_id'];

    private const MAX_DEPTH = 16;

    /**
     * Redact a Monolog 2 array record or a Monolog 3 LogRecord, returning the same type.
     *
     * @param array<string, mixed>|LogRecord $record
     * @return array<string, mixed>|LogRecord
     */
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        if ($record instanceof LogRecord) {
            return $record->with(
                message: $this->redactString($record->message),
                context: $this->redactArray($record->context),
                extra: $this->redactArray($record->extra)
            );
        }

        if (isset($record['message']) && is_string($record['message'])) {
            $record['message'] = $this->redactString($record['message']);
        }
        foreach (['context', 'extra'] as $key) {
            if (isset($record[$key]) && is_array($record[$key])) {
                $record[$key] = $this->redactArray($record[$key]);
            }
        }

        return $record;
    }

    /**
     * Redact token-bearing keys and string values at any depth.
     *
     * @param array<mixed> $values
     * @param int $depth
     * @return array<mixed>
     */
    private function redactArray(array $values, int $depth = 0): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = self::REDACTED;
            } elseif (is_string($value)) {
                $values[$key] = $this->redactString($value);
            } elseif (is_array($value)) {
                $values[$key] = $depth < self::MAX_DEPTH ? $this->redactArray($value, $depth + 1) : self::REDACTED;
            }
        }

        return $values;
    }

    /**
     * Redact tokens in URIs and query strings.
     *
     * @param string $value
     * @return string
     */
    private function redactString(string $value): string
    {
        // Cheap guards first: this runs for every record of the main logger. If a regex ever fails, the whole
        // value is dropped rather than logged unredacted.
        if (str_contains($value, '/api/v2/checkout/')) {
            $value = preg_replace(self::PATH_PATTERN, '$1' . self::REDACTED, $value) ?? self::REDACTED;
        }
        if (stripos($value, 'checkout_') !== false) {
            $value = preg_replace(self::QUERY_PATTERN, '$1' . self::REDACTED, $value) ?? self::REDACTED;
        }

        return $value;
    }
}
