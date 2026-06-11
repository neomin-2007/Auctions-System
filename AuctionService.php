<?php

require_once __DIR__ . "/../core/AuctionErrorCode.php";

class AuctionService {

    private $pdo;
    private $config;

    public function __construct($pdo, $config = []) {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    public function saveDraft($player, $item, $category = "MISC") {

        if (empty($player) || empty($item)) {
            throw new InvalidArgumentException("Player and item are required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $id  = uniqid("draft_", true);
        $now = time();

        $stmt = $this->pdo->prepare("
            INSERT INTO draft_auctions (id, player, item, category, starting_price, duration, created_at)
            VALUES (?, ?, ?, ?, 1, 3600, ?)
            ON DUPLICATE KEY UPDATE
                id           = VALUES(id),
                item         = VALUES(item),
                category     = VALUES(category),
                starting_price = 1,
                duration     = 3600,
                created_at   = VALUES(created_at)
        ");
        $stmt->execute([
            $id,
            $player,
            is_array($item) ? json_encode($item) : $item,
            strtoupper($category),
            $now
        ]);

        $fetch = $this->pdo->prepare("SELECT id FROM draft_auctions WHERE player = ?");
        $fetch->execute([$player]);
        return $fetch->fetchColumn();
    }

    public function getDraft($player) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $stmt = $this->pdo->prepare("SELECT * FROM draft_auctions WHERE player = ?");
        $stmt->execute([$player]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);

        return $draft ?: null;
    }

    public function updateDraft($player, $startingPrice, $duration) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $stmt = $this->pdo->prepare("
            UPDATE draft_auctions
            SET starting_price = ?, duration = ?
            WHERE player = ?
        ");
        $stmt->execute([(int) $startingPrice, (int) $duration, $player]);

        return true;
    }

    public function confirmDraft($player) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("SELECT * FROM draft_auctions WHERE player = ? FOR UPDATE");
            $stmt->execute([$player]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$draft) {
                throw new RuntimeException("Draft not found", AuctionErrorCode::DRAFT_NOT_FOUND);
            }

            // Deleta o draft ANTES de criar o leilão para liberar o UNIQUE slot
            $del = $this->pdo->prepare("DELETE FROM draft_auctions WHERE player = ?");
            $del->execute([$player]);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->createAuction(
            $player,
            $draft["item"],
            $draft["starting_price"],
            $draft["duration"],
            $draft["category"]
        );
    }

    public function cancelDraft($player) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("SELECT * FROM draft_auctions WHERE player = ? FOR UPDATE");
            $stmt->execute([$player]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$draft) {
                $this->pdo->rollBack();
                throw new RuntimeException("Draft not found", AuctionErrorCode::DRAFT_NOT_FOUND);
            }

            $del = $this->pdo->prepare("DELETE FROM draft_auctions WHERE player = ?");
            $del->execute([$player]);

            $refund = $this->pdo->prepare("
                INSERT INTO pending_items (player, item, reason, claimed, created_at)
                VALUES (?, ?, 'DRAFT_RETURN', 0, ?)
            ");
            $refund->execute([$player, $draft["item"], time()]);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ["item" => $draft["item"]];
    }

    public function claimPendingItems($player) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("
                SELECT * FROM pending_items
                WHERE player = ? AND claimed = 0
                FOR UPDATE
            ");
            $stmt->execute([$player]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$items) {
                $this->pdo->commit();
                return ["items" => []];
            }

            $ids = array_column($items, "id");
            $placeholders = implode(",", array_fill(0, count($ids), "?"));

            $upd = $this->pdo->prepare("
                UPDATE pending_items SET claimed = 1
                WHERE id IN ({$placeholders})
            ");
            $upd->execute($ids);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ["items" => array_column($items, "item")];
    }

    public function createAuction($seller, $item, $startingPrice, $duration, $category = "MISC") {

        if (empty($seller) || empty($item)) {
            throw new InvalidArgumentException("Seller and item are required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        if ($startingPrice < 1) {
            throw new InvalidArgumentException("Starting price must be at least 1", AuctionErrorCode::INVALID_STARTING_PRICE);
        }

        $maxDuration = $this->config["max_duration"] ?? 604800;
        $minDuration = $this->config["min_duration"] ?? 60;

        if ($duration < $minDuration || $duration > $maxDuration) {
            throw new InvalidArgumentException("Duration must be between {$minDuration} and {$maxDuration} seconds", AuctionErrorCode::INVALID_DURATION);
        }

        $validCategories = $this->config["categories"] ?? ["WEAPONS","ARMOR","TOOLS","CONSUMABLES","MISC"];
        $category = strtoupper($category);

        if (!in_array($category, $validCategories)) {
            throw new InvalidArgumentException("Invalid category. Valid: " . implode(", ", $validCategories), AuctionErrorCode::INVALID_CATEGORY);
        }

        $this->checkRateLimit();

        $id  = uniqid("auc_", true);
        $now = time();
        $end = $now + (int) $duration;

        $stmt = $this->pdo->prepare("
            INSERT INTO auctions
            (id, seller, item, category, starting_price, highest_bid, highest_bidder,
             end_time, status, seller_claimed, winner_claimed, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)
        ");

        $stmt->execute([
            $id,
            $seller,
            is_array($item) ? json_encode($item) : $item,
            $category,
            (int) $startingPrice,
            (int) $startingPrice,
            null,
            $end,
            "ACTIVE",
            $now
        ]);

        $this->recordRateLimit();
        $this->recordStat($seller, "CREATE", 0, $id, $category);

        return $id;
    }

    private function checkRateLimit() {

        $limit    = $this->config["rate_limit_per_minute"] ?? 15;
        $windowStart = time() - 60;

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM rate_limit
            WHERE action = 'CREATE_AUCTION' AND created_at >= ?
        ");
        $stmt->execute([$windowStart]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $limit) {
            throw new RuntimeException("Server auction rate limit reached ({$limit}/min). Try again shortly.", AuctionErrorCode::AUCTION_RATE_LIMIT_REACHED);
        }
    }

    private function recordRateLimit() {

        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limit (action, created_at) VALUES ('CREATE_AUCTION', ?)
        ");
        $stmt->execute([time()]);

        // Clean entries older than 2 minutes to keep table small
        $stmt = $this->pdo->prepare("
            DELETE FROM rate_limit WHERE created_at < ?
        ");
        $stmt->execute([time() - 120]);
    }

    private function recordStat($player, $action, $amount, $auctionId, $category = "MISC") {

        $stmt = $this->pdo->prepare("
            INSERT INTO auction_stats (player, action, amount, auction_id, category, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$player, $action, $amount, $auctionId, $category, time()]);
    }

    public function getStats() {

        $stats = [];


        $stmt = $this->pdo->query("SELECT COUNT(*) FROM auction_stats WHERE action = 'CREATE'");
        $stats["total_auctions_created"] = (int) $stmt->fetchColumn();


        $stmt = $this->pdo->query("SELECT COUNT(*) FROM auctions WHERE status = 'FINISHED'");
        $stats["total_auctions_finished"] = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM auctions WHERE status = 'ACTIVE' AND end_time > ?");
        $stmt->execute([time()]);
        $stats["total_auctions_active"] = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM pending_funds WHERE reason = 'SALE'");
        $stats["total_coins_transacted"] = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->query("
            SELECT a.id, a.item, a.category, a.highest_bid, a.seller, a.highest_bidder
            FROM auctions a
            WHERE a.status = 'FINISHED' AND a.highest_bidder IS NOT NULL
            ORDER BY a.highest_bid DESC
            LIMIT 1
        ");
        $stats["most_expensive_sale"] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $this->pdo->query("
            SELECT player, SUM(amount) as total_earned
            FROM pending_funds
            WHERE reason = 'SALE'
            GROUP BY player
            ORDER BY total_earned DESC
            LIMIT 5
        ");
        $stats["top_sellers"] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->query("
            SELECT highest_bidder as player, SUM(highest_bid) as total_spent
            FROM auctions
            WHERE status = 'FINISHED' AND highest_bidder IS NOT NULL
            GROUP BY highest_bidder
            ORDER BY total_spent DESC
            LIMIT 5
        ");
        $stats["top_buyers"] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->query("
            SELECT category, COUNT(*) as total
            FROM auctions
            GROUP BY category
            ORDER BY total DESC
        ");
        $stats["auctions_per_category"] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }

    public function placeBid($auctionId, $bidder, $amount) {

        if (empty($auctionId) || empty($bidder)) {
            throw new InvalidArgumentException("Auction ID and bidder are required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $amount = (int) $amount;
        $minIncrement = $this->config["min_bid_increment"] ?? 1;

        $this->pdo->beginTransaction();

        try {


            $stmt = $this->pdo->prepare("
                SELECT * FROM auctions WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$auctionId]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auction) {
                throw new RuntimeException("Auction not found", AuctionErrorCode::AUCTION_NOT_FOUND);
            }

            if ($auction["status"] !== "ACTIVE") {
                throw new RuntimeException("Auction is not active", AuctionErrorCode::AUCTION_NOT_ACTIVE);
            }

            if ($auction["end_time"] <= time()) {
                throw new RuntimeException("Auction has ended", AuctionErrorCode::AUCTION_ENDED);
            }

            if ($auction["seller"] === $bidder) {
                throw new RuntimeException("Seller cannot bid on their own auction", AuctionErrorCode::SELLER_CANNOT_BID);
            }

            $minRequired = (int) $auction["highest_bid"] + $minIncrement;

            if ($amount < $minRequired) {
                throw new RuntimeException("Bid must be at least {$minRequired}", AuctionErrorCode::BID_TOO_LOW);
            }

            $update = $this->pdo->prepare("
                UPDATE auctions
                SET highest_bid = ?, highest_bidder = ?
                WHERE id = ? AND highest_bid < ?
            ");

            $update->execute([$amount, $bidder, $auctionId, $amount]);

            if ($update->rowCount() === 0) {
                throw new RuntimeException("Your bid was beaten by another player. Try again!", AuctionErrorCode::BID_RACE_CONDITION);
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO bids (auction_id, bidder, amount, time)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([$auctionId, $bidder, $amount, time()]);

            $this->pdo->commit();

            $this->recordStat($bidder, "BID", $amount, $auctionId, $auction["category"] ?? "MISC");

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    public function claimItem($auctionId, $player) {

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("
                SELECT * FROM auctions WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$auctionId]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auction) {
                throw new RuntimeException("Auction not found", AuctionErrorCode::AUCTION_NOT_FOUND);
            }

            if ($auction["status"] !== "FINISHED") {
                throw new RuntimeException("Auction is not finished yet", AuctionErrorCode::AUCTION_NOT_FINISHED);
            }

            if ($auction["highest_bidder"] !== $player) {
                throw new RuntimeException("You did not win this auction", AuctionErrorCode::NOT_THE_WINNER);
            }

            if ($auction["winner_claimed"]) {
                throw new RuntimeException("Item already claimed", AuctionErrorCode::ITEM_ALREADY_CLAIMED);
            }

            $upd = $this->pdo->prepare("
                UPDATE auctions SET winner_claimed = 1 WHERE id = ?
            ");
            $upd->execute([$auctionId]);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->tryDeleteAuction(
            $auctionId,
            (bool) $auction["seller_claimed"],
            true, // winner just claimed
            true  // had a bidder (we checked above)
        );

        return [
            "auction_id" => $auctionId,
            "item"       => json_decode($auction["item"], true) ?? $auction["item"],
            "winner"     => $player
        ];
    }

    public function claimCoins($auctionId, $player) {

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("
                SELECT * FROM auctions WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$auctionId]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auction) {
                throw new RuntimeException("Auction not found", AuctionErrorCode::AUCTION_NOT_FOUND);
            }

            if ($auction["status"] !== "FINISHED") {
                throw new RuntimeException("Auction is not finished yet", AuctionErrorCode::AUCTION_NOT_FINISHED);
            }

            if ($auction["seller"] !== $player) {
                throw new RuntimeException("You are not the seller of this auction", AuctionErrorCode::NOT_THE_SELLER);
            }

            if (!$auction["highest_bidder"]) {
                throw new RuntimeException("No bids were placed on this auction", AuctionErrorCode::NO_BIDS_PLACED);
            }

            if ($auction["seller_claimed"]) {
                throw new RuntimeException("Coins already claimed", AuctionErrorCode::COINS_ALREADY_CLAIMED);
            }

            $upd = $this->pdo->prepare("
                UPDATE auctions SET seller_claimed = 1 WHERE id = ?
            ");
            $upd->execute([$auctionId]);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->tryDeleteAuction(
            $auctionId,
            true, // seller just claimed
            (bool) $auction["winner_claimed"],
            !empty($auction["highest_bidder"])
        );

        return [
            "auction_id" => $auctionId,
            "amount"     => (int) $auction["highest_bid"],
            "seller"     => $player
        ];
    }

    private function tryDeleteAuction($auctionId, $sellerClaimed = null, $winnerClaimed = null, $hasBidder = null) {

        // If values not passed, fetch from DB
        if ($sellerClaimed === null) {
            $stmt = $this->pdo->prepare("
                SELECT seller_claimed, winner_claimed, highest_bidder
                FROM auctions WHERE id = ?
            ");
            $stmt->execute([$auctionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return;

            $sellerClaimed = (bool) $row["seller_claimed"];
            $winnerClaimed = (bool) $row["winner_claimed"];
            $hasBidder     = !empty($row["highest_bidder"]);
        }

        $readyToDelete = $sellerClaimed && (!$hasBidder || $winnerClaimed);

        if ($readyToDelete) {
            $del = $this->pdo->prepare("DELETE FROM auctions WHERE id = ?");
            $del->execute([$auctionId]);
        }
    }

    public function claimItemBack($auctionId, $player) {

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("
                SELECT * FROM auctions WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$auctionId]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auction) {
                throw new RuntimeException("Auction not found", AuctionErrorCode::AUCTION_NOT_FOUND);
            }

            if ($auction["seller"] !== $player) {
                throw new RuntimeException("You are not the seller of this auction", AuctionErrorCode::NOT_THE_SELLER);
            }


            if ($auction["status"] !== "EXPIRED" && $auction["status"] !== "FINISHED") {
                throw new RuntimeException("Auction is not finished yet", AuctionErrorCode::AUCTION_NOT_FINISHED);
            }

            if ($auction["highest_bidder"] !== null) {
                throw new RuntimeException("Auction had bids — item belongs to the winner", AuctionErrorCode::ITEM_BELONGS_TO_WINNER);
            }

            if ($auction["seller_claimed"]) {
                throw new RuntimeException("Item already reclaimed", AuctionErrorCode::ITEM_ALREADY_RECLAIMED);
            }

            $upd = $this->pdo->prepare("
                UPDATE auctions SET seller_claimed = 1, winner_claimed = 1 WHERE id = ?
            ");
            $upd->execute([$auctionId]);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->tryDeleteAuction($auctionId);

        return [
            "auction_id" => $auctionId,
            "item"       => json_decode($auction["item"], true) ?? $auction["item"],
            "seller"     => $player
        ];
    }



    public function cancelAuction($auctionId, $seller) {

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("
                SELECT * FROM auctions WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$auctionId]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auction) {
                throw new RuntimeException("Auction not found", AuctionErrorCode::AUCTION_NOT_FOUND);
            }

            if ($auction["seller"] !== $seller) {
                throw new RuntimeException("You are not the seller of this auction", AuctionErrorCode::NOT_THE_SELLER);
            }

            if ($auction["status"] !== "ACTIVE") {
                throw new RuntimeException("Only active auctions can be cancelled", AuctionErrorCode::AUCTION_NOT_ACTIVE);
            }

            if ($auction["highest_bidder"] !== null) {
                throw new RuntimeException("Cannot cancel an auction that already has bids", AuctionErrorCode::AUCTION_HAS_BIDS);
            }

            $upd = $this->pdo->prepare("
                UPDATE auctions SET status = 'CANCELLED' WHERE id = ?
            ");
            $upd->execute([$auctionId]);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    public function claimPendingFunds($player) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player name is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM pending_funds
                WHERE player = ? AND claimed = 0
                FOR UPDATE
            ");
            $stmt->execute([$player]);
            $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$funds) {
                $this->pdo->commit();
                return ["total" => 0, "entries" => []];
            }

            $ids   = array_column($funds, "id");
            $total = array_sum(array_column($funds, "amount"));

            $placeholders = implode(",", array_fill(0, count($ids), "?"));

            $upd = $this->pdo->prepare("
                UPDATE pending_funds SET claimed = 1
                WHERE id IN ({$placeholders})
            ");
            $upd->execute($ids);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            "total"   => $total,
            "entries" => $funds
        ];
    }

    public function getAuction($id) {

        if (empty($id)) {
            throw new InvalidArgumentException("Auction ID is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM auctions WHERE id = ?
        ");
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getActiveAuctions($from = 0, $to = 49, $category = null) {

        $from  = max(0, (int) $from);
        $to    = max($from, (int) $to);
        $limit = min(100, $to - $from + 1);

        $validCategories = $this->config["categories"] ?? ["WEAPONS","ARMOR","TOOLS","CONSUMABLES","MISC"];

        if ($category !== null) {
            $category = strtoupper($category);
            if (!in_array($category, $validCategories)) {
                throw new InvalidArgumentException("Invalid category. Valid: " . implode(", ", $validCategories), AuctionErrorCode::INVALID_CATEGORY);
            }

            $stmt = $this->pdo->prepare("
                SELECT * FROM auctions
                WHERE status = 'ACTIVE' AND end_time > ? AND category = ?
                ORDER BY end_time ASC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, time(), PDO::PARAM_INT);
            $stmt->bindValue(2, $category, PDO::PARAM_STR);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->bindValue(4, $from, PDO::PARAM_INT);
            $stmt->execute();

        } else {

            $stmt = $this->pdo->prepare("
                SELECT * FROM auctions
                WHERE status = 'ACTIVE' AND end_time > ?
                ORDER BY end_time ASC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, time(), PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $from, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlayerAuctions($player) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player name is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $stmt = $this->pdo->prepare("
            SELECT a.*,
                   (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count
            FROM auctions a
            WHERE a.seller = ?
            ORDER BY a.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$player]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAuctionBids($auctionId) {

        if (empty($auctionId)) {
            throw new InvalidArgumentException("Auction ID is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM bids
            WHERE auction_id = ?
            ORDER BY amount DESC
        ");
        $stmt->execute([$auctionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlayerBids($player) {

        if (empty($player)) {
            throw new InvalidArgumentException("Player name is required", AuctionErrorCode::MISSING_REQUIRED_FIELD);
        }

        $stmt = $this->pdo->prepare("
            SELECT a.*, b.amount as my_bid
            FROM bids b
            JOIN auctions a ON a.id = b.auction_id
            WHERE b.bidder = ?
            GROUP BY a.id
            ORDER BY b.amount DESC
            LIMIT 50
        ");
        $stmt->execute([$player]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
