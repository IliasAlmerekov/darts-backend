<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Serializer;

use App\Dto\StartGameRequest;
use App\Exception\Request\ConflictingSettingKeysException;
use Override;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Accepts the legacy lowercase settings keys next to the camelCase ones.
 *
 * Remove the legacy keys once the frontend sends camelCase only.
 *
 * @psalm-suppress UnusedClass Reason: registered through Symfony serializer autoconfiguration.
 */
final class StartGameRequestDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    private const string KEYS_MERGED = 'start_game_request_keys_merged';

    /**
     * Legacy key => camelCase key.
     */
    private const array LEGACY_KEYS = [
        'startscore' => 'startScore',
        'doubleout' => 'doubleOut',
        'tripleout' => 'tripleOut',
    ];

    /**
     * @param mixed                $data
     * @param string               $type
     * @param string|null          $format
     * @param array<string, mixed> $context
     *
     * @return bool
     */
    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return StartGameRequest::class === $type && is_array($data) && !isset($context[self::KEYS_MERGED]);
    }

    /**
     * @param mixed                $data
     * @param string               $type
     * @param string|null          $format
     * @param array<string, mixed> $context
     *
     * @return mixed
     */
    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array<string, mixed> $data */
        foreach (self::LEGACY_KEYS as $legacyKey => $camelCaseKey) {
            if (!array_key_exists($legacyKey, $data)) {
                continue;
            }

            if (array_key_exists($camelCaseKey, $data) && $data[$camelCaseKey] !== $data[$legacyKey]) {
                throw new ConflictingSettingKeysException($camelCaseKey, $legacyKey);
            }

            $data[$camelCaseKey] = $data[$legacyKey];
            unset($data[$legacyKey]);
        }

        return $this->denormalizer->denormalize($data, $type, $format, $context + [self::KEYS_MERGED => true]);
    }

    /**
     * @param string|null $format
     *
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [StartGameRequest::class => false];
    }
}
