<?php
/** Shared AdminLTE page frame. Define $pageTitle, $activeNavPage and $sess before including. */
$pageTitle = $pageTitle ?? 'MPS & Item Analysis System';
$activeNavPage = $activeNavPage ?? 'dashboard';
$layoutVariant = $layoutVariant ?? 'app';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> – MPS System</title>
<link rel="icon" href="<?= BASE_URL ?>assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css">
<link rel="stylesheet" href="<?= asset_url('styles.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/css/adminlte.min.css">
<?php if (!empty($layoutHeadAssets)) echo $layoutHeadAssets; ?>
</head>
<body class="<?= h($layoutBodyClass ?? 'layout-fixed sidebar-expand-lg bg-body-tertiary') ?>" data-bs-theme="light">
<?php if ($layoutVariant === 'auth'): ?>
<main class="mps-auth-master">
<?php else: ?>
<div class="app-wrapper mps-master-layout">
<?php require __DIR__ . '/adminlte_navigation.php'; ?>
<main class="app-main">
    <div class="app-content">
        <div class="container-fluid">
<?php endif; ?>
