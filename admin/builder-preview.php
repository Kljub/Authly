<?php
// ============================================================================
// AUTHLY – ADMIN: BUILDER LIVE PREVIEW (iframe target) – FIXED (Handshake)
// ----------------------------------------------------------------------------
// PFAD: /admin/builder-preview.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/admin_functions/admin_auth.php';
require_once __DIR__ . '/../functions/admin_functions/admin_db.php';

session_start();
require_admin();

$pageId = (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) ? (int)$_GET['id'] : 0;
if ($pageId <= 0) { http_response_code(400); echo "Missing id"; exit; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Live Preview</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="/gui/css/style.css" rel="stylesheet">
  <style> body{background:transparent;} </style>
</head>
<body class="bg-gray-900 text-gray-200 font-inter">
  <div id="root" class="max-w-5xl mx-auto px-6 py-8">
    <div class="text-gray-500 text-sm italic">Waiting for builder data…</div>
  </div>

<script>
(function(){
  const PAGE_ID = <?= (int)$pageId ?>;
  const root = document.getElementById('root');

  // ✅ Handshake: iframe -> parent (ready)
  function notifyReady(){
    try{
      window.parent.postMessage({ type:'AUTHLY_PREVIEW_READY', page_id: PAGE_ID }, '*');
    }catch(e){}
  }

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"
  }[m]));

  const safeHref = (href) => {
    href = String(href ?? '').trim();
    if (!href) return '#';
    if (/^https?:\/\//i.test(href)) return href;
    if (href.startsWith('/')) return href;
    return '#';
  };

  const padCls = (pad) => pad === 'lg' ? 'p-6' : (pad === 'sm' ? 'p-3' : 'p-4');
  const alignCls = (align) => align === 'center' ? 'text-center' : (align === 'right' ? 'text-right' : 'text-left');

  function renderBlock(b){
    const type = String(b?.type ?? '');
    const p = (b && typeof b === 'object' && b.props && typeof b.props === 'object') ? b.props : {};
    const pad = padCls(String(p.pad ?? 'md'));
    const align = alignCls(String(p.align ?? 'left'));

    if (type === 'section') {
      return `
        <section class="rounded-3xl border border-gray-800/60 bg-gray-950/50 backdrop-blur-lg ${pad} shadow-xl ${align}">
          <div class="text-gray-200">Section</div>
        </section>
      `;
    }

    if (type === 'heading') {
      const lvl = String(p.level ?? '2');
      const cls = (lvl === '1') ? 'text-3xl font-bold'
                : (lvl === '3') ? 'text-lg font-semibold'
                : 'text-2xl font-semibold';
      return `<div class="${align} ${cls} text-gray-100">${esc(p.text ?? '')}</div>`;
    }

    if (type === 'text') {
      return `<div class="${align} text-gray-300 whitespace-pre-wrap">${esc(p.text ?? '')}</div>`;
    }

    if (type === 'button') {
      const variant = String(p.variant ?? 'primary');
      const cls = (variant === 'secondary')
        ? 'inline-flex items-center px-4 py-2 rounded-2xl bg-gray-700 hover:bg-gray-600 transition text-white font-semibold'
        : 'inline-flex items-center px-4 py-2 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20';
      return `<div class="${align}"><a class="${cls}" href="${esc(safeHref(p.href))}">${esc(p.text ?? '')}</a></div>`;
    }

    if (type === 'divider') {
      const st = String(p.style ?? 'line');
      return st === 'spacer'
        ? `<div class="h-6"></div>`
        : `<div class="h-px bg-gray-800/70"></div>`;
    }

    return '';
  }

  function render(builder){
    const blocks = Array.isArray(builder?.blocks) ? builder.blocks : [];
    if (!blocks.length) {
      root.innerHTML = `<div class="text-gray-500 text-sm italic">No blocks…</div>`;
      return;
    }
    root.innerHTML = `<article class="space-y-4">${blocks.map(b => `<div>${renderBlock(b)}</div>`).join('')}</article>`;
  }

  // ✅ Listen for updates from parent
  window.addEventListener('message', (ev) => {
    const data = ev?.data;
    if (!data || data.type !== 'AUTHLY_BUILDER_UPDATE') return;
    try { render(data.builder || {}); }
    catch(e){ root.innerHTML = `<div class="text-red-300">Preview render failed.</div>`; }
  });

  // ✅ send ready after load
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    notifyReady();
  } else {
    document.addEventListener('DOMContentLoaded', notifyReady);
  }
})();
</script>
</body>
</html>
