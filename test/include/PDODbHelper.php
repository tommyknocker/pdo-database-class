<?php

/**
 * PDODb testing helper
 *
 * @author    Vasiliy A. Ulin <ulin.vasiliy@gmail.com>
 * @license http://www.gnu.org/licenses/lgpl.txt LGPLv3
 */
class PDODbHelper
{
    /**
     * Create table
     * 
     * @param string $name
     * @param array $data
     */
    function createTable($name, $data)
    {
        $PDODb = PDODb::getInstance();
        $PDODb->rawQuery("DROP TABLE IF EXISTS $name");
        $query = "CREATE TABLE $name (id INT(9) UNSIGNED PRIMARY KEY AUTO_INCREMENT";
        foreach ($data as $key => $value) {
            $query .= ", $key $value";
        }
        $query .= ")";
        $PDODb->rawQuery($query);
    }
}