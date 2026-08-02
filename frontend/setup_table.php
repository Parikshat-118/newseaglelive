<?php
require_once 'm:/news/frontend/includes/bootstrap.php';
$db = ne_db();
if (!$db) die('No db');
$sql = "CREATE TABLE IF NOT EXISTS article_live_viewers (
    article_id INT NOT NULL,
    viewer_id CHAR(32) NOT NULL,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(article_id, viewer_id),
    INDEX idx_article_seen(article_id, last_seen)
);";
$db->exec($sql);
echo "Table created successfully!";
