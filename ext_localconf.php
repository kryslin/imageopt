<?php

defined('TYPO3_MODE') || die('Access denied.');

$GLOBALS['TYPO3_CONF_VARS']['EXT']['EXTCONF']['imageopt']['database'] = \SourceBroker\Imageopt\Database\Database87::class;

// Few xclasses to make TYPO3 to create copy of images even if not needed.
// This way we can make optimization on copies always to not destroy original images.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][TYPO3\CMS\Core\Resource\Service\FileProcessingService::class] = [
    'className' => SourceBroker\Imageopt\Xclass\FileProcessingService::class
];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::class] = [
    'className' => SourceBroker\Imageopt\Xclass\ContentObjectRenderer::class
];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][TYPO3\CMS\Backend\Form\Element\ImageManipulationElement::class] = [
    'className' => SourceBroker\Imageopt\Xclass\ImageManipulationElement::class
];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][TYPO3\CMS\Backend\Controller\Wizard\ImageManipulationController::class] = [
    'className' => SourceBroker\Imageopt\Xclass\ImageManipulationController::class
];

if (TYPO3_MODE === "BE" )   {
    $pageRenderer = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class);
    $pageRenderer->loadRequireJsModule(
        'TYPO3/CMS/Imageopt/RotateModule'
    );
}
