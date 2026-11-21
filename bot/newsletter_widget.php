<?php
/*
 * Tribune Breizh · bandeau d'inscription à la newsletter.
 * Un seul bloc, injecté par bot/build.php (page d'accueil) et bot/seo.php (pages club/sport/événement),
 * pour qu'il apparaisse sur tout le site sans jamais être écrit dans src/index.html (réécrit chaque nuit).
 * Le formulaire envoie en JS vers /abonnement.php (racine, seul endroit accessible : bot/ n'est pas servi).
 * Pour retirer le bandeau : vider tb_newsletter_block() ci-dessous (renvoyer '').
 */

function tb_newsletter_block(): string {
  return <<<'HTML'
<div id="tb-news" class="tb-news" hidden>
  <div class="tb-news-in">
    <p class="tb-news-txt">📬 <b>Chaque jour, le sport breton dans votre boîte mail.</b> Résultats, programme, un email, zéro spam.</p>
    <form id="tb-news-form" class="tb-news-form">
      <label for="tb-news-email" class="tb-news-sr">Adresse email</label>
      <input id="tb-news-email" name="email" type="email" required placeholder="vous@exemple.fr" autocomplete="email" maxlength="190">
      <input type="text" name="site_web" class="tb-news-hp" tabindex="-1" autocomplete="off">
      <button type="submit">S'inscrire</button>
    </form>
    <button type="button" class="tb-news-x" aria-label="Fermer">×</button>
  </div>
</div>
<style>
.tb-news{position:sticky;top:0;z-index:999;background:#121417;color:#fff;font:14px -apple-system,BlinkMacSystemFont,sans-serif;border-bottom:2px solid #fff}
.tb-news-in{max-width:960px;margin:0 auto;display:flex;align-items:center;gap:12px;padding:8px 36px 8px 12px;position:relative;flex-wrap:wrap}
.tb-news-txt{margin:0;flex:1 1 220px;line-height:1.35}
.tb-news-txt b{font-weight:700}
.tb-news-form{display:flex;gap:6px;flex:0 0 auto}
.tb-news-form input[type=email]{font:inherit;padding:6px 8px;border:1px solid #fff;border-radius:4px;background:#fff;color:#000;width:180px}
.tb-news-form button{font:inherit;font-weight:700;padding:6px 12px;border:1px solid #fff;border-radius:4px;background:#fff;color:#121417;cursor:pointer}
.tb-news-form button:active{background:#ddd}
.tb-news-hp{position:absolute!important;left:-9999px;width:1px;height:1px;opacity:0}
.tb-news-x{position:absolute;top:4px;right:6px;background:none;border:none;color:#fff;font-size:20px;line-height:1;cursor:pointer;padding:4px 8px}
.tb-news-sr{position:absolute;left:-9999px}
.tb-news-ok{margin:0;padding:10px 12px;text-align:center;font-weight:700}
@media (max-width:560px){.tb-news-in{padding:8px 32px 8px 10px}.tb-news-form{flex:1 1 100%}.tb-news-form input[type=email]{flex:1;width:auto}}
</style>
<script>
(function(){
  var el=document.getElementById('tb-news');
  if(!el)return;
  var DISMISS_DAYS=14;
  try{
    if(localStorage.getItem('tb-news-done')==='1')return;
    var d=localStorage.getItem('tb-news-dismiss');
    if(d&&(Date.now()-parseInt(d,10))<DISMISS_DAYS*86400000)return;
  }catch(e){}
  el.hidden=false;
  var form=document.getElementById('tb-news-form');
  var closeBtn=el.querySelector('.tb-news-x');
  closeBtn.addEventListener('click',function(){
    el.hidden=true;
    try{localStorage.setItem('tb-news-dismiss',String(Date.now()));}catch(e){}
  });
  form.addEventListener('submit',function(ev){
    ev.preventDefault();
    var btn=form.querySelector('button');
    var email=form.email.value.trim();
    if(!email)return;
    btn.disabled=true;btn.textContent='...';
    var body=new URLSearchParams();
    body.set('email',email);
    body.set('site_web',form.site_web.value);
    fetch('/abonnement.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j&&j.ok){
          el.querySelector('.tb-news-in').innerHTML='<p class="tb-news-ok">Merci, c’est fait — vous recevrez le prochain email. ✓</p>';
          try{localStorage.setItem('tb-news-done','1');}catch(e){}
          setTimeout(function(){el.hidden=true;},4000);
        }else{
          btn.disabled=false;btn.textContent='S’inscrire';
          alert((j&&j.error)||'Adresse invalide, réessayez.');
        }
      })
      .catch(function(){
        btn.disabled=false;btn.textContent='S’inscrire';
        alert('Erreur réseau, réessayez.');
      });
  });
})();
</script>
HTML;
}
