<?php
defined('TYPO3_MODE') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
    'web',
    'visualedit',
    'after:layout',
    null,
    [
        'routeTarget' => \Webian\Visualedit\Controller\VisualeditModuleController::class . '::showAction',
        'access' => 'user,group',
        'name' => 'web_visualedit',
        'icon' => 'EXT:visualedit/Resources/Public/Icons/module-visualedit.svg',
        'labels' => 'LLL:EXT:visualedit/Resources/Private/Language/locallang_mod.xlf',
    ]
);
