<?php
session_start();

require_once __DIR__ . '/../functions/dbfunctions.php';
$ownerId = $_SESSION['user_id'];




echo getProjectCountByOwner($ownerId);

?>