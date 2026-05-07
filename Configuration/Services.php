<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use T3\Size\Widgets\StorageUsageWidget;
use TYPO3\CMS\Dashboard\WidgetRegistry;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    if (!$containerBuilder->hasDefinition(WidgetRegistry::class)) {
        return;
    }

    $container->services()
        ->set('dashboard.widget.t3.size.storageUsage', StorageUsageWidget::class)
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false)
        ->tag('dashboard.widget', [
            'identifier' => 't3SizeStorageUsage',
            'groupNames' => 'systemInfo',
            'title' => 'LLL:EXT:size/Resources/Private/Language/locallang.xlf:widget.storageUsage.title',
            'description' => 'LLL:EXT:size/Resources/Private/Language/locallang.xlf:widget.storageUsage.description',
            'iconIdentifier' => 'tx-size-chart-pie',
            'height' => 'medium',
            'width' => 'medium',
        ]);
};
