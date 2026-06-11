<?php

require_once __DIR__ . "/../core/AuctionErrorCode.php";

class AuctionController {

    private $service;
    private $config;

    public function __construct($service, $config = []) {
        $this->service = $service;
        $this->config  = $config;
    }

    private function auth(): void {

        $apiKey = $this->config["auth"]["api_key"] ?? null;

        if (!$apiKey) return;

        $header = $_SERVER["HTTP_X_API_KEY"] ?? null;

        if ($header !== $apiKey) {
            http_response_code(401);
            echo json_encode(["error" => "Unauthorized"]);
            exit;
        }
    }

    public function create() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["seller", "item", "starting_price", "duration", "category"]);

        $id = $this->service->createAuction(
            $data["seller"],
            $data["item"],
            $data["starting_price"],
            $data["duration"],
            $data["category"]
        );

        $this->respond(["auction_id" => $id]);
    }

    public function bid() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["auction_id", "player", "amount"]);

        $this->service->placeBid(
            $data["auction_id"],
            $data["player"],
            $data["amount"]
        );

        $this->respond(["success" => true]);
    }

    public function list() {
        $this->auth();
        $from     = isset($_GET["from"])     ? (int) $_GET["from"]  : 0;
        $to       = isset($_GET["to"])       ? (int) $_GET["to"]    : 49;
        $category = isset($_GET["category"]) ? $_GET["category"]    : null;

        $auctions = $this->service->getActiveAuctions($from, $to, $category);
        $this->respond($auctions);
    }

    public function get() {
        $this->auth();
        $id = $_GET["id"] ?? null;
        if (!$id) $this->error("Missing auction id", 400);

        $auction = $this->service->getAuction($id);
        if (!$auction) $this->error("Auction not found", 404);

        $this->respond($auction);
    }

    public function bids() {
        $this->auth();
        $id = $_GET["id"] ?? null;
        if (!$id) $this->error("Missing auction id", 400);

        $bids = $this->service->getAuctionBids($id);
        $this->respond($bids);
    }

    public function mine() {
        $this->auth();
        $player = $_GET["player"] ?? null;
        if (!$player) $this->error("Missing player", 400);

        $auctions = $this->service->getPlayerAuctions($player);
        $this->respond($auctions);
    }

    public function myBids() {
        $this->auth();
        $player = $_GET["player"] ?? null;
        if (!$player) $this->error("Missing player", 400);

        $bids = $this->service->getPlayerBids($player);
        $this->respond($bids);
    }

    public function claimFunds() {
        $this->auth();
        $player = $_GET["player"] ?? null;
        if (!$player) $this->error("Missing player", 400);

        $funds = $this->service->claimPendingFunds($player);
        $this->respond($funds);
    }

    public function claimItem() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["auction_id", "player"]);

        $result = $this->service->claimItem($data["auction_id"], $data["player"]);
        $this->respond($result);
    }

    public function claimItemBack() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["auction_id", "player"]);

        $result = $this->service->claimItemBack($data["auction_id"], $data["player"]);
        $this->respond($result);
    }

    public function claimCoins() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["auction_id", "player"]);

        $result = $this->service->claimCoins($data["auction_id"], $data["player"]);
        $this->respond($result);
    }

    public function cancel() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["auction_id", "seller"]);

        $this->service->cancelAuction($data["auction_id"], $data["seller"]);
        $this->respond(["success" => true]);
    }

    public function stats() {
        $this->auth();
        $stats = $this->service->getStats();
        $this->respond($stats);
    }

    public function draftSave() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["player", "item"]);

        $draftId = $this->service->saveDraft(
            $data["player"],
            $data["item"],
            $data["category"] ?? "MISC"
        );

        $this->respond(["draft_id" => $draftId]);
    }

    public function draftGet() {
        $this->auth();
        $player = $_GET["player"] ?? null;
        if (!$player) $this->error("Missing player", 400);

        $draft = $this->service->getDraft($player);
        if (!$draft) $this->error("Draft not found", 404, AuctionErrorCode::DRAFT_NOT_FOUND);

        $this->respond($draft);
    }

    public function draftUpdate() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["player", "starting_price", "duration"]);

        $this->service->updateDraft(
            $data["player"],
            $data["starting_price"],
            $data["duration"]
        );

        $this->respond(["success" => true]);
    }

    public function draftConfirm() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["player"]);

        $auctionId = $this->service->confirmDraft($data["player"]);
        $this->respond(["auction_id" => $auctionId]);
    }

    public function draftCancel() {
        $this->auth();
        $data = $this->parseBody();
        $this->requireFields($data, ["player"]);

        $result = $this->service->cancelDraft($data["player"]);
        $this->respond($result);
    }

    public function claimItems() {
        $this->auth();
        $player = $_GET["player"] ?? null;
        if (!$player) $this->error("Missing player", 400);

        $result = $this->service->claimPendingItems($player);
        $this->respond($result);
    }

    private function parseBody(): array {
        $body = json_decode(file_get_contents("php://input"), true);
        if (!is_array($body)) $this->error("Invalid JSON body", 400);
        return $body;
    }

    private function requireFields(array $data, array $fields): void {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === "") {
                $this->error("Missing required field: {$field}", 400, AuctionErrorCode::MISSING_REQUIRED_FIELD);
            }
        }
    }

    private function respond(mixed $data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }

    private function error(string $message, int $httpCode = 400, int $errorCode = 0): void {
        http_response_code($httpCode);
        echo json_encode(["error" => $message, "code" => $errorCode]);
        exit;
    }
}
