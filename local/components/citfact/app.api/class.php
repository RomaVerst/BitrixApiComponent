<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

use Citfact\SiteCore\api\tdo\FeedbackTDO;
use Citfact\Sitecore\Orm\HashGenTable;
use Citfact\SiteCore\Core;
use \Bitrix\Main\Loader;


class AppAPI extends CBitrixComponent
{
    const CODE_GROUP = 'api_client';

    private $userId;
    private $iblockElement;
    private $iblockIDListForms;

    public function executeComponent()
    {
        header('Content-Type: application/json');

        try {
            Loader::includeModule('iblock');
            Loader::includeModule('form');
            Loader::includeModule('citfact.sitecore');
            $this->iblockElement = new CIBlockElement;
            $core = Core::getInstance();
            $this->iblockIDListForms = (int)$core->getIblockId($core::IBLOCK_CODE_FORMS_SENDING);
            switch ($_REQUEST['action']) {
                case 'setReqForm2CRM':
                    echo $this->setReqForm2CRM();
                    break;
                case 'getReqForms':
                    $this->initRequest();
                    echo $this->getReqForms();
                    break;
                default:
                    throw new \Exception('action not found');
            }
        } catch (Exception $e) {
            $responseAPI = json_encode(
                [
                    'status' => false,
                    'errorMsg' => $e->getMessage(),
                ]
            );
            if ($_REQUEST['action'] === 'getReqForms') {
                $this->bitrixLogAdd($_REQUEST, $responseAPI, 'ERROR', $e->getMessage());
            }
            echo $responseAPI;
        }
    }

    private function getIdHashGen($hash)
    {
        if (!empty($hash)) {
            $isHash = HashGenTable::getList([
                'filter' => ['=UF_HASH' => htmlspecialchars($hash)],
                'select' => ['ID']
            ])->fetch();
            if (isset($isHash['ID'])) {
                return $isHash['ID'];
            }
        }
        return false;
    }

    private function setReqForm2CRM()
    {
        $idHashGen = $this->getIdHashGen($_REQUEST['hash']);
        if ($idHashGen) {
            $arResult = CFormResult::GetByID((int)$_REQUEST['resultId'])->Fetch();
            $issetUserForm = CIBlockElement::GetList(
                ["SORT" => "ASC"],
                ['IBLOCK_ID' => $this->iblockIDListForms, '=NAME' => trim($_REQUEST['resultId'])],
                false,
                false,
                ['ID']
            )->Fetch();
            if (!$issetUserForm['ID']) {
                $arParamsToAdd = [
                    'IBLOCK_ID' => $this->iblockIDListForms,
                    'NAME' => $_REQUEST['resultId'],
                    'ACTIVE' => 'Y',
                    'ACTIVE_FROM' => $arResult['DATE_CREATE'],
                    'DATE_ACTIVE_FROM' => $arResult['DATE_CREATE']
                ];

                if ($this->iblockElement->Add($arParamsToAdd)) {
                    HashGenTable::delete($idHashGen);
                    return 'Form added successfully';
                } else {
                    throw new \Exception('Errors occurred while adding a request');
                }
            } else {
                throw new \Exception('This request has already been added to the list of forms for CRM');
            }
        } else {
            throw new \Exception('Access is denied or this request has already been added to the list');
        }
    }

    private function getReqForms()
    {
        $resListForms = CIBlockElement::GetList(
            ['SORT' => 'ASC'],
            [
                'IBLOCK_ID' => $this->iblockIDListForms,
                '>=DATE_ACTIVE_FROM' => $_REQUEST['dateFrom'],
                '<=DATE_ACTIVE_FROM' => $_REQUEST['dateTo']
            ],
            false,
            false,
            ['ID', 'NAME']
        );
        $arListForms = [];
        while ($arRes = $resListForms->GetNext()) {
            $arListForms[$arRes['ID']] = $arRes['NAME'];
        }

        if (!empty($arListForms)) {
            $arItems =  $this->getItemsFromIBForms($arListForms, ['s1', 'en']);
            $responseAPI = json_encode(
                [
                    'status' => true,
                    'errorMsg' => '',
                    'items' => array_values($arItems),
                ]
            );
            $this->bitrixLogAdd($_REQUEST, $responseAPI, 'INFO', 'success');
            return $responseAPI;
        } else {
            throw new \Exception('Not found any item in this range');
        }
    }

    private function getItemsFromIBForms($arListForms, $arLid) {
        $core = Core::getInstance();
        $arItems = [];
        $feedbackTDO = new FeedbackTDO();
        $idsToDel = [];
        foreach ($arLid as $lid) {
            $webFormID = (int)$core->getWebFormId($core::getCodeFormFeedback($lid));
            CForm::GetResultAnswerArray(
                $webFormID,
                $columns,
                $answers,
                $answers2,
                [
                    'RESULT_ID' => array_keys($arListForms)
                ]
            );
            foreach ($arListForms as $idEl => $resultId) {
                $arItems[$resultId]['id'] = $resultId;
                foreach ($columns as $column) {
                    $fieldForm = $column['TITLE'];
                    $attrApi = $feedbackTDO->getAttrApiByFieldForm($fieldForm, $lid);
                    if ($attrApi) {
                        switch ($answers2[$resultId][$column['VARNAME']][0]['FIELD_TYPE']) {
                            case 'dropdown':
                                $arItems[$resultId][$attrApi] = $answers2[$resultId][$column['VARNAME']][0]['ANSWER_TEXT'];
                                break;
                            case 'file':
                                $idFile = $answers2[$resultId][$column['VARNAME']][0]['USER_FILE_ID'];
                                $pathFile = CFile::GetPath($idFile);
                                $fileContent = file_get_contents( $_SERVER['DOCUMENT_ROOT'] . $pathFile);
                                $arItems[$resultId][$attrApi] = base64_encode($fileContent);
                                break;
                            default:
                                $arItems[$resultId][$attrApi] = $answers2[$resultId][$column['VARNAME']][0]['USER_TEXT'];
                                break;
                        }
                    }
                }
                if (!in_array($idEl, $idsToDel)) $idsToDel[] = $idEl;
            }
        }
        foreach ($idsToDel as $elemId) {
            CIBlockElement::Delete($elemId);
        }
        return $arItems;
    }

    private function bitrixLogAdd($request, $response, $severity, $status)
    {
        CEventLog::Add(array(
            "SEVERITY" => $severity,
            "AUDIT_TYPE_ID" => "API_SEND2CRM",
            "MODULE_ID" => "main",
            "DESCRIPTION" => "Request => $request; Response => $response; Status => $status",
        ));
    }

    private function initRequest()
    {
        $userResult = \CUser::GetList($by = 'id', $order = 'asc',
            array('UF_API_KEY' => $this->getApiKey()),
            array('FIELDS' => array('ID', 'NAME', 'LAST_NAME', 'EMAIL', 'PERSONAL_PHONE', 'WORK_PHONE'))
        );
        $user = $userResult->fetch();

        if ($user && $this->getApiKey() !== false) {
            $this->user = $user;
            $this->userId = $user['ID'];
        } else {
            header("HTTP/1.1 403 Forbidden");
            throw new \Exception('User not found');
        }

        if (!$this->checkAccessUser()) {
            header("HTTP/1.1 403 Forbidden");
            throw new \Exception('Access denied');
        }
    }

    private function getApiKey()
    {
        if (isset($_SERVER['HTTP_X_API_KEY']) && !empty($_SERVER['HTTP_X_API_KEY'])) {
            return $_SERVER['HTTP_X_API_KEY'];
        }
        return false;
    }

    private function checkAccessUser()
    {
        $userGroups = \CUser::GetUserGroup($this->userId);
        $groupId = $this->getGroupIdApiClient();
        if (!$groupId) {
            throw new \Exception('There is no group for API clients');
        }
        if (!in_array($groupId, $userGroups)) {
            return false;
        }
        return true;
    }

    private function getGroupIdApiClient()
    {
        return $this->getGroupIdByCode(static::CODE_GROUP);
    }

    private function getGroupIdByCode($code)
    {
        $rsGroups = \CGroup::GetList($by = "c_sort", $order = "asc", array("STRING_ID" => $code));
        $result = $rsGroups->Fetch();
        if ($result) {
            return $result['ID'];
        }
        return false;
    }
}