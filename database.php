<?php

class Database {

    private static $instance = null;
    private $pdo;

    private function __construct($config) {

        $db = $config["database"];

        $dsn = "mysql:host={$db["host"]};port={$db["port"]};dbname={$db["database"]};charset=utf8mb4";

        $this->pdo = new PDO(
            $dsn,
            $db["username"],
            $db["password"],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );
    }

    public static function getInstance($config) {

        if (self::$instance == null) {
            self::$instance = new Database($config);
        }

        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }
}
