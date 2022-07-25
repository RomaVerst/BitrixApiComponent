<?php
namespace Citfact\Sitecore\Orm;
use Bitrix\Main\Entity;
class HashGenTable extends Entity\DataManager {
    public static function getTableName()
    {
        return 'hash_for_set_req_form';
    }
    public static function getFilePath()
    {
        return __FILE__;
    }
    public static function getMap()
    {
        return array(
            new Entity\IntegerField('ID', array(
                'primary' => true,
                'autocomplete' => true
            )),
            new Entity\StringField('HASH', array(
                'required' => true,
            ))
        );
    }
}