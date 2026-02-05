
(function(){
  function qs(sel){ return document.querySelector(sel); }
  function update(){
    var bar = qs('[data-wprl-progress-bar]');
    var pct = qs('[data-wprl-progress-pct]');
    if(!bar || !pct) return;
    fetch(ajaxurl + '?action=wprl_wizard_scan_state', {credentials:'same-origin'})
      .then(r=>r.json()).then(function(d){
        if(!d || !d.success) return;
        var s=d.data;
        var total = parseInt(s.total||0,10);
        var prog = parseInt(s.progress||0,10);
        var status = s.status||'idle';
        var percent = total>0 ? Math.round((prog/total)*100) : (status==='complete'?100:0);
        bar.style.width = percent + '%';
        pct.textContent = 'PROGRESS: ' + percent + '%';
      }).catch(function(){});
  }
  setInterval(update, 2000);
  document.addEventListener('DOMContentLoaded', update);
})();
