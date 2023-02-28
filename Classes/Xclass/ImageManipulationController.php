<?php
declare(strict_types=1);

namespace SourceBroker\Imageopt\Xclass;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

class ImageManipulationController extends \TYPO3\CMS\Backend\Controller\Wizard\ImageManipulationController
{
    public function __construct(StandaloneView $templateView = null)
    {
        if ($templateView === null) {
            $templateView = GeneralUtility::makeInstance(StandaloneView::class);
        }
        $templateView->setLayoutRootPaths([
            GeneralUtility::getFileAbsFileName('EXT:backend/Resources/Private/Layouts/'),
            GeneralUtility::getFileAbsFileName('EXT:imageopt/Resources/Private/Layouts/'),
        ]);
        $templateView->setPartialRootPaths([
            GeneralUtility::getFileAbsFileName('EXT:backend/Resources/Private/Partials/ImageManipulation/'),
            GeneralUtility::getFileAbsFileName('EXT:imageopt/Resources/Private/Partials/ImageManipulation/')
        ]);
        $templateView->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName('EXT:imageopt/Resources/Private/Templates/ImageManipulation/ImageManipulationWizard.html'));
        parent::__construct($templateView);
    }
}
