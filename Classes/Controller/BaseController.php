<?php
namespace Mediadreams\MdNewsfrontend\Controller;

/**
 *
 * This file is part of the "News frontend" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2019 Christoph Daecke <typo3@mediadreams.org>
 *
 */

use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Messaging\AbstractMessage;

/**
 * Base controllerUnreadnewsController
 */
class BaseController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * @var array
     */
    protected $uploadFields = ['falMedia', 'falRelatedFiles'];

    /**
     * newsRepository
     *
     * @var \Mediadreams\MdNewsfrontend\Domain\Repository\NewsRepository
     * @inject
     */
    protected $newsRepository = null;

    /**
     * userRepository
     *
     * @var \TYPO3\CMS\Extbase\Domain\Repository\FrontendUserRepository
     * @inject
     */
    protected $userRepository = null;

    /**
     * Deactivate errorFlashMessage
     *
     * @return bool|string
     */
    public function getErrorFlashMessage()
    {
        return false;
    }

    /**
     * Initializes the view and pass additional data to template
     *
     * @param \TYPO3\CMS\Extbase\Mvc\View\ViewInterface $view The view to be initialized
     */
    protected function initializeView(\TYPO3\CMS\Extbase\Mvc\View\ViewInterface $view)
    {
        if ( strlen($this->settings['parentCategory']) > 0 ) {
            $categoryRepository = $this->objectManager->get(\TYPO3\CMS\Extbase\Domain\Repository\CategoryRepository::class);
            $categories = $categoryRepository->findByParent($this->settings['parentCategory']);

            // Assign categories to template
            $view->assign('categories', $categories);
        }

        parent::initializeView($view);
    }

    /**
     * Initialize actions
     * Add possibility to overwrite settings
     */
    protected function initializeAction()
    {
        // Use stdWrap for given defined settings
        // Thanks to Georg Ringer: https://github.com/georgringer/news/blob/2c8522ad508fa92ad39a5effe4301f7d872238a5/Classes/Controller/NewsController.php#L597
        if (
            isset($this->settings['useStdWrap']) 
            && !empty($this->settings['useStdWrap'])
        ) {
            $typoScriptService = GeneralUtility::makeInstance(TypoScriptService::class);
            $typoScriptArray = $typoScriptService->convertPlainArrayToTypoScriptArray($this->settings);
            $stdWrapProperties = GeneralUtility::trimExplode(',', $this->settings['useStdWrap'], true);
            foreach ($stdWrapProperties as $key) {
                if (is_array($typoScriptArray[$key . '.'])) {
                    $this->settings[$key] = $this->configurationManager->getContentObject()->stdWrap(
                        $typoScriptArray[$key],
                        $typoScriptArray[$key . '.']
                    );
                }
            }
        }

        // Get logged in user
        if ($GLOBALS['TSFE']->fe_user->user) {
            $this->feuserUid = $GLOBALS['TSFE']->fe_user->user['uid'];
            $this->feuserObj = $this->userRepository->findByUid($this->feuserUid);
        }

        parent::initializeAction();
    }

    /**
     * Check, if news record belongs to user
     * If news record does not belong to user, redirect to list action
     *
     * @param \Mediadreams\MdNewsfrontend\Domain\Model\News $newsRecord
     * @return void
     */
    protected function checkAccess(\Mediadreams\MdNewsfrontend\Domain\Model\News $newsRecord)
    {
        if ($newsRecord->getMdNewsfrontendFeuser()->getUid() != $this->feuserUid) {
            $this->addFlashMessage(
                LocalizationUtility::translate('controller.access_error','md_newsfrontend'),
                '', 
                AbstractMessage::ERROR
            );

            $this->redirect('list');
        }
    }

    /**
     * Get storage pid for news record
     * If a pid was set via form value use this, otherwise use value from typoscript settings
     *
     * @param \Mediadreams\MdNewsfrontend\Domain\Model\News $newsRecord
     * @return int
     */
    protected function getStoragePid(\Mediadreams\MdNewsfrontend\Domain\Model\News $newsRecord)
    {
        if ($newsRecord->getPid() > 0) {
            return $newsRecord->getPid();
        } else {
            return (int)$this->settings['storagePid'];
        }
    }

    /**
     * Get storage pid for news record
     * If a pid was set via form value use this, otherwise use value from typoscript settings
     *
     * @param array $requestArguments
     * @param \TYPO3\CMS\Extbase\Mvc\Controller\Argument $argument
     * @return void
     */
    protected function initializeCreateUpdate($requestArguments, $argument)
    {
        // add validator for upload fields
        $this->initializeFileValidator($requestArguments, $argument);

        // remove category from request, if it was not provided
        if ( empty($requestArguments[$argument->getName()]['categories']) ) {
            unset($requestArguments[$argument->getName()]['categories']);
            $this->request->setArguments($requestArguments);
        }

        // use correct format for datetime
        $argument
            ->getPropertyMappingConfiguration()
            ->forProperty('archive')
            ->setTypeConverterOption(
                'TYPO3\\CMS\\Extbase\\Property\\TypeConverter\\DateTimeConverter',
                \TYPO3\CMS\Extbase\Property\TypeConverter\DateTimeConverter::CONFIGURATION_DATE_FORMAT,
                'd.m.Y H:i'
            );
    }

    /**
     * Initialize the file upload for configured fields
     *
     * @param array $requestArguments
     * @param \Mediadreams\MdNewsfrontend\Domain\Model\News $obj
     * @return void
     */
    protected function initializeFileUpload($requestArguments, $obj)
    {
        foreach ($this->uploadFields as $fieldName) {
            if ( !empty($requestArguments[$fieldName]['tmp_name']) ) {
                // upload new file and update file reference (meta data)
                \Mediadreams\MdNewsfrontend\Utility\FileUpload::handleUpload(
                    $requestArguments, 
                    $obj, 
                    $fieldName, 
                    $this->settings,
                    $this->feuserUid
                );
            } else {
                $methodName = 'getFirst'.ucfirst($fieldName);
                if ( $obj->$methodName() ) {
                    // update meta data
                    $this->updateFileReference(
                        $obj->$methodName()->getUid(), 
                        $requestArguments[$fieldName]
                    );
                }
            }
        }
    }

    /**
     * Initialize the upload validators for configured fields
     *
     * @param array $requestArguments
     * @param \TYPO3\CMS\Extbase\Mvc\Controller\Argument $argument
     * @return void
     */
    protected function initializeFileValidator($requestArguments, $argument)
    {
        foreach ($this->uploadFields as $fieldName) {
            $this->addFileuploadValidator(
                $argument, 
                $requestArguments[$fieldName], 
                $this->settings['allowed_'.$fieldName]
            );
        }
    }

    /**
     * Add the file upload validator for given object and field
     *
     * @param News $news
     * @return void
     */
    protected function addFileuploadValidator($arguments, $fieldName, $allowedFileExtensions)
    {
        $validator = $arguments->getValidator();

        $checkFileUploadValidator = $this->objectManager->get(
            'Mediadreams\MdNewsfrontend\Domain\Validator\CheckFileUpload', 
            array(
                'filesArr'              => $fieldName,
                'allowedFileExtensions' => $allowedFileExtensions,
            )
        );

        $validator->addValidator($checkFileUploadValidator);
    }

    /**
     * Update meta data of file references
     *
     * @param int $fileReferencesUid uid of sys_file_reference record
     * @param array $fileData All data about the file
     * @return void
     */
    protected function updateFileReference($fileReferencesUid, $fileData)
    {
        $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
                        ->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder
            ->update('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid', $fileReferencesUid)
            )
            ->set('tstamp', time())
            ->set('title', $fileData['title'])
            ->set('description', $fileData['description'])
            ->execute();
    }

    /**
     * Flush cache
     * See: GeorgRinger\News\Hooks\DataHandler::clearCachePostProc()
     *
     * @param int $newsUid Uid of news record
     * @param int $newsPid Pid of news record
     */
    protected function clearNewsCache($newsUid, $newsPid)
    {
        $cacheTagsToFlush = [];

        if ($newsUid) {
            $cacheTagsToFlush[] = 'tx_news_uid_' . $newsUid;
        }

        if ($newsPid) {
            $cacheTagsToFlush[] = 'tx_news_pid_' . $newsPid;
        }

        $cacheManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class);
        foreach ($cacheTagsToFlush as $cacheTag) {
            $cacheManager->flushCachesInGroupByTag('pages', $cacheTag);
        }
    }
}
