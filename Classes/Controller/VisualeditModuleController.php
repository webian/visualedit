<?php
declare(strict_types = 1);
namespace Webian\Visualedit\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\FullyRenderedButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\BackendWorkspaceRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * Controller for editing the frontend inside the backend
 */
class VisualeditModuleController
{
    /**
     * The name of the module
     *
     * @var string
     */
    protected $moduleName = 'web_visualedit';

    /**
     * ModuleTemplate object
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * View
     *
     * @var ViewInterface
     */
    protected $view;

    /**
     * Initialize module template and language service
     */
    public function __construct()
    {
        $this->moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);
        $this->getLanguageService()->includeLLFile('EXT:visualedit/Resources/Private/Language/locallang.xlf');
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addInlineLanguageLabelFile('EXT:visualedit/Resources/Private/Language/locallang.xlf');
    }

    /**
     * Initialize view
     *
     * @param string $templateName
     */
    protected function initializeView(string $templateName)
    {
        $this->view = GeneralUtility::makeInstance(StandaloneView::class);
        $this->view->getRequest()->setControllerExtensionName('Visualedit');
        $this->view->setTemplate($templateName);
        $this->view->setTemplateRootPaths(['EXT:visualedit/Resources/Private/Templates/ViewModule']);
        $this->view->setPartialRootPaths(['EXT:visualedit/Resources/Private/Partials']);
        $this->view->setLayoutRootPaths(['EXT:visualedit/Resources/Private/Layouts']);
    }

    /**
     * Registers the docheader
     *
     * @param int $pageId
     * @param int $languageId
     * @param string $targetUrl
     * @param ServerRequestInterface|null $request
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function registerDocHeader(int $pageId, int $languageId, string $targetUrl, ServerRequestInterface $request = null)
    {
        $languages = $this->getPreviewLanguages($pageId);
        if (count($languages) > 1) {
            $languageMenu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
            $languageMenu->setIdentifier('_langSelector');
            /** @var \TYPO3\CMS\Backend\Routing\UriBuilder $uriBuilder */
            $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);
            foreach ($languages as $value => $label) {
                $href = (string)$uriBuilder->buildUriFromRoute(
                    'web_visualedit',
                    [
                        'id' => $pageId,
                        'language' => (int)$value
                    ]
                );
                $menuItem = $languageMenu->makeMenuItem()
                    ->setTitle($label)
                    ->setHref($href);
                if ($languageId === (int)$value) {
                    $menuItem->setActive(true);
                }
                $languageMenu->addMenuItem($menuItem);
            }
            $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($languageMenu);
        }

        // Add view page button to module docheader
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $showButton = $buttonBar->makeLinkButton()
            ->setHref($targetUrl)
            ->setOnClick('window.open(this.href, \'newTYPO3frontendWindow\').focus();return false;')
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.showPage'))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-view-page', Icon::SIZE_SMALL));
        $buttonBar->addButton($showButton);

        // Add clear cache button and edit page button to module docheader
        // (code adapted from \TYPO3\CMS\Backend\Controller\PageLayoutController)
        $request = $request ?: $GLOBALS['TYPO3_REQUEST'];
        $lang = $this->getLanguageService();
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        // Cache
        if (empty(BackendUtility::getPagesTSconfig($pageId)['properties']['disableAdvanced'])) {
            $clearCacheButton = $buttonBar->makeLinkButton()
                ->setHref((string)$uriBuilder->buildUriFromRoute($this->moduleName, ['id' => $pageId, 'clear_cache' => '1']))
                ->setTitle($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.clear_cache'))
                ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-system-cache-clear', Icon::SIZE_SMALL));
            $buttonBar->addButton($clearCacheButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
        }
        if (empty(BackendUtility::getPagesTSconfig($pageId)['properties']['disableIconToolbar'])) {
            // Edit page properties and page language overlay icons
            if ($this->isPageEditable(0)) {
                /** @var \TYPO3\CMS\Core\Http\NormalizedParams */
                $normalizedParams = $request->getAttribute('normalizedParams');
                // Edit localized pages only when one specific language is selected
                if ($languageId > 0) {
                    $localizationParentField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'];
                    $languageField = $GLOBALS['TCA']['pages']['ctrl']['languageField'];
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getQueryBuilderForTable('pages');
                    $queryBuilder->getRestrictions()
                        ->removeAll()
                        ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                        ->add(GeneralUtility::makeInstance(BackendWorkspaceRestriction::class));
                    $overlayRecord = $queryBuilder
                        ->select('uid')
                        ->from('pages')
                        ->where(
                            $queryBuilder->expr()->eq(
                                $localizationParentField,
                                $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)
                            ),
                            $queryBuilder->expr()->eq(
                                $languageField,
                                $queryBuilder->createNamedParameter($languageId, \PDO::PARAM_INT)
                            )
                        )
                        ->setMaxResults(1)
                        ->execute()
                        ->fetch();
                    // Edit button
                    $urlParameters = [
                        'edit' => [
                            'pages' => [
                                $overlayRecord['uid'] => 'edit'
                            ]
                        ],
                        'returnUrl' => $normalizedParams->getRequestUri(),
                    ];

                    $url = (string)$uriBuilder->buildUriFromRoute('record_edit', $urlParameters);
                    $editLanguageButton = $buttonBar->makeLinkButton()
                        ->setHref($url)
                        ->setTitle($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_layout.xlf:editPageLanguageOverlayProperties'))
                        ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('mimetypes-x-content-page-language-overlay', Icon::SIZE_SMALL));
                    $buttonBar->addButton($editLanguageButton, ButtonBar::BUTTON_POSITION_LEFT, 3);
                }
                $urlParameters = [
                    'edit' => [
                        'pages' => [
                            $pageId => 'edit'
                        ]
                    ],
                    'returnUrl' => $normalizedParams->getRequestUri(),
                ];
                $url = (string)$uriBuilder->buildUriFromRoute('record_edit', $urlParameters);
                $editPageButton = $buttonBar->makeLinkButton()
                    ->setHref($url)
                    ->setTitle($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_layout.xlf:editPageProperties'))
                    ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-page-open', Icon::SIZE_SMALL));
                $buttonBar->addButton($editPageButton, ButtonBar::BUTTON_POSITION_LEFT, 3);
            }
        }

        // Add refresh button to module docheader
        $refreshButton = $buttonBar->makeLinkButton()
            ->setHref('javascript:document.getElementById(\'tx_visualedit_iframe\').contentWindow.location.reload(true);')
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:visualedit/Resources/Private/Language/locallang.xlf:refreshPage'))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-refresh', Icon::SIZE_SMALL));
        $buttonBar->addButton($refreshButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);

        // Shortcut
        $mayMakeShortcut = $this->getBackendUser()->mayMakeShortcut();
        if ($mayMakeShortcut) {
            $getVars = ['id', 'route'];

            $shortcutButton = $buttonBar->makeShortcutButton()
                ->setModuleName('web_visualedit')
                ->setGetVariables($getVars);
            $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);
        }

        $this->addResponsiveUI($pageId, $buttonBar);
    }

    /**
     * Show selected page from pagetree in iframe
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = (int)($request->getParsedBody()['id'] ?? $request->getQueryParams()['id'] ?? 0);

        $this->initializeView('show');
        $this->moduleTemplate->setBodyTag('<body class="typo3-module-visualedit">');
        $this->moduleTemplate->setModuleName('typo3-module-visualedit');
        $this->moduleTemplate->setModuleId('typo3-module-visualedit');

        if (!$this->isValidDoktype($pageId)) {
            $flashMessage = GeneralUtility::makeInstance(
                FlashMessage::class,
                $this->getLanguageService()->getLL('noValidPageSelected'),
                '',
                FlashMessage::INFO
            );
            $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
            $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
            $defaultFlashMessageQueue->enqueue($flashMessage);
        } else {
            $languageId = $this->getCurrentLanguage($pageId, $request->getParsedBody()['language'] ?? $request->getQueryParams()['language'] ?? null);
            $targetUrl = BackendUtility::getPreviewUrl(
                $pageId,
                '',
                null,
                '',
                '',
                $this->getTypeParameterIfSet($pageId) . '&L=' . $languageId
            );
            $this->registerDocHeader($pageId, $languageId, $targetUrl, $request);

            $current = ($this->getBackendUser()->uc['moduleData']['web_view']['States']['current'] ?: []);
            $maximizeButtonLabel = $this->getLanguageService()->getLL('maximized');
            if ($current['label'] !== $maximizeButtonLabel) {
                $current['label'] = ($current['label'] ?? $this->getLanguageService()->sL('LLL:EXT:visualedit/Resources/Private/Language/locallang.xlf:custom'));
                $current['width'] = (isset($current['width']) && (int)$current['width'] >= 300 ? (int)$current['width'] : 320);
                $current['height'] = (isset($current['height']) && (int)$current['height'] >= 300 ? (int)$current['height'] : 480);
            }

            $this->view->assign('current', $current);
            $this->view->assign('url', $targetUrl);
        }

        $this->moduleTemplate->setContent($this->view->render());
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * With page TS config it is possible to force a specific type id via mod.web_visualedit.type
     * for a page id or a page tree.
     * The method checks if a type is set for the given id and returns the additional GET string.
     *
     * @param int $pageId
     * @return string
     */
    protected function getTypeParameterIfSet(int $pageId): string
    {
        $typeParameter = '';
        $typeId = (int)(BackendUtility::getPagesTSconfig($pageId)['mod.']['web_visualedit.']['type'] ?? 0);
        if ($typeId > 0) {
            $typeParameter = '&type=' . $typeId;
        }
        return $typeParameter;
    }

    /**
     * Get domain name for requested page id
     *
     * @param int $pageId
     * @return string|null Domain name from first sys_domains-Record or from TCEMAIN.previewDomain, NULL if neither is configured
     */
    protected function getDomainName(int $pageId)
    {
        $previewDomainConfig = BackendUtility::getPagesTSconfig($pageId)['TCEMAIN.']['previewDomain'] ?? '';
        return $previewDomainConfig ?: BackendUtility::firstDomainRecord(BackendUtility::BEgetRootLine($pageId));
    }

    /**
     * Get available presets for page id
     *
     * @param int $pageId
     * @return array
     */
    protected function getPreviewPresets(int $pageId): array
    {
        $presetGroups = [
            'desktop' => [],
            'tablet' => [],
            'mobile' => [],
            'unidentified' => []
        ];
        $previewFrameWidthConfig = BackendUtility::getPagesTSconfig($pageId)['mod.']['web_view.']['previewFrameWidths.'] ?? [];
        foreach ($previewFrameWidthConfig as $item => $conf) {
            $data = [
                'key' => substr($item, 0, -1),
                'label' => $conf['label'] ?? null,
                'type' => $conf['type'] ?? 'unknown',
                'width' => (isset($conf['width']) && (int)$conf['width'] > 0 && strpos($conf['width'], '%') === false) ? (int)$conf['width'] : null,
                'height' => (isset($conf['height']) && (int)$conf['height'] > 0 && strpos($conf['height'], '%') === false) ? (int)$conf['height'] : null,
            ];
            $width = (int)substr($item, 0, -1);
            if (!isset($data['width']) && $width > 0) {
                $data['width'] = $width;
            }
            if (!isset($data['label'])) {
                $data['label'] = $data['key'];
            } elseif (strpos($data['label'], 'LLL:') === 0) {
                $data['label'] = $this->getLanguageService()->sL(trim($data['label']));
            }

            if (array_key_exists($data['type'], $presetGroups)) {
                $presetGroups[$data['type']][$data['key']] = $data;
            } else {
                $presetGroups['unidentified'][$data['key']] = $data;
            }
        }

        return $presetGroups;
    }

    /**
     * Returns the preview languages
     *
     * @param int $pageId
     * @return array
     */
    protected function getPreviewLanguages(int $pageId): array
    {
        $localizationParentField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'];
        $languageField = $GLOBALS['TCA']['pages']['ctrl']['languageField'];
        $modSharedTSconfig = BackendUtility::getPagesTSconfig($pageId)['mod.']['SHARED.'] ?? [];
        if ($modSharedTSconfig['view.']['disableLanguageSelector'] === '1') {
            return [];
        }
        $languages = [
            0 => isset($modSharedTSconfig['defaultLanguageLabel'])
                    ? $modSharedTSconfig['defaultLanguageLabel'] . ' (' . $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf:defaultLanguage') . ')'
                    : $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf:defaultLanguage')
        ];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_language');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        if (!$this->getBackendUser()->isAdmin()) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $result = $queryBuilder->select('sys_language.uid', 'sys_language.title')
            ->from('sys_language')
            ->join(
                'sys_language',
                'pages',
                'o',
                $queryBuilder->expr()->eq('o.' . $languageField, $queryBuilder->quoteIdentifier('sys_language.uid'))
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'o.' . $localizationParentField,
                    $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)
                )
            )
            ->groupBy('sys_language.uid', 'sys_language.title', 'sys_language.sorting')
            ->orderBy('sys_language.sorting')
            ->execute();

        while ($row = $result->fetch()) {
            if ($this->getBackendUser()->checkLanguageAccess($row['uid'])) {
                $languages[$row['uid']] = $row['title'];
            }
        }
        return $languages;
    }

    /**
     * Returns the current language
     *
     * @param int $pageId
     * @param string $languageParam
     * @return int
     */
    protected function getCurrentLanguage(int $pageId, string $languageParam = null): int
    {
        $languageId = (int)$languageParam;
        if ($languageParam === null) {
            $states = $this->getBackendUser()->uc['moduleData']['web_view']['States'];
            $languages = $this->getPreviewLanguages($pageId);
            if (isset($states['languageSelectorValue']) && isset($languages[$states['languageSelectorValue']])) {
                $languageId = (int)$states['languageSelectorValue'];
            }
        } else {
            $this->getBackendUser()->uc['moduleData']['web_view']['States']['languageSelectorValue'] = $languageId;
            $this->getBackendUser()->writeUC($this->getBackendUser()->uc);
        }
        return $languageId;
    }

    /**
     * Verifies if doktype of given page is valid
     *
     * @param int $pageId
     * @return bool
     */
    protected function isValidDoktype(int $pageId = 0): bool
    {
        if ($pageId === 0) {
            return false;
        }

        $page = BackendUtility::getRecord('pages', $pageId);
        $pageType = (int)$page['doktype'] ?? 0;

        return $page !== null
            && $pageType !== PageRepository::DOKTYPE_SPACER
            && $pageType !== PageRepository::DOKTYPE_SYSFOLDER
            && $pageType !== PageRepository::DOKTYPE_RECYCLER;
    }

    /**
     * Check if page can be edited by current user
     *
     * @param int|null $languageId
     * @return bool
     */
    protected function isPageEditable(int $languageId): bool
    {
        if ($this->getBackendUser()->isAdmin()) {
            return true;
        }

        return !$this->pageinfo['editlock']
            && $this->getBackendUser()->doesUserHaveAccess($this->pageinfo, Permission::PAGE_EDIT)
            && $this->getBackendUser()->checkLanguageAccess($languageId);
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Add responsive UI to module docheader
     *
     * @param int $pageId
     * @param ButtonBar $buttonBar
     */
    private function addResponsiveUI(int $pageId, ButtonBar $buttonBar)
    {
        // Add device orientation button to module docheader
        $orientationButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setClasses('t3js-change-orientation')
            ->setTitle($this->getLanguageService()->getLL('orientationButtonTitle'))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-device-orientation-change', Icon::SIZE_SMALL));
        $buttonBar->addButton($orientationButton, ButtonBar::BUTTON_POSITION_LEFT, 10);

        // Add preset menu to module docheader
        // TODO: Implement a better UI for preset selection but actually this is the best I can do using TYPO3 API
        $presetSplitButtonElement = $buttonBar->makeSplitButton();
        // Current state
        $current = ($this->getBackendUser()->uc['moduleData']['web_view']['States']['current'] ?: []);
        $current['label'] = ($current['label'] ?? $this->getLanguageService()->sL('LLL:EXT:viewpage/Resources/Private/Language/locallang.xlf:custom'));
        $maximizeButtonLabel = $this->getLanguageService()->getLL('maximized');
        if ($current['label'] !== $maximizeButtonLabel) {
            $current['width'] = (isset($current['width']) && (int)$current['width'] >= 300 ? (int)$current['width'] : 320);
            $current['height'] = (isset($current['height']) && (int)$current['height'] >= 300 ? (int)$current['height'] : 480);
        }
        $currentButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setClasses('t3js-preset-current t3js-change-preset')
            ->setTitle($current['label'])
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('miscellaneous-placeholder', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setDataAttributes([
                'key' => 'current',
                'label' => $current['label'],
                'width' => '',
                'height' => ''
            ]);
        $presetSplitButtonElement->addItem($currentButton, true);
        // Maximize button
        $maximizeButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setClasses('t3js-preset-maximized t3js-change-preset')
            ->setTitle($maximizeButtonLabel . ' (100%x100%)')
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-fullscreen', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setDataAttributes([
                'key' => 'maximized',
                'label' => $maximizeButtonLabel,
                'width' => '',
                'height' => ''
            ]);
        $presetSplitButtonElement->addItem($maximizeButton);
        // Custom button
        $custom = ($this->getBackendUser()->uc['moduleData']['web_view']['States']['custom'] ?: []);
        $custom['width'] = (isset($custom['width']) && (int)$custom['width'] >= 300 ? (int)$custom['width'] : 320);
        $custom['height'] = (isset($custom['height']) && (int)$custom['height'] >= 300 ? (int)$custom['height'] : 480);
        $customButtonLabel = $this->getLanguageService()->getLL('custom');
        $customButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setClasses('t3js-preset-custom t3js-change-preset')
            ->setTitle($customButtonLabel . ' (' . $custom['width'] . 'x' . $custom['height'] . ')')
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-expand', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setDataAttributes([
                'key' => 'custom',
                'label' => $customButtonLabel,
                'width' => $custom['width'],
                'height' => $custom['height']
            ]);
        $presetSplitButtonElement->addItem($customButton);
        // Presets buttons
        $presetGroups = $this->getPreviewPresets($pageId);
        foreach ($presetGroups as $presetGroup => $presets) {
            $separatorButton = $buttonBar->makeLinkButton()
                ->setHref('#')
                ->setClasses('divider')
                ->setTitle('──────────────────')
                ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('miscellaneous-placeholder', Icon::SIZE_SMALL))
                ->setShowLabelText(true)
                ->setDisabled(true);
            $presetSplitButtonElement->addItem($separatorButton);
            foreach ($presets as $preset) {
                $presetButtonLabel = $preset['label'];
                $presetButton = $buttonBar->makeLinkButton()
                    ->setHref('#')
                    ->setClasses('t3js-change-preset')
                    ->setTitle($presetButtonLabel . ' (' . $preset['width'] . 'x' . $preset['height'] . ')')
                    ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-device-' . $presetGroup, Icon::SIZE_SMALL))
                    ->setShowLabelText(true)
                    ->setDataAttributes([
                        'key' => $preset['key'],
                        'label' => $presetButtonLabel,
                        'width' => $preset['width'],
                        'height' => $preset['height']
                    ]);
                $presetSplitButtonElement->addItem($presetButton);
            }
        }
        $buttonBar->addButton($presetSplitButtonElement, ButtonBar::BUTTON_POSITION_LEFT, 20);

        // Add width & height input to module docheader
        $sizeButtons = new FullyRenderedButton();
        $sizeButtons->setHtmlSource('
            <input class="t3js-visualedit-input-width" type="number" name="width" min="300" max="9999" value="' . $current['width'] . '">
            x
            <input class="t3js-visualedit-input-height" type="number" name="height" min="300" max="9999" value="' . $current['height'] . '">
        ');
        $buttonBar->addButton($sizeButtons, ButtonBar::BUTTON_POSITION_LEFT, 15);
    }
}
