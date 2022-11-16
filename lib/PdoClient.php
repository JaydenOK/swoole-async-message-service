<?php

namespace module\lib;

use module\FluentPDO\Query;

class PdoClient
{

    protected function database()
    {
        $configDir = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
        $filePath = $configDir . 'database.php';
        if (!file_exists($filePath)) {
            throw new \Exception('database config not exist:' . $filePath);
        }
        return include($filePath);
    }

    public function getQuery()
    {
        $config = $this->database();
        $pdo = new \PDO("mysql:dbname={$config['dbname']};host={$config['host']};charset={$config['charset']}", "{$config['user']}", "{$config['password']}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
        $pdo->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_LOWER);
        $fluent = new Query($pdo);
        return $fluent;
    }

}