<?php

declare(strict_types=1);

namespace T3\Size\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use T3\Size\Event\BeforeSizeOverviewSnapshotStoredEvent;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

final readonly class SizeOverviewRefreshService
{
    private const LOCK_IDENTIFIER = 'size-overview-refresh';

    public function __construct(
        private LockFactory $lockFactory,
        private SizeOverviewCalculator $sizeOverviewCalculator,
        private SizeOverviewSnapshotStorage $snapshotStorage,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function refresh(): RefreshResult
    {
        $locker = $this->createLocker();

        try {
            $locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        } catch (LockAcquireWouldBlockException) {
            $locker->destroy();
            return RefreshResult::locked();
        }

        $startedAt = microtime(true);

        try {
            $overview = $this->sizeOverviewCalculator->getOverview();
            $calculatedAt = time();
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $event = new BeforeSizeOverviewSnapshotStoredEvent($overview, $calculatedAt, $durationMs);
            $this->eventDispatcher->dispatch($event);
            $this->snapshotStorage->storeSnapshot($event->getOverview(), $calculatedAt, $durationMs);

            return RefreshResult::refreshed($calculatedAt, $durationMs);
        } finally {
            if ($locker->isAcquired()) {
                $locker->release();
            }
            $locker->destroy();
        }
    }

    public function isRefreshRunning(): bool
    {
        $locker = $this->createLocker();

        try {
            $locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        } catch (LockAcquireWouldBlockException) {
            $locker->destroy();
            return true;
        }

        if ($locker->isAcquired()) {
            $locker->release();
        }
        $locker->destroy();

        return false;
    }

    private function createLocker(): LockingStrategyInterface
    {
        return $this->lockFactory->createLocker(
            self::LOCK_IDENTIFIER,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
    }
}
