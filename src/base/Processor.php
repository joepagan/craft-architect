<?php
/**
 * Architect plugin for Craft CMS 3.x
 *
 * CraftCMS plugin to generate content models from JSON/YAML data.
 *
 * @link      https://pennebaker.com
 * @copyright Copyright (c) 2018 Pennebaker
 */

namespace pennebaker\architect\base;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\errors\SiteNotFoundException;
use craft\models\FieldLayout;
use craft\base\Field;

/**
 * Processor
 *
 * @author    Pennebaker
 * @package   Architect
 * @since     2.0.0
 */
abstract class Processor implements ProcessorInterface
{
    /**
     * @param $item
     * @param $type
     *
     * @return FieldLayout
     */
    public function createFieldLayout($item, $type): FieldLayout
    {
        $fieldLayout = new FieldLayout();

        if (isset($item['fieldLayout'])) {
            foreach ($item['fieldLayout'] as $tab => $fields) {
                foreach ($item['fieldLayout'][$tab] as $k => $fieldHandle) {
                    $field = Craft::$app->fields->getFieldByHandle($fieldHandle);
                    if ($field) {
                        $item['fieldLayout'][$tab][$k] = $field->id;
                    } else {
                        unset($item['fieldLayout'][$tab][$k]);
                    }
                }
            }
            if (isset($item['requiredFields']) && is_array($item['requiredFields'])) {
                foreach ($item['requiredFields'] as $k => $fieldHandle) {
                    $field = Craft::$app->fields->getFieldByHandle($fieldHandle);
                    if ($field) {
                        $item['requiredFields'][$k] = $field->id;
                    } else {
                        unset($item['requiredFields'][$k]);
                    }
                }
            } else {
                $item['requiredFields'] = [];
            }
            $fieldLayout = Craft::$app->fields->assembleLayout($item['fieldLayout'], $item['requiredFields']);
        }
        $fieldLayout->type = $type;

        return $fieldLayout;
    }

    /**
     * @param array $obj
     * @return array
     */
    public function stripNulls(array $obj):array
    {
        $allowedNulls = [
            'maxLevels'
        ];
        foreach ($obj as $key => $value) {
            if (is_array($value)) {
                $obj[$key] = $this->stripNulls($value);
            } else if ($value === null && !in_array($key, $allowedNulls)) {
                unset($obj[$key]);
            }
        }
        return $obj;
    }

    /**
     * @param $item
     * @param string $type
     * @param string $handle
     * @param bool $prefix
     */
    public function mapService(&$item, $type, $handle, $prefix)
    {
        $service = null;
        switch ($type) {
            case 'asset':
                $type = 'volume';
                $service = Craft::$app->volumes->getVolumeByHandle($handle);
                if (!$service) {
                    $type = 'folder';
                    $service = Craft::$app->assets->findFolder(['name' => $handle]);
                }
                break;
            case 'volume':
                $service = Craft::$app->volumes->getVolumeByHandle($handle);
                break;
            case 'folder':
                $service = Craft::$app->assets->findFolder(['name' => $handle]);
                break;
            case 'section':
                $service = Craft::$app->sections->getSectionByHandle($handle);
                break;
            case 'group':
                $service = Craft::$app->categories->getGroupByHandle($handle);
                if (!$service) {
                    $service = Craft::$app->userGroups->getGroupByHandle($handle);
                }
                break;
            case 'taggroup':
                $service = Craft::$app->tags->getTagGroupByHandle($handle);
                break;
            case 'transform':
                $service = Craft::$app->assetTransforms->getTransformByHandle($handle);
                break;
            default:
                break;
        }
        if ($service) {
            if ($prefix) {
                $item = $type . ':' . $service->uid;
            } else {
                $item = $service->uid;
            }
        }
    }

    /**
     * @param array|string|null $obj
     * @param string $expectedType
     * @param bool $prefix
     */
    public function map(&$obj, $expectedType, $prefix = true)
    {
        if (is_string($obj)) {
            if ($obj !== '*' && $obj !== '') {
                try {
                    list($type, $handle) = explode(':', $obj);
                } catch (\Exception $e) {
                    $type = $expectedType;
                    $handle = $obj;
                }
                $this->mapService($obj, $type, $handle, $prefix);
            }
        } else if (is_array($obj)) {
            foreach ($obj as $k => &$item) {
                try {
                    list($type, $handle) = explode(':', $item);
                } catch (\Exception $e) {
                    $type = $expectedType;
                    $handle = $item;
                }
                $this->mapService($item, $type, $handle, $prefix);
            }
        } else {
            $obj = null;
        }
    }

    /**
     * @param $item
     * @param string $type
     * @param string $uid
     */
    public function unmapService(&$item, $type, $uid)
    {
        $service = null;
        switch ($type) {
            case 'volume':
                $service = Craft::$app->volumes->getVolumeByUid($uid);
                break;
            case 'folder':
                $service = Craft::$app->assets->getFolderByUid($uid);
                break;
            case 'section':
                $service = Craft::$app->sections->getSectionByUid($uid);
                break;
            case 'group':
                $service = Craft::$app->categories->getGroupByUid($uid);
                if (!$service) {
                    $service = Craft::$app->userGroups->getGroupByUid($uid);
                }
                break;
            case 'taggroup':
                $service = Craft::$app->tags->getTagGroupByUid($uid);
                break;
            case 'transform':
                $service = Craft::$app->assetTransforms->getTransformByUid($uid);
                break;
            default:
                break;
        }
        if ($service) {
            try {
                $handle = $service->handle;
            } catch (\Exception $e) {
                $handle = $service->name;
            }
            $item = $type . ':' . $handle;
        }
    }

    /**
     * @param array|string|null $obj
     * @param \stdClass|null $expectedType
     */
    public function unmap(&$obj, $expectedType = null)
    {
        if (is_string($obj)) {
            if ($obj !== '*' && $obj !== '') {
                list($type, $uid) = explode(':', $obj);
                $this->unmapService($obj, $type, $uid);
            }
        } else if (is_array($obj)) {
            foreach ($obj as $k => &$item) {
                try {
                    list($type, $uid) = explode(':', $item);
                } catch (\Exception $e) {
                    $type = $expectedType;
                    $uid = $item;
                }
                $this->unmapService($item, $type, $uid);
            }
        } else {
            $obj = null;
        }
    }

    /**
     * @param array|string $sites
     * @param string $prefix
     */
    public function mapSites(&$sites, $prefix = '', $useIds = false)
    {
        if (is_array($sites)) {
            foreach ($sites as $k => $siteHandle) {
                $site = Craft::$app->sites->getSiteByHandle($siteHandle);
                if ($site) {
                    if ($useIds) {
                        $sites[$k] = $prefix . $site->id;
                    } else {
                        $sites[$k] = $prefix . $site->uid;
                    }
                } else {
                    unset($sites[$k]);
                }
            }
        } else {
            $site = Craft::$app->sites->getSiteByHandle($sites);
            if ($site) {
                if ($useIds) {
                    $sites = $prefix . $site->id;
                } else {
                    $sites = $prefix . $site->uid;
                }
            } else {
                $sites = null;
            }
        }
    }

    /**
     * @param array|string|null $sites
     */
    public function unmapSites(&$sites)
    {
        if (is_array($sites)) {
            foreach ($sites as $k => $siteRef) {
                try {
                    $site = Craft::$app->sites->getSiteByUid($siteRef);
                    $sites[$k] = $site->handle;
                } catch (SiteNotFoundException $e) {
                    $site = Craft::$app->sites->getSiteById($siteRef);
                    if ($site) {
                        $sites[$k] = $site->handle;
                    } else {
                        unset($sites[$k]);
                    }
                }
            }
        } else if (is_string($sites)) {
            try {
                $site = Craft::$app->sites->getSiteByUid($sites);
                $sites = $site->handle;
            } catch (SiteNotFoundException $e) {
                $site = Craft::$app->sites->getSiteById($sites);
                if ($site) {
                    $sites = $site->handle;
                } else {
                    $sites = null;
                }
            }
        } else {
            $sites = null;
        }
    }

    /**
     * @param array $item
     */
    public function mapTypeSettings(array &$item)
    {
        if (isset($item['blockTypes']) && is_array($item['blockTypes'])) {
            foreach ($item['blockTypes'] as $blockKey => $blockType) {
                if (isset($blockType['fields']) && is_array($blockType['fields'])) {
                    foreach ($blockType['fields'] as $fieldKey => $field) {
                        $newField = [
                            'name' => $field['name'],
                            'handle' => $field['handle'],
                            'instructions' => $field['instructions'],
                            'required' => $field['required'],
                            'type' => $field['type'],
                        ];
                        unset($field['name']);
                        unset($field['handle']);
                        unset($field['instructions']);
                        unset($field['required']);
                        unset($field['type']);

                        $oldTypeSettings = [];
                        if (isset($field['typesettings'])) $oldTypeSettings = $field['typesettings'];
                        unset($field['typesettings']);

                        $newField['typesettings'] = array_merge($oldTypeSettings, $field);
                        $item['blockTypes'][$blockKey]['fields'][$fieldKey] = $newField;
                    }
                }
            }
        }
    }

    /**
     * @param string $class
     *
     * @return array|mixed
     */
    public function additionalAttributes(string $class) {
        $additionalAttributes = [];
        return (isset($additionalAttributes[$class])) ? $additionalAttributes[$class] : [];
    }

    /**
     * @param FieldLayout $fieldLayout
     *
     * @return array
     */
    public function exportFieldLayout($fieldLayout) {
        $fieldLayoutObj = [];
        $tabs = $fieldLayout->getTabs();
        usort($tabs, function($a, $b) {
            return $a->sortOrder > $b->sortOrder;
        });
        foreach ($tabs as $tab) {
            $fieldLayoutObj[$tab->name] = [];
            foreach ($tab->getFields() as $field) {
                array_push($fieldLayoutObj[$tab->name], $field->handle);
            }
        }
        return $fieldLayoutObj;
    }

    /**
     * @param FieldLayout $fieldLayout
     *
     * @return array
     */
    public function exportRequiredFields($fieldLayout) {
        $requiredFieldsObj = [];
        $tabs = $fieldLayout->getTabs();
        usort($tabs, function($a, $b) {
            return $a->sortOrder > $b->sortOrder;
        });
        foreach ($tabs as $tab) {
            foreach ($tab->getFields() as $field) {
                /** @var Field $field */
                if (boolval($field->required)) {
                    array_push($requiredFieldsObj, $field->handle);
                }
            }
        }
        return $requiredFieldsObj;
    }

    public function permissionMap() {
        return [
            'assignusergroup' => 'userGroup',
            'editsite' => 'site',
            'editentries' => 'section',
            'createentries' => 'section',
            'publishentries' => 'section',
            'deleteentries' => 'section',
            'editpeerentries' => 'section',
            'publishpeerentries' => 'section',
            'deletepeerentries' => 'section',
            'editpeerentrydrafts' => 'section',
            'publishpeerentrydrafts' => 'section',
            'deletepeerentrydrafts' => 'section',
            'editglobalset' => 'globalSet',
            'editcategories' => 'category',
            'viewvolume' => 'volume',
            'saveassetinvolume' => 'volume',
            'createfoldersinvolume' => 'volume',
            'deletefilesandfoldersinvolume' => 'volume',
            'utility' => 'utility',
        ];
    }

    /**
     * @param array $permissions
     */
    public function unmapPermissions(&$permissions) {
        $permissionMap = $this->permissionMap();
        foreach ($permissions as $key => $permission) {
            if (strpos($permission, ':') > 0) {
                list($permissionName, $permissionId) = explode(':', $permission);
                try {
                    switch ($permissionMap[$permissionName]) {
                        case 'userGroup':
                            $permissionHandle = Craft::$app->userGroups->getGroupByUid($permissionId)->handle;
                            break;
                        case 'site':
                            $permissionHandle = Craft::$app->sites->getSiteByUid($permissionId)->handle;
                            break;
                        case 'section':
                            $permissionHandle = Craft::$app->sections->getSectionByUid($permissionId)->handle;
                            break;
                        case 'globalSet':
                            $permissionHandle = (new Query())
                                ->from([Table::GLOBALSETS])
                                ->where(['uid' => $permissionId])
                                ->one()['handle'];
                            break;
                        case 'category':
                            $permissionHandle = Craft::$app->categories->getGroupByUid($permissionId)->handle;
                            break;
                        case 'volume':
                            $permissionHandle = Craft::$app->volumes->getVolumeByUid($permissionId)->handle;
                            break;
                        default:
                            $permissionHandle = $permissionId;
                            break;
                    }
                    $permissions[$key] = $permissionName . ':'. $permissionHandle;
                } catch (\Exception $e) {}
            }
        }
    }

    /**
     * @param array $permissions
     */
    public function mapPermissions(&$permissions) {
        $permissionMap = $this->permissionMap();
        foreach ($permissions as $key => $permission) {
            if (strpos($permission, ':') > 0) {
                list($permissionName, $permissionHandle) = explode(':', $permission);
                try {
                    switch ($permissionMap[$permissionName]) {
                        case 'userGroup':
                            $permissionId = Craft::$app->userGroups->getGroupByHandle($permissionHandle)->uid;
                            break;
                        case 'site':
                            $permissionId = Craft::$app->sites->getSiteByHandle($permissionHandle)->uid;
                            break;
                        case 'section':
                            $permissionId = Craft::$app->sections->getSectionByHandle($permissionHandle)->uid;
                            break;
                        case 'globalSet':
                            $permissionId = Craft::$app->globals->getSetByHandle($permissionHandle)->uid;
                            break;
                        case 'category':
                            $permissionId = Craft::$app->categories->getGroupByHandle($permissionHandle)->uid;
                            break;
                        case 'volume':
                            $permissionId = Craft::$app->volumes->getVolumeByHandle($permissionHandle)->uid;
                            break;
                        default:
                            $permissionId = $permissionHandle;
                            break;
                    }
                    $permissions[$key] = $permissionName . ':'. $permissionId;
                } catch (\Exception $e) {}
            }
        }
    }

    public function setPermissions($type, $item) {
        $this->mapPermissions($item['permissions']);
        switch ($type) {
            case 'users':
                Craft::$app->userPermissions->saveUserPermissions($item['id'], $item['permissions']);
                break;
            case 'userGroups':
                Craft::$app->userPermissions->saveGroupPermissions($item['id'], $item['permissions']);
                break;
        }
    }
}