<?php
$state = array('status'=>'idle','total'=>0,'progress'=>0);
if ( class_exists('WPRankLab_Batch_Scan') ) {
  $bs = WPRankLab_Batch_Scan::get_instance();
  if ( $bs && method_exists($bs,'get_state') ) {
    $state = $bs->get_state();
  }
}
$total = (int) ($state['total'] ?? 0);
$prog  = (int) ($state['progress'] ?? 0);
$status = (string) ($state['status'] ?? 'idle');
$percent = $total > 0 ? (int) round(($prog/$total)*100) : ($status==='complete' ? 100 : 0);
?>
<div class="wprl-wiz-titleRow">
  <div class="wprl-wiz-num">3</div>
  <div>
    <h2 class="wprl-wiz-h1">Run First Scan</h2>
    <div class="wprl-wiz-desc">Click on the button below to start scanning all of your pages. This might take some time. You can skip and run this later. For advanced insights and more <a href="#" onclick="return false;">upgrade to PRO</a>.</div>
  </div>
</div>

<div class="wprl-progressbar" aria-label="scan progress">
  <div data-wprl-progress-bar style="width: <?php echo (int)$percent; ?>%"></div>
</div>
<div class="wprl-progress-text" data-wprl-progress-pct>PROGRESS: <?php echo (int)$percent; ?>%</div>
