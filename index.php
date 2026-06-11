<?php

declare(strict_types=1);

header("Content-Type: application/json");

require __DIR__ . "/../bootstrap.php";
require __DIR__ . "/../services/AuctionWorker.php";

// Run background worker on every request (tick-based approach)
$worker = new AuctionWorker($db);
$worker->tick();

// -----------------------------------------------------------------------
// Routes
// -----------------------------------------------------------------------

// Queries
$router->get("/auction/list",        [AuctionController::class, "list"]);
$router->get("/auction/get",         [AuctionController::class, "get"]);
$router->get("/auction/bids",        [AuctionController::class, "bids"]);
$router->get("/auction/mine",        [AuctionController::class, "mine"]);
$router->get("/auction/mybids",      [AuctionController::class, "myBids"]);
$router->get("/auction/claim_funds", [AuctionController::class, "claimFunds"]);
$router->get("/auction/stats",       [AuctionController::class, "stats"]);

// Draft
$router->post("/auction/draft/save",    [AuctionController::class, "draftSave"]);
$router->get("/auction/draft/get",      [AuctionController::class, "draftGet"]);
$router->post("/auction/draft/update",  [AuctionController::class, "draftUpdate"]);
$router->post("/auction/draft/confirm", [AuctionController::class, "draftConfirm"]);
$router->post("/auction/draft/cancel",  [AuctionController::class, "draftCancel"]);

// Pending items (devoluções de draft)
$router->get("/auction/claim_items",    [AuctionController::class, "claimItems"]);

// Actions
$router->post("/auction/create",      [AuctionController::class, "create"]);
$router->post("/auction/bid",         [AuctionController::class, "bid"]);
$router->post("/auction/claim_item",      [AuctionController::class, "claimItem"]);
$router->post("/auction/claim_item_back", [AuctionController::class, "claimItemBack"]);
$router->post("/auction/claim_coins",     [AuctionController::class, "claimCoins"]);
$router->post("/auction/cancel",      [AuctionController::class, "cancel"]);

$router->run();
