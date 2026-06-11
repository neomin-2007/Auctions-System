<?php

return [
    "database" => [
        "host"     => "127.0.0.1",
        "port"     => 3306,
        "database" => "auctions",
        "username" => "root",
        "password" => ""
    ],

    "auction" => [
        "min_bid_increment"      => 1,
        "min_duration"           => 60,
        "max_duration"           => 604800,
        "max_per_player"         => 10,
        "rate_limit_per_minute"  => 15,
        "categories"             => ["WEAPONS", "ARMOR", "TOOLS", "CONSUMABLES", "MISC"]
    ],

    "auth" => [
        "api_key" => ""
    ]
];
