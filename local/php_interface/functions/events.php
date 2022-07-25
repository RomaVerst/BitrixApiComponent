<?php

AddEventHandler("main", "OnBeforeEventAdd", "addLink2CRM");

function addLink2CRM(&$event, &$lid, &$arFields)
{
    \Bitrix\Main\Loader::includeModule('citfact.sitecore');
    if ($event === \Citfact\SiteCore\Core::getTypeEventFormFeedback($lid)) {
        $hash = md5($arFields['RS_RESULT_ID'] . $arFields['EMAIL_RAW']) . randString(20);
        $result = \Citfact\Sitecore\Orm\HashGenTable::add(['UF_HASH' => $hash]);
        if ($result->isSuccess()) {
            $linkAdd2Crm = 'https://' . SITE_SERVER_NAME . '/rest/?action=setReqForm2CRM&hash=' . $hash . '&resultId=' . $arFields['RS_RESULT_ID'];
            $arFields['ADD2CRM_URL'] = $linkAdd2Crm;
            $arFields['ADD2CRM_LINK'] = '<a href="' . $linkAdd2Crm . '">Добавить обращение в CRM</a>';
        }
    }
}
