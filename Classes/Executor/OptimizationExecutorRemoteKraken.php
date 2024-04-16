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

namespace SourceBroker\Imageopt\Executor;

use SourceBroker\Imageopt\Configuration\Configurator;
use SourceBroker\Imageopt\Domain\Dto\Image;
use SourceBroker\Imageopt\Domain\Model\ExecutorResult;
use SourceBroker\Imageopt\Resource\CroppedFileRepository;
use SourceBroker\Imageopt\Utility\GraphicalFunctionsUtility;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class OptimizationExecutorRemoteKraken extends OptimizationExecutorRemote
{
    /**
     * Initialize executor
     *
     * @param Configurator $configurator
     * @return bool
     */
    protected function initConfiguration(Configurator $configurator)
    {
        $result = parent::initConfiguration($configurator);
        if ($result) {
            if (!isset($this->auth['key']) || !isset($this->auth['pass'])) {
                $result = false;
            } elseif (!isset($this->url['upload'])) {
                $result = false;
            }
            if (!isset($this->apiOptions['quality']) && isset($this->executorOptions['quality'])) {
                $this->apiOptions['quality'] = (int)$this->executorOptions['quality']['value'];
            }
            if (isset($this->apiOptions['quality'])) {
                $this->apiOptions['quality'] = (int)$this->apiOptions['quality'];
            }
        }
        return $result;
    }

    /**
     * Upload file to kraken.io and save it if optimization will be success
     *
     * @param string $inputImageAbsolutePath Absolute path/file with original image
     * @param Image $image
     * @param ExecutorResult $executorResult
     */
    protected function process(string $inputImageAbsolutePath, Image $image, ExecutorResult $executorResult)
    {
        $options = $this->apiOptions;
        $options['wait'] = true; // wait for processed file (forced option)
        $options['auth'] = [
            'api_key' => $this->auth['key'],
            'api_secret' => $this->auth['pass'],
        ];
        foreach ($options as $key => $value) {
            if ($value === 'true' || $value === 'false') {
                $options[$key] = $value === 'true';
            }
        }

        $resize = [];

        if (!empty($this->providerSettings['processOriginalFile']) && $image->hasProcessingConfiguration()) {
            $processingConfiguration = $image->getProcessingConfiguration();
            if (!$this->createCroppedFile($inputImageAbsolutePath, $image, $options)) {
                copy($image->getOriginalImagePath(), $inputImageAbsolutePath);
            }

            $resize = [
                'resize' => $this->getResizeSettings($inputImageAbsolutePath, $processingConfiguration)
            ];
        }

        $post = [
            'file' => curl_file_create($inputImageAbsolutePath),
            'data' => json_encode(array_merge($options, $resize)),
        ];

        $result = $this->request($post, $this->url['upload'], ['type' => 'upload']);
        $executorResult->setCommand('URL: ' . $this->url['upload'] . " \n" . 'POST: ' . $post['data']);
        if ($result['success']) {
            if (isset($result['response']['kraked_url'])) {
                $download = $this->getFileFromRemoteServer($inputImageAbsolutePath, $result['response']['kraked_url']);
                if ($download) {
                    $executorResult->setExecutedSuccessfully(true);
                    $executorResult->setCommandStatus('Done');
                } else {
                    $executorResult->setErrorMessage('Unable to download image');
                    $executorResult->setCommandStatus('Failed');
                }
            } else {
                $executorResult->setErrorMessage('Download URL not defined');
                $executorResult->setCommandStatus('Failed');
            }
        } else {
            $executorResult->setErrorMessage($result['error']);
            $executorResult->setCommandStatus('Failed');
        }
    }

    protected function getResizeSettings(string $imageFilePath, array $processingConfiguration): array
    {
        /** @var GraphicalFunctionsUtility $graphicalFunctions */
        $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctionsUtility::class);
        $info = $graphicalFunctions->getImageDimensionsWithoutExtension($imageFilePath);
        $data = $graphicalFunctions->getImageScale(
            $info,
            $processingConfiguration['width'] ?? '',
            $processingConfiguration['height'] ?? '',
            $graphicalFunctions->getConfigurationForImageCropScale($processingConfiguration)
        );

        [$width, $height] = $data;

        if ($data['crs']) {
            if (!$data['origW']) {
                $data['origW'] = $data[0];
            }
            if (!$data['origH']) {
                $data['origH'] = $data[1];
            }

            $finalWidth = min($width, $data['origW']);
            $finalHeight = min($height, $data['origH']);

            [$originalWidth, $originalHeight] = $info;

            $originalRatio = $originalWidth / $originalHeight;
            $finalRatio = $finalWidth / $finalHeight;

            $focusX = $processingConfiguration['focusPoint']['x'] ?? 0.5 ?: 0.5;
            $focusY = $processingConfiguration['focusPoint']['y'] ?? 0.5 ?: 0.5;

            if ($originalRatio > $finalRatio) {
                $width = $originalHeight * $finalRatio;
                $scale = $finalWidth / $width * 100;
                $height = $originalHeight;
            } else {
                $width = $originalWidth;
                $height = $originalWidth / $finalRatio;
                $scale = $finalHeight / $height * 100;
            }

            $offsetX = $originalWidth - $width;
            if ($originalWidth - ($originalWidth * $focusX) >= $width / 2) {
                $offsetX = (int)(($originalWidth * $focusX) - $width / 2);
            }
            $offsetX = max($offsetX, 0);

            $offsetY = $originalHeight - $height;
            if ($originalHeight - ($originalHeight * $focusY) >= $height / 2) {
                $offsetY = (int)(($originalHeight * $focusY) - $height / 2);
            }
            $offsetY = max($offsetY, 0);

            return [
                'width' => $width,
                'height' => $height,
                'x' => $offsetX,
                'y' => $offsetY,
                'scale' => $scale,
                'strategy' => 'crop'
            ];
        }
        return [
            'width' => $width,
            'height' => $height,
            'strategy' => 'exact'
        ];
    }

    /**
     * Executes request to remote server
     *
     * @param array $data Array with data and file path
     * @param string $url API kraken.io url
     * @param array $params Additional parameters
     * @return array Result of optimization includes the response from the kraken.io
     */
    protected function request($data, $url, array $params = [])
    {
        $options = [
            'curl' => [
                CURLOPT_CAINFO => ExtensionManagementUtility::extPath('imageopt') . 'Resources/Private/Cert/cacert.pem',
                CURLOPT_SSL_VERIFYPEER => 1,
            ],
        ];
        if (isset($params['type']) && $params['type'] === 'url') {
            $options['curl'][CURLOPT_HTTPHEADER] = [
                'Content-Type: application/json',
            ];
        }
        $responseFromAPI = parent::request($data, $url, $options);
        $handledResponse = $this->handleResponseError($responseFromAPI);
        if ($handledResponse !== null) {
            return [
                'success' => false,
                'error' => $handledResponse
            ];
        }
        $response = json_decode($responseFromAPI['response'], true, 512);
        if ($response === null) {
            $result = [
                'success' => false,
                'error' => 'Unable to decode JSON',
            ];
        } elseif (!isset($response['success']) || $response['success'] === false) {
            $message = isset($response['message'])
                ? $response['message']
                : 'Undefined error';

            $result = [
                'success' => false,
                'error' => 'API error: ' . $message,
            ];
        } else {
            $result = [
                'success' => true,
                'response' => $response,
            ];
        }
        return $result;
    }

    protected function createCroppedFile(string $imageFilePath, Image $image, array $requestOptions): ?string
    {
        $processingConfiguration = $image->getProcessingConfiguration();
        if (!empty($processingConfiguration['crop'])) {
            $crop = $processingConfiguration['crop'];
            if ($crop instanceof Area) {
                $croppedFileRepository = GeneralUtility::makeInstance(CroppedFileRepository::class);
                $croppedFile = $croppedFileRepository->findOneByProcessedFileAndProvider($image->getProcessedFile(), 'kraken');

                if ($croppedFile->isOutdated()) {
                    $post = [
                        'file' => curl_file_create($image->getOriginalImagePath()),
                        'data' => json_encode(
                            array_merge(
                                $requestOptions,
                                [
                                    'resize' => [
                                        'strategy' => 'crop',
                                        'width' => $crop->getWidth(),
                                        'height' => $crop->getHeight(),
                                        'x' => $crop->getOffsetLeft(),
                                        'y' =>  $crop->getOffsetTop(),
                                    ]
                                ]
                            )
                        )
                    ];

                    $result = $this->request($post, $this->url['upload'], ['type' => 'upload']);
                    if ($result['success'] && isset($result['response']['kraked_url'])) {
                        $download = $this->getFileFromRemoteServer($imageFilePath, $result['response']['kraked_url']);
                        if ($download) {
                            $croppedFile->updateWithLocalFile($imageFilePath);
                        }
                    }
                    $croppedFileRepository->add($croppedFile);
                } else {
                    copy($croppedFile->getForLocalProcessing(false), $imageFilePath);
                }

                return true;
            }
        }

        return false;
    }
}
