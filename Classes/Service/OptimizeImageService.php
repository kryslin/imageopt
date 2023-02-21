<?php

/***************************************************************
 *  Copyright notice
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace SourceBroker\Imageopt\Service;

use SourceBroker\Imageopt\Configuration\Configurator;
use SourceBroker\Imageopt\Domain\Dto\Image;
use SourceBroker\Imageopt\Domain\Model\ModeResult;
use SourceBroker\Imageopt\Domain\Model\StepResult;
use SourceBroker\Imageopt\Provider\OptimizationProvider;
use SourceBroker\Imageopt\Utility\TemporaryFileUtility;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Optimize single image using multiple Image Optmization Provider.
 * The best optimization wins!
 */
class OptimizeImageService
{
    /**
     * @var object|Configurator
     */
    public $configurator;

    /**
     * @var TemporaryFileUtility
     */
    private $temporaryFile;

    /**
     * OptimizeImageService constructor.
     * @param array $config
     * @throws \Exception
     */
    public function __construct($config = null)
    {
        if ($config === null) {
            throw new \Exception('Configuration not set for OptimizeImageService class');
        }

        $this->configurator = GeneralUtility::makeInstance(Configurator::class, $config);
        $this->configurator->init();

        $this->temporaryFile = GeneralUtility::makeInstance(TemporaryFileUtility::class);
    }

    /**
     * Optimize image using chained Image Optimization Provider
     *
     * @param string $originalImagePath
     * @return ModeResult[]
     * @throws \Exception
     */
    public function optimize(Image $image)
    {
        $modeResults = [];
        foreach ((array)$this->configurator->getOption('mode') as $modeKey => $modeConfig) {
            $regexp = '@' . $modeConfig['fileRegexp'] . '@';
            $modeConfig['name'] = $modeKey;
            if (!$image->matchExtension($regexp)) {
                continue;
            }
            $modeResults[$modeKey] = $this->optimizeSingleMode(
                $modeConfig,
                $image
            );
        }

        return $modeResults;
    }

    /**
     * @param array $modeConfig
     * @param Image $image
     * @return ModeResult
     * @throws \Exception
     */
    protected function optimizeSingleMode($modeConfig, Image $image)
    {
        $modeResult = GeneralUtility::makeInstance(ModeResult::class)
            ->setFileAbsolutePath($image->getLocalImagePath())
            ->setName($modeConfig['name'])
            ->setDescription($modeConfig['description'])
            ->setSizeBefore(filesize($image->getLocalImagePath()))
            ->setExecutedSuccessfully(false);

        $chainImagePath = $this->temporaryFile->createTemporaryCopy($image->getLocalImagePath());

        foreach ($modeConfig['step'] as $stepKey => $stepConfig) {
            $stepResult = GeneralUtility::makeInstance(StepResult::class)
                ->setExecutedSuccessfully(false)
                ->setSizeBefore(filesize($chainImagePath))
                ->setSizeAfter(filesize($chainImagePath))
                ->setName($stepKey)
                ->setDescription(!empty($stepConfig['description']) ? $stepConfig['description'] : $stepKey);

            $providers = $this->configurator->getProviders(
                $stepConfig['providerType'],
                strtolower(explode('/', image_type_to_mime_type(getimagesize($image->getLocalImagePath())[2]))[1]),
                $stepConfig['providerSettings'] ?: []
            );
            $this->optimizeWithBestProvider($stepResult, $chainImagePath, $image, $providers);
            $modeResult->addStepResult($stepResult);
        }
        if ($modeResult->getExecutedSuccessfullyNum() == $modeResult->getStepResults()->count()) {
            $modeResult->setExecutedSuccessfully(true);
        }

        clearstatcache(true, $chainImagePath);
        $modeResult->setSizeAfter(filesize($chainImagePath));

        $pathInfo = pathinfo($image->getLocalImagePath());
        copy($chainImagePath, str_replace(
            ['{dirname}', '{basename}', '{extension}', '{filename}'],
            [$pathInfo['dirname'], $pathInfo['basename'], $pathInfo['extension'], $pathInfo['filename']],
            $modeConfig['outputFilename']
        ));

        return $modeResult;
    }

    /**
     * @param $stepResult
     * @param string $chainImagePath
     * @param Image $image
     * @param array $providers
     * @return StepResult
     * @throws \Exception
     */
    protected function optimizeWithBestProvider($stepResult, string $chainImagePath, Image $image, array $providers)
    {
        clearstatcache(true, $chainImagePath);

        $providerExecutedCounter = 0;
        $providerExecutedSuccessfullyCounter = 0;
        $providerEnabledCounter = 0;

        // work on chain image copy
        $tmpBestImagePath = $this->temporaryFile->createTemporaryCopy($chainImagePath);

        foreach ($providers as $providerKey => $providerConfig) {
            $providerConfig['providerKey'] = $providerKey;
            $providerConfigurator = GeneralUtility::makeInstance(Configurator::class, $providerConfig);

            if (empty($providerConfigurator->getOption('enabled'))) {
                continue;
            }

            $providerEnabledCounter++;
            $providerExecutedCounter++;

            $tmpWorkingImagePath = $this->temporaryFile->createTemporaryCopy($chainImagePath);
            $optimizationProvider = GeneralUtility::makeInstance(OptimizationProvider::class);

            $providerResult = $optimizationProvider->optimize($tmpWorkingImagePath, $image, $providerConfigurator);

            if ($providerResult->isExecutedSuccessfully()) {
                $providerExecutedSuccessfullyCounter++;
                clearstatcache(true, $tmpWorkingImagePath);

                if (filesize($tmpWorkingImagePath) < filesize($tmpBestImagePath)) {
                    // overwrite current (in chain link) best image
                    $tmpBestImagePath = $tmpWorkingImagePath;
                    $stepResult->setProviderWinnerName($providerKey);
                    $stepResult->setSizeAfter(filesize($tmpBestImagePath));
                }
            }
            $stepResult->addProvidersResult($providerResult);
        }

        if ($providerEnabledCounter === 0) {
            $stepResult->setInfo('No providers enabled (or defined).');
            $stepResult->setExecutedSuccessfully(true);
        } elseif ($providerExecutedSuccessfullyCounter === 0) {
            $stepResult->setInfo('No winner. All providers in this step were unsuccessfull.');
        } else {
            $stepResult->setExecutedSuccessfully(true);
            if ($stepResult->getOptimizationBytes() === 0) {
                $stepResult->setInfo('No winner of this step. Non of the optimized images were smaller than original.');
            } else {
                if ($stepResult->getProviderWinnerName()) {
                    $stepResult->setInfo('Winner is ' . $stepResult->getProviderWinnerName() .
                        ' with optimized image smaller by: ' .
                        round($stepResult->getOptimizationPercentage(), 2) . '%');
                }
                clearstatcache(true, $tmpBestImagePath);
                // overwrite chain image with current best image
                copy($tmpBestImagePath, $chainImagePath);
            }
        }
        clearstatcache(true, $chainImagePath);
    }
}
