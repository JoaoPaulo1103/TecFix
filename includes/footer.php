    </div>
  </div>
</div>
<script>
(function(){
  const body=document.body;
  const button=document.querySelector('.mobile-menu-button');
  const overlay=document.querySelector('.mobile-menu-overlay');
  const sidebar=document.querySelector('.sidebar');
  if(!button || !sidebar) return;

  function isMobile(){ return window.matchMedia('(max-width: 900px)').matches; }
  function closeMenu(){
    body.classList.remove('mobile-menu-open');
    button.setAttribute('aria-expanded','false');
  }
  button.addEventListener('click',function(){
    if(!isMobile()) return;
    const open=body.classList.toggle('mobile-menu-open');
    button.setAttribute('aria-expanded',String(open));
  });
  if(overlay) overlay.addEventListener('click',closeMenu);
  sidebar.querySelectorAll('a').forEach(function(link){
    link.addEventListener('click',function(){ if(isMobile()) closeMenu(); });
  });
  window.addEventListener('resize',function(){ if(!isMobile()) closeMenu(); });
})();
</script>
<script>
(function(){
  if(!document.body.classList.contains('cliente-theme')) return;
  document.addEventListener('click', function(e){
    const el=e.target.closest('button, .btn, .side-nav a, .cart-button, .portal-product-card, .back-store, .qty-control button');
    if(!el || el.disabled || el.closest('dialog')) return;
    el.classList.remove('client-click-bounce');
    void el.offsetWidth;
    el.classList.add('client-click-bounce');
    el.classList.add('client-clickable');
    const rect=el.getBoundingClientRect();
    const size=Math.max(rect.width,rect.height)*.45;
    const ripple=document.createElement('span');
    ripple.className='client-ripple';
    ripple.style.width=size+'px'; ripple.style.height=size+'px';
    ripple.style.left=(e.clientX-rect.left-size/2)+'px';
    ripple.style.top=(e.clientY-rect.top-size/2)+'px';
    el.appendChild(ripple);
    ripple.addEventListener('animationend',()=>ripple.remove(),{once:true});
  });
})();
</script>
</body>
</html>
