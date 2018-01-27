<?php
/**
 * Architect plugin for Craft CMS 3.x
 *
 * CraftCMS plugin to generate content models from JSON data.
 *
 * @link      https://pennebaker.com
 * @copyright Copyright (c) 2018 Pennebaker
 */

namespace pennebaker\architect\controllers;

use pennebaker\architect\Architect;

use Craft;
use craft\web\Controller;

/**
 * Default Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Pennebaker
 * @package   Architect
 * @since     2.0.0
 */
class DefaultController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
//    protected $allowAnonymous = [];

    // Public Methods
    // =========================================================================

    /**
     * Handle importing json object,
     * e.g.: actions/architect/default/import
     *
     * @throws \Throwable
     */
    public function actionImport()
    {
        $jsonData = Craft::$app->request->getBodyParam('jsonData');

        $jsonObj = json_decode($jsonData, true);

        if ($jsonObj === null) {
            $this->renderTemplate('architect/import', [
                'invalidJson' => json_last_error(),
                'jsonData' => $jsonData,
            ]);
            return;
        }

        $backup = Craft::$app->getDb()->backup(); // TODO: Create backup before performing import

        $noErrors = true;

        $parseOrder = [
            'sections',
            'fieldGroups',
            'fields',
            'entryTypes',
        ];

        $processors = [
            'sections' => 'section',
            'entryTypes' => 'entryType',
            'fields' => 'field',
            'fieldGroups' => 'fieldGroup',
        ];

        $results = [];
        $successfulSections = [];
        $addedEntryTypes = [];
        foreach ($parseOrder as $parseKey) {
            $processor = $processors[$parseKey];
            if (isset($jsonObj[$parseKey]) && is_array($jsonObj[$parseKey])) {
                $results[$parseKey] = [];
                foreach ($jsonObj[$parseKey] as $itemObj) {
                    if ($parseKey === 'fieldGroups') {
                        list($item, $itemErrors) = Architect::$processors->$processor->parse(['name' => $itemObj]);
                    } else {
                        list($item, $itemErrors) = Architect::$processors->$processor->parse($itemObj);
                    }

                    if ($parseKey === 'entryTypes' && array_search($itemObj['sectionHandle'], $successfulSections) === false) {
                        if (!isset($itemObj['name'])) $itemObj['name'] = '';
                        if (!isset($itemObj['handle'])) $itemObj['handle'] = $itemObj['sectionHandle'];
                        $item = false;
                        $itemErrors = [
                            'parent' => [
                                'Section parent "' . $itemObj['sectionHandle'] . '" was not imported successfully.'
                            ]
                        ];
                    }

                    if ($item) {
                        $itemSuccess = Architect::$processors->$processor->save($item);
                        if ($parseKey === 'sections') {
                            $itemErrors = [];

                            foreach ($item->getSiteSettings() as $settings) {
                                foreach ($settings->getErrors() as $errorKey => $errors) {
                                    if (isset($itemErrors[$errorKey])) {
                                        $itemErrors[$errorKey] = array_merge($itemErrors[$errorKey], $errors);
                                    } else {
                                        $itemErrors[$errorKey] = $errors;
                                    }
                                }
                            }
                            $itemErrors = array_merge($itemErrors, $item->getErrors());
                        } else {
                            $itemErrors = $item->getErrors();
                        }
                    } else {
                        $itemSuccess = false;
                    }

                    if (!$itemSuccess) $noErrors = false;

                    if ($parseKey === 'fieldGroups') {
                        $item = ($item) ? $item : ['name' => $itemObj];
                    } else {
                        $item = ($item) ? $item : $itemObj;
                    }
                    if ($itemSuccess) {
                        if ($parseKey === 'entryTypes') {
                            $addedEntryTypes[] =  Craft::$app->sections->getSectionById($item->sectionId)->handle . ':' . $item->handle;
                        }
                        switch ($parseKey) {
                            case 'sections':
                                $successfulSections[] = $item->handle;
                                break;
                        }
                    }
                    $results[$parseKey][] = [
                        'item' => $item,
                        'success' => $itemSuccess,
                        'errors' => $itemErrors,
                    ];
                }
            }
        }

        /**
         * Post Processing on Section Entry Types
         * This is to loop over all entry types in a section and remove entry types that do not match one that was meant to be created.
         * ex. A section was created for Employees but there is only entry types defined for Board Members & Management
         */
        if (isset($jsonObj['sections']) && is_array($jsonObj['sections']) && isset($jsonObj['entryTypes']) && is_array($jsonObj['entryTypes'])) {
            forEach ($successfulSections as $sectionHandle) {
                $section = Craft::$app->sections->getSectionByHandle($sectionHandle);
                $entryTypes = $section->getEntryTypes();
                foreach ($entryTypes as $entryType) {
                    if (array_search($section->handle. ':' . $entryType->handle, $addedEntryTypes) === false) {
                        Craft::$app->sections->deleteEntryType($entryType);
                    }
                }
            }
        }

        if ($noErrors) {
            unlink($backup);
        } else {
            Architect::warning('Architect encountered errors performing an import, there is a database backup located at: ' . $backup);
        }

        $this->renderTemplate('architect/import_results', [
            'noErrors' => $noErrors,
            'backupLocation' => $backup,
            'results' => $results,
            'jsonData' => $jsonData,
        ]);

    }
}
