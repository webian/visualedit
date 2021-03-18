<?php
declare(strict_types = 1);

namespace Webian\Visualedit\Middleware;

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
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\FrontendBackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webian\Visualedit\DataHandling\FrontendEditDataHandler;

/**
 * PSR-15 middleware initializing frontend editing
 *
 * @internal this is a concrete TYPO3 implementation and solely used for EXT:visualedit and not part of TYPO3's Core API.
 */
class FrontendEditInitiator implements MiddlewareInterface
{

    /**
     * Process an incoming server request and return a response, optionally delegating
     * response creation to a handler.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Process only if valid BE user has access to Visualedit module
        if (
            isset($GLOBALS['BE_USER']) &&
            $GLOBALS['BE_USER'] instanceof FrontendBackendUserAuthentication &&
            $GLOBALS['BE_USER']->check('modules', 'web_visualedit')
        ) {
            $GLOBALS['TSFE']->displayEditIcons = '1';
            $GLOBALS['TSFE']->displayFieldEditIcons = '1';
            $GLOBALS['TSFE']->set_no_cache('EXT:Visualedit - Frontend editing', true);
//            $parameters = [];
//            GeneralUtility::makeInstance(FrontendEditDataHandler::class, $parameters)->editAction();
        }
        return $handler->handle($request);
    }
}
