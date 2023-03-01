<?php
declare(strict_types=1);

namespace SourceBroker\Imageopt\Xclass;

use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ImageManipulationElement extends \TYPO3\CMS\Backend\Form\Element\ImageManipulationElement
{
    public function __construct(NodeFactory $nodeFactory, array $data)
    {
        parent::__construct($nodeFactory, $data);
        $this->templateView->setLayoutRootPaths(array_merge(
            $this->templateView->getLayoutRootPaths(),
            [GeneralUtility::getFileAbsFileName('EXT:imageopt/Resources/Private/Layouts/')]
        ));
        $this->templateView->setPartialRootPaths(array_merge(
            $this->templateView->getPartialRootPaths(),
            [GeneralUtility::getFileAbsFileName('EXT:imageopt/Resources/Private/Partials/ImageManipulation/')]
        ));
        $this->templateView->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName('EXT:imageopt/Resources/Private/Templates/ImageManipulation/ImageManipulationElement.html'));
    }


    protected function processConfiguration(array $config, string &$elementValue, File $file): array
    {
        $originalElementValue = json_decode($elementValue, true);
        $config = parent::processConfiguration($config, $elementValue, $file);
        foreach ($originalElementValue as $key => $settings) {
            if (!empty($config['cropVariants'][$key])) {
                $config['cropVariants'][$key]['rotate'] = (int)($settings['rotate'] ?? 0);
                $originalElementValue[$key]['rotate'] = $config['cropVariants'][$key]['rotate'];
            }
        }
        $elementValue = json_encode($originalElementValue);
        return $config;
    }
}
