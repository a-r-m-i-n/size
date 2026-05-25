<?php

use T3\Size\Controller\StorageStatisticsController;

return [
    'size_storage_statistics' => [
        'parent' => 'system',
        'access' => 'user',
        'path' => '/module/system/size/storage-statistics',
        'packageName' => 't3/size',
        'iconIdentifier' => 'tx-size-chart-pie',
        'labels' => 'LLL:EXT:size/Resources/Private/Language/locallang_mod.xlf',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
        'routes' => [
            '_default' => [
                'target' => StorageStatisticsController::class . '::overviewAction',
            ],
            'refresh' => [
                'target' => StorageStatisticsController::class . '::refreshAction',
                'methods' => ['POST'],
            ],
            'reset' => [
                'target' => StorageStatisticsController::class . '::resetAction',
                'methods' => ['POST'],
            ],
        ],
    ],
    'size_storage_statistics_history' => [
        'parent' => 'system',
        'access' => 'user',
        'path' => '/module/system/size/storage-statistics/history',
        'packageName' => 't3/size',
        'iconIdentifier' => 'tx-size-chart-pie',
        'labels' => 'LLL:EXT:size/Resources/Private/Language/locallang_mod.xlf',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
        'routes' => [
            '_default' => [
                'target' => StorageStatisticsController::class . '::historyAction',
            ],
            'reset' => [
                'target' => StorageStatisticsController::class . '::resetHistoryAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
