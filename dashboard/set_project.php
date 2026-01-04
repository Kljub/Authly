<?php
// ============================================================================
// AUTHLY – Aktives Projekt setzen und ins Management weiterleiten
// ============================================================================

session_start();

// Nur eingeloggte User
if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

if (!isset($_POST['project_id']) || !is_numeric($_POST['project_id'])) {
    // Kein Projekt übergeben – zurück zum Dashboard
    header("Location: /dashboard/");
    exit();
}

$projectId = (int)$_POST['project_id'];

// Aktives Projekt in der Session speichern
$_SESSION['active_project'] = $projectId;

// Weiterleitung zum Management-Panel
header("Location: /dashboard/management.php");
exit();
