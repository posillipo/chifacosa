(function () {
  function init() {
    if (document.getElementById('adminlte-profile-back-to-top')) return;
    var b=document.createElement('button');
    b.id='adminlte-profile-back-to-top';b.type='button';b.setAttribute('aria-label','Torna in cima');b.innerHTML='<i class="bi bi-arrow-up"></i>';document.body.appendChild(b);
    function u(){b.style.display=window.scrollY>400?'flex':'none'}
    addEventListener('scroll',u,{passive:true});b.onclick=function(){scrollTo({top:0,behavior:'smooth'})};u();
  }
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();
