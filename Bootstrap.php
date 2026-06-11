<?php

$config = require __DIR__ . "/config/config.php";

require __DIR__ . "/core/Database.php";
require __DIR__ . "/core/Router.php";

require __DIR__ . "/repositories/AuctionRepository.php";
require __DIR__ . "/services/AuctionService.php";
require __DIR__ . "/controllers/AuctionController.php";

$db = Database::getInstance($config)->getConnection();

$repository = new AuctionRepository($db);
$repository->createTables();

$auctionService = new AuctionService($db, $config["auction"] ?? []);

$router = new Router($config);
