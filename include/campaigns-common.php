<?php
// Shared by campaigns.php, campaign.php and addCampaign.php. Expects include/fishnet-layout.php to be loaded
// first (h(), fish_str(), fishnet_open()).

// Render a short notice inside the layout with the given HTTP status and stop (like users_stop_page()).
function campaign_stop_page($status, $title, $message) {
    http_response_code($status);
    fishnet_open($title, 'campaigns', ['donor.css', 'campaigns.css']);
    ?>
      <section class="page-head"><div><p class="crumbs">Campaigns</p><h1><?= h($title) ?></h1></div></section>
      <div class="notice" role="status"><?= h($message) ?></div>
      <p><a href="dashboard.php" class="btn btn-outline small">&lsaquo; Back</a></p>
    <?php
    fishnet_close();
    exit;
}

// A positive integer id from $_GET, or 0. Arrays and junk give 0 (same rule as the donation and hours pages).
function campaign_id_param(array $source) {
    $v = fish_str($source, 'id');
    return ctype_digit($v) ? (int) $v : 0;
}