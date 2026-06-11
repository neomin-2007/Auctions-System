<?php

class AuctionWorker {

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function tick() {

        $stmt = $this->pdo->prepare("
            SELECT * FROM auctions
            WHERE status = 'ACTIVE' AND end_time <= ?
        ");

        $stmt->execute([time()]);
        $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($auctions as $auction) {
            $this->finishAuction($auction);
        }

        $this->cleanOldFunds();
        $this->cleanAbandonedDrafts();
    }


    /**
     * Drafts com mais de 30 minutos sem confirmação são considerados abandonados.
     * O item é enfileirado em pending_items para devolução ao player.
     */
    private function cleanAbandonedDrafts() {

        $cutoff = time() - (30 * 60);

        $stmt = $this->pdo->prepare("SELECT * FROM draft_auctions WHERE created_at <= ?");
        $stmt->execute([$cutoff]);
        $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($drafts as $draft) {
            $this->pdo->beginTransaction();
            try {
                $del = $this->pdo->prepare("DELETE FROM draft_auctions WHERE id = ? AND created_at <= ?");
                $del->execute([$draft["id"], $cutoff]);
                if ($del->rowCount() === 0) { $this->pdo->rollBack(); continue; }
                $refund = $this->pdo->prepare("INSERT INTO pending_items (player, item, reason, claimed, created_at) VALUES (?, ?, 'DRAFT_RETURN', 0, ?)");
                $refund->execute([$draft["player"], $draft["item"], time()]);
                $this->pdo->commit();
            } catch (Exception $e) { $this->pdo->rollBack(); }
        }

        $oneDayAgo = time() - (24 * 60 * 60);
        $this->pdo->prepare("DELETE FROM pending_items WHERE claimed = 1 AND created_at <= ?")->execute([$oneDayAgo]);
    }

    private function cleanOldFunds() {

        $oneDayAgo    = time() - (1  * 24 * 60 * 60);
        $fifteenDaysAgo = time() - (15 * 24 * 60 * 60);

        // SALE entries (any state) — delete after 1 day
        $stmt = $this->pdo->prepare("
            DELETE FROM pending_funds
            WHERE reason = 'SALE' AND created_at <= ?
        ");
        $stmt->execute([$oneDayAgo]);

        // REFUND already claimed — delete after 1 day
        $stmt = $this->pdo->prepare("
            DELETE FROM pending_funds
            WHERE reason = 'REFUND' AND claimed = 1 AND created_at <= ?
        ");
        $stmt->execute([$oneDayAgo]);

        // REFUND unclaimed — delete after 15 days (player lost their window)
        $stmt = $this->pdo->prepare("
            DELETE FROM pending_funds
            WHERE reason = 'REFUND' AND claimed = 0 AND created_at <= ?
        ");
        $stmt->execute([$fifteenDaysAgo]);
    }

    private function finishAuction($auction) {

        $id = $auction["id"];

        $this->pdo->beginTransaction();

        try {

            // Optimistic lock: only process if still ACTIVE
            $lock = $this->pdo->prepare("
                UPDATE auctions
                SET status = 'PROCESSING'
                WHERE id = ? AND status = 'ACTIVE'
            ");
            $lock->execute([$id]);

            if ($lock->rowCount() === 0) {
                $this->pdo->rollBack();
                return; // Another worker already picked it up
            }

            // BUG FIX: fetch only the HIGHEST bid per bidder so we only refund
            // the amount that was actually locked (their last/highest bid).
            // Previously, every single bid row was refunded — a player who
            // overbid themselves multiple times would receive multiple refunds.
            $bids = $this->pdo->prepare("
                SELECT bidder, MAX(amount) as amount
                FROM bids
                WHERE auction_id = ?
                GROUP BY bidder
            ");
            $bids->execute([$id]);
            $topBids = $bids->fetchAll(PDO::FETCH_ASSOC);

            foreach ($topBids as $bid) {

                // Losing bidders get a full refund
                if ($bid["bidder"] !== $auction["highest_bidder"]) {

                    $refund = $this->pdo->prepare("
                        INSERT INTO pending_funds
                        (player, amount, auction_id, reason, claimed, created_at)
                        VALUES (?, ?, ?, 'REFUND', 0, ?)
                    ");
                    $refund->execute([
                        $bid["bidder"],
                        $bid["amount"],
                        $id,
                        time()
                    ]);
                }
            }

            // Seller receives coins only if someone actually bid
            if ($auction["highest_bidder"] !== null) {

                $sellerPayment = $this->pdo->prepare("
                    INSERT INTO pending_funds
                    (player, amount, auction_id, reason, claimed, created_at)
                    VALUES (?, ?, ?, 'SALE', 0, ?)
                ");
                $sellerPayment->execute([
                    $auction["seller"],
                    $auction["highest_bid"],
                    $id,
                    time()
                ]);
            }

            // Mark as FINISHED (had a winner) or EXPIRED (no bids)
            $finalStatus = $auction["highest_bidder"] !== null ? "FINISHED" : "EXPIRED";
            $update = $this->pdo->prepare("
                UPDATE auctions SET status = ? WHERE id = ?
            ");
            $update->execute([$finalStatus, $id]);

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
