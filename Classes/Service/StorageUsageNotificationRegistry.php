<?php

declare(strict_types=1);

namespace T3\Size\Service;

use TYPO3\CMS\Core\Registry;

final readonly class StorageUsageNotificationRegistry
{
    private const REGISTRY_NAMESPACE = 'size';
    private const LAST_CHECK_KEY = 'notification_last_check';
    private const LAST_SENT_WARNING_KEY = 'notification_last_sent_warning';
    private const LAST_SENT_FULL_KEY = 'notification_last_sent_full';

    public function __construct(
        private Registry $registry,
    ) {}

    /**
     * @return array{calculatedAt: int, warningRecipients: list<string>, fullRecipients: list<string>}|null
     */
    public function getLastCheck(): ?array
    {
        $value = $this->registry->get(self::REGISTRY_NAMESPACE, self::LAST_CHECK_KEY);
        if (!is_array($value)) {
            return null;
        }

        $warningRecipients = $this->normalizeRecipientList($value['warningRecipients'] ?? null);
        $fullRecipients = $this->normalizeRecipientList($value['fullRecipients'] ?? null);

        return [
            'calculatedAt' => (int)($value['calculatedAt'] ?? 0),
            'warningRecipients' => $warningRecipients,
            'fullRecipients' => $fullRecipients,
        ];
    }

    /**
     * @param list<string> $warningRecipients
     * @param list<string> $fullRecipients
     */
    public function storeLastCheck(int $calculatedAt, array $warningRecipients, array $fullRecipients): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::LAST_CHECK_KEY, [
            'calculatedAt' => $calculatedAt,
            'warningRecipients' => array_values($warningRecipients),
            'fullRecipients' => array_values($fullRecipients),
        ]);
    }

    public function getLastSentTimestamp(string $type): ?int
    {
        $key = $this->resolveLastSentRegistryKey($type);
        $value = $this->registry->get(self::REGISTRY_NAMESPACE, $key);
        if (!is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    public function storeLastSentTimestamp(string $type, int $timestamp): void
    {
        $this->registry->set(
            self::REGISTRY_NAMESPACE,
            $this->resolveLastSentRegistryKey($type),
            $timestamp
        );
    }

    public function reset(): void
    {
        $this->registry->remove(self::REGISTRY_NAMESPACE, self::LAST_CHECK_KEY);
        $this->registry->remove(self::REGISTRY_NAMESPACE, self::LAST_SENT_WARNING_KEY);
        $this->registry->remove(self::REGISTRY_NAMESPACE, self::LAST_SENT_FULL_KEY);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeRecipientList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $recipients = [];
        foreach ($value as $recipient) {
            if (!is_string($recipient) || $recipient === '') {
                continue;
            }
            $recipients[] = $recipient;
        }

        return array_values($recipients);
    }

    private function resolveLastSentRegistryKey(string $type): string
    {
        return match ($type) {
            'warning' => self::LAST_SENT_WARNING_KEY,
            'full' => self::LAST_SENT_FULL_KEY,
            default => throw new \InvalidArgumentException(
                sprintf('Unsupported notification type "%s"', $type),
                1747909632
            ),
        };
    }
}
