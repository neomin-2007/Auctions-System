<?php

class AuctionRepository {

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function createTables() {

        $this->pdo->exec("
        CREATE TABLE IF NOT EXISTS auctions (
            id VARCHAR(64) PRIMARY KEY,
            seller VARCHAR(32) NOT NULL,
            item LONGTEXT NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'MISC',
            starting_price BIGINT NOT NULL,
            highest_bid BIGINT DEFAULT 0,
            highest_bidder VARCHAR(32) DEFAULT NULL,
            end_time BIGINT NOT NULL,
            status VARCHAR(16) NOT NULL,
            seller_claimed TINYINT(1) NOT NULL DEFAULT 0,
            winner_claimed TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,

            INDEX idx_status (status),
            INDEX idx_end_time (end_time),
            INDEX idx_seller (seller),
            INDEX idx_category (category),
            INDEX idx_highest_bidder (highest_bidder)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->pdo->exec("
        CREATE TABLE IF NOT EXISTS bids (
            id INT AUTO_INCREMENT PRIMARY KEY,
            auction_id VARCHAR(64) NOT NULL,
            bidder VARCHAR(32) NOT NULL,
            amount BIGINT NOT NULL,
            time BIGINT NOT NULL,

            INDEX idx_auction_id (auction_id),
            INDEX idx_bidder (bidder),

            FOREIGN KEY (auction_id)
            REFERENCES auctions(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_funds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player VARCHAR(32) NOT NULL,
            amount BIGINT NOT NULL,
            auction_id VARCHAR(64) NOT NULL,
            reason VARCHAR(32) NOT NULL DEFAULT 'REFUND',
            claimed TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,

            INDEX idx_player (player),
            INDEX idx_claimed (claimed),
            INDEX idx_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->pdo->exec("
        CREATE TABLE IF NOT EXISTS auction_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player VARCHAR(32) NOT NULL,
            action VARCHAR(32) NOT NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            auction_id VARCHAR(64) NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'MISC',
            created_at BIGINT NOT NULL,

            INDEX idx_player (player),
            INDEX idx_action (action),
            INDEX idx_category (category),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player VARCHAR(32) NOT NULL,
            item LONGTEXT NOT NULL,
            reason VARCHAR(32) NOT NULL DEFAULT 'DRAFT_RETURN',
            claimed TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,

            INDEX idx_player (player),
            INDEX idx_claimed (claimed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->pdo->exec("
        CREATE TABLE IF NOT EXISTS draft_auctions (
            id VARCHAR(64) PRIMARY KEY,
            player VARCHAR(32) NOT NULL,
            item LONGTEXT NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'MISC',
            starting_price BIGINT NOT NULL DEFAULT 1,
            duration INT NOT NULL DEFAULT 3600,
            created_at BIGINT NOT NULL,

            UNIQUE INDEX idx_player (player),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->pdo->exec("
        CREATE TABLE IF NOT EXISTS rate_limit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(32) NOT NULL,
            created_at BIGINT NOT NULL,

            INDEX idx_action_time (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
