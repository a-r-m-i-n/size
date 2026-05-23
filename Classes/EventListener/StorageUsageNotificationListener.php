<?php

declare(strict_types=1);

namespace T3\Size\EventListener;

use Psr\Log\LoggerInterface;
use T3\Size\Event\BeforeSizeOverviewSnapshotStoredEvent;
use T3\Size\Service\StorageUsageNotificationRegistry;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Fluid\View\TemplatePaths;

#[AsEventListener('size/storage-usage-notification')]
final readonly class StorageUsageNotificationListener
{
    private const TYPE_WARNING = 'warning';
    private const TYPE_FULL = 'full';
    private const WARNING_THRESHOLD = 90.0;
    private const FULL_THRESHOLD = 100.0;
    private const COOLDOWN_SECONDS = 604800;

    private LoggerInterface $logger;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private StorageUsageNotificationRegistry $notificationRegistry,
        private MailerInterface $mailer,
        private LanguageServiceFactory $languageServiceFactory,
        private SiteFinder $siteFinder,
        LogManager $logManager,
    ) {
        $this->logger = $logManager->getLogger(__CLASS__);
    }

    public function __invoke(BeforeSizeOverviewSnapshotStoredEvent $event): void
    {
        $overview = $event->getOverview();
        $chart = is_array($overview['chart'] ?? null) ? $overview['chart'] : [];
        $total = is_array($overview['total'] ?? null) ? $overview['total'] : [];

        if (($chart['isMaximumConfigured'] ?? false) !== true) {
            $this->notificationRegistry->storeLastCheck($event->getCalculatedAt(), [], []);
            return;
        }

        $percentage = $this->normalizePercentage($chart['totalPercentage'] ?? null);
        if ($percentage === null) {
            $this->notificationRegistry->storeLastCheck($event->getCalculatedAt(), [], []);
            return;
        }

        $warningRecipients = $this->getRecipientsFromConfiguration('warningNotificationRecipients');
        $fullRecipients = $this->getRecipientsFromConfiguration('fullNotificationRecipients');
        $warningSentRecipients = [];
        $fullSentRecipients = [];

        if ($percentage >= self::FULL_THRESHOLD) {
            $warningRecipients = array_values(array_diff($warningRecipients, $fullRecipients));
            if ($warningRecipients !== [] && $this->canSendNotification(self::TYPE_WARNING, $event->getCalculatedAt())) {
                $warningSentRecipients = $this->sendNotification(
                    self::TYPE_WARNING,
                    $warningRecipients,
                    $percentage,
                    $total,
                    $chart,
                    $event->getCalculatedAt()
                );
            }
            if ($fullRecipients !== [] && $this->canSendNotification(self::TYPE_FULL, $event->getCalculatedAt())) {
                $fullSentRecipients = $this->sendNotification(
                    self::TYPE_FULL,
                    $fullRecipients,
                    $percentage,
                    $total,
                    $chart,
                    $event->getCalculatedAt()
                );
            }
        } elseif ($percentage > self::WARNING_THRESHOLD) {
            if ($warningRecipients !== [] && $this->canSendNotification(self::TYPE_WARNING, $event->getCalculatedAt())) {
                $warningSentRecipients = $this->sendNotification(
                    self::TYPE_WARNING,
                    $warningRecipients,
                    $percentage,
                    $total,
                    $chart,
                    $event->getCalculatedAt()
                );
            }
        }

        $this->notificationRegistry->storeLastCheck(
            $event->getCalculatedAt(),
            $warningSentRecipients,
            $fullSentRecipients,
        );
    }

    /**
     * @param array<string, mixed> $total
     * @param array<string, mixed> $chart
     * @param list<string> $recipients
     * @return list<string>
     */
    private function sendNotification(
        string $type,
        array $recipients,
        float $percentage,
        array $total,
        array $chart,
        int $calculatedAt,
    ): array {
        try {
            $siteName = $this->getSiteName();
            $siteUrl = $this->getSiteUrl();
            $serverIp = $this->getServerIp();
            $templatePaths = new TemplatePaths();
            $templatePaths->setTemplateRootPaths(array_replace(
                $GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'] ?? [],
                [
                    100 => 'EXT:size/Resources/Private/Templates/Mail/',
                ]
            ));
            $templatePaths->setLayoutRootPaths($GLOBALS['TYPO3_CONF_VARS']['MAIL']['layoutRootPaths'] ?? []);
            $templatePaths->setPartialRootPaths($GLOBALS['TYPO3_CONF_VARS']['MAIL']['partialRootPaths'] ?? []);
            $email = new FluidEmail($templatePaths);
            $email
                ->assign('normalizedParams', ['siteUrl' => $siteUrl])
                ->to(...$recipients)
                ->subject($this->buildSubject($type, $percentage, $siteName))
                ->format(FluidEmail::FORMAT_BOTH)
                ->setTemplate('StorageUsageNotification')
                ->assignMultiple([
                    'headline' => $this->buildHeadline($type),
                    'introduction' => $this->buildIntroduction($type),
                    'notificationType' => $type,
                    'percentage' => number_format($percentage, 1, '.', ''),
                    'totalLabel' => (string)($total['label'] ?? ''),
                    'maximumLabel' => $this->formatBytes((int)($chart['maximumBytes'] ?? 0)),
                    'calculatedAt' => $calculatedAt,
                    'calculatedAtLabel' => date('Y-m-d H:i:s', $calculatedAt),
                    'siteName' => $siteName,
                    'siteUrl' => $siteUrl,
                    'serverIp' => $serverIp,
                ]);
            $this->mailer->send($email);
            $this->notificationRegistry->storeLastSentTimestamp($type, $calculatedAt);

            return $recipients;
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('Failed to send %s storage usage notification.', $type),
                ['exception' => $exception]
            );

            return [];
        }
    }

    private function canSendNotification(string $type, int $calculatedAt): bool
    {
        $lastSentTimestamp = $this->notificationRegistry->getLastSentTimestamp($type);
        if ($lastSentTimestamp === null) {
            return true;
        }

        return ($calculatedAt - $lastSentTimestamp) >= self::COOLDOWN_SECONDS;
    }

    /**
     * @return list<string>
     */
    private function getRecipientsFromConfiguration(string $key): array
    {
        try {
            $configuration = $this->extensionConfiguration->get('size');
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($configuration)) {
            return [];
        }

        $rawValue = (string)($configuration[$key] ?? '');
        if ($rawValue === '') {
            return [];
        }

        $recipients = preg_split('/[\r\n,]+/', $rawValue) ?: [];
        $normalized = [];

        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if ($recipient === '') {
                continue;
            }
            $validatedRecipient = filter_var($recipient, FILTER_VALIDATE_EMAIL);
            if ($validatedRecipient === false) {
                continue;
            }
            $normalized[strtolower($validatedRecipient)] = $validatedRecipient;
        }

        return array_values($normalized);
    }

    private function normalizePercentage(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $percentage = (float)$value;

        return $percentage >= 0.0 ? $percentage : null;
    }

    private function buildSubject(string $type, float $percentage, string $siteName): string
    {
        $subjectKey = $type === self::TYPE_FULL
            ? 'mail.storageUsage.subject.full'
            : 'mail.storageUsage.subject.warning';

        $subject = sprintf(
            $this->translate($subjectKey),
            number_format($percentage, 1, '.', '')
        );

        if ($siteName === '') {
            return $subject;
        }

        return sprintf('[%s] %s', $siteName, $subject);
    }

    private function buildHeadline(string $type): string
    {
        return $type === self::TYPE_FULL
            ? $this->translate('mail.storageUsage.heading.full')
            : $this->translate('mail.storageUsage.heading.warning');
    }

    private function buildIntroduction(string $type): string
    {
        return $type === self::TYPE_FULL
            ? $this->translate('mail.storageUsage.body.full')
            : $this->translate('mail.storageUsage.body.warning');
    }

    private function translate(string $key): string
    {
        return $this->languageServiceFactory
            ->createFromUserPreferences(null)
            ->sL('LLL:EXT:size/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }

    private function getSiteName(): string
    {
        return trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''));
    }

    private function getSiteUrl(): string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $siteUrl = rtrim((string)$site->getBase(), '/') . '/';
            if ($siteUrl !== '/') {
                return $siteUrl;
            }
        }

        try {
            $normalizedParams = NormalizedParams::createFromServerParams($_SERVER);
            if (method_exists($normalizedParams, 'getSiteUrl')) {
                return trim((string)$normalizedParams->getSiteUrl());
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    private function getServerIp(): string
    {
        $serverIp = trim((string)($_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? ''));
        if ($serverIp === '') {
            $hostname = gethostname();
            if (is_string($hostname) && $hostname !== '') {
                $resolvedAddress = gethostbyname($hostname);
                if ($resolvedAddress !== $hostname) {
                    $serverIp = trim($resolvedAddress);
                }
            }
        }

        return filter_var($serverIp, FILTER_VALIDATE_IP) !== false ? $serverIp : '';
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        $unitIndex = 0;

        while ($value >= 1024 && isset($units[$unitIndex + 1])) {
            $value /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : 2;
        $formattedValue = number_format($value, $precision, '.', ' ');

        if ($precision > 0) {
            $formattedValue = rtrim(rtrim($formattedValue, '0'), '.');
        }

        return $formattedValue . ' ' . $units[$unitIndex];
    }
}
