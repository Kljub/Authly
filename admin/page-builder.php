<?php
// ============================================================================
// AUTHLY – ADMIN: PAGE BUILDER (Drag & Drop + Properties + ✅ LIVE PREVIEW)
// ----------------------------------------------------------------------------
// - Blocks hinzufügen / sortieren / editieren
// - ✅ Live Preview per iframe (builder-preview.php) + postMessage
// - Speichert JSON in cms_pages.builder_json
// ----------------------------------------------------------------------------
// PFAD: /admin/page-builder.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';

require_once __DIR__ . '/../functions/admin_functions/admin_auth.php';
require_once __DIR__ . '/../functions/admin_functions/admin_db.php';

require_once __DIR__ . '/../functions/cms_pages.php';

session_start();

$auth = require_admin();
$userRole = (string)$auth['role_id'];

$pdo = admin_get_pdo();

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function authly_render_sidebar_menu(string $userRole): string {
    if (function_exists('render_menu_dynamic')) {
        return (string)render_menu_dynamic($_SERVER['REQUEST_URI'], (string)$userRole);
    }
    return '<p class="px-4 text-gray-500 text-sm">⚠️ render_menu_dynamic() fehlt.</p>';
}

// -------------------------
// JSON API: load/save/menu_html
// -------------------------
$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($ct, 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('Invalid JSON.');

        $action = (string)($data['action'] ?? '');
        $pageId = (int)($data['page_id'] ?? 0);
        if ($pageId <= 0) throw new RuntimeException('Invalid page_id.');

        $page = cms_get_page($pdo, $pageId);
        if (!$page) throw new RuntimeException('Page not found.');

        if ($action === 'menu_html') {
            echo json_encode(['ok'=>true,'menu_html'=>authly_render_sidebar_menu($userRole)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'load') {
            echo json_encode([
                'ok' => true,
                'page' => [
                    'id' => (int)$page['id'],
                    'title' => (string)$page['title'],
                    'slug' => (string)$page['slug'],
                    'status' => (string)$page['status'],
                    'render_mode' => (string)($page['render_mode'] ?? 'builder'),
                    'builder_json' => (string)($page['builder_json'] ?? ''),
                ]
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'save') {
            $renderMode = (string)($data['render_mode'] ?? 'builder');
            if (!in_array($renderMode, ['builder','html'], true)) $renderMode = 'builder';

            $builder = $data['builder'] ?? null;
            if (!is_array($builder)) throw new RuntimeException('builder must be array');
            if (!isset($builder['blocks']) || !is_array($builder['blocks'])) {
                throw new RuntimeException('builder.blocks missing');
            }

            $json = json_encode($builder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || $json === '') throw new RuntimeException('JSON encode failed');

            $stmt = $pdo->prepare("UPDATE cms_pages SET builder_json = ?, render_mode = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$json, $renderMode, $pageId]);

            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }

        throw new RuntimeException('Invalid action.');

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// -------------------------
// UI Load
// -------------------------
$pageId = (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) ? (int)$_GET['id'] : 0;
if ($pageId <= 0) {
    header("Location: /admin/pages.php?err=" . urlencode("❌ page id fehlt (z.B. /admin/page-builder.php?id=1)"));
    exit;
}
$page = cms_get_page($pdo, $pageId);
if (!$page) {
    header("Location: /admin/pages.php?err=" . urlencode("❌ Page nicht gefunden."));
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Admin – Page Builder</title>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.5/cdn.min.js" defer></script>
  <script src="https://cdn.tailwindcss.com"></script>

  <link href="/gui/css/style.css" rel="stylesheet">
  <link href="/gui/css/sidebar.css" rel="stylesheet">
  <link href="/gui/css/project-settings.css?v=1" rel="stylesheet">

  <style>
    [x-cloak]{display:none!important;}
    .pb-drop-hint { outline: 2px dashed rgba(139,92,246,.55); outline-offset: 6px; }
    .pb-selected { outline: 2px solid rgba(139,92,246,.8); outline-offset: 4px; border-radius: 12px; }
    .pb-mini-btn { font-size:12px; padding:.35rem .6rem; border-radius:.6rem; }
    .pb-card { border: 1px solid rgba(55,65,81,.6); background: rgba(17,24,39,.35); }
  </style>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

<!-- SIDEBAR -->
<aside class="sidebar fixed left-0 top-0 h-screen w-64 flex flex-col overflow-y-auto
             bg-gray-900/90 backdrop-blur-lg border border-gray-800/50 rounded-3xl m-4 shadow-xl">

  <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800/50">
    <div class="flex items-center gap-2">
      <svg class="w-6 h-6 text-violet-400" viewBox="0 0 24 24"><path d="M12 2L20 20H4L12 2Z"/></svg>
      <span class="text-gray-100 font-semibold text-lg tracking-wide">Authly</span>
    </div>
  </div>

  <nav id="authly-sidebar-menu" class="flex-1 px-2 mt-4">
    <?= authly_render_sidebar_menu((string)$userRole); ?>
  </nav>

  <div class="mt-auto px-4 py-3 border-t border-gray-800/50">
    <div class="flex items-center justify-between text-gray-400 text-xs">
      <span>© <?= date('Y') ?> Authly</span>
      <a href="/logout.php" class="hover:text-violet-400 transition">Logout</a>
    </div>
  </div>
</aside>

<!-- MAIN -->
<main class="flex-1 ml-72 p-10 space-y-6"
      x-data="pageBuilder(<?= (int)$pageId ?>)"
      x-init="init()">

  <header class="flex items-start justify-between gap-6 flex-wrap">
    <div>
      <h1 class="text-3xl font-bold text-gray-100">Page Builder</h1>
      <p class="text-gray-400 mt-2">
        Bearbeite: <span class="font-mono">#<?= (int)$page['id'] ?></span> —
        <span class="text-gray-200 font-semibold"><?= h((string)$page['title']) ?></span>
        <span class="text-gray-500">(@<?= h((string)$page['slug']) ?>)</span>
      </p>
    </div>

    <div class="flex gap-3 flex-wrap">
      <a href="/admin/pages.php?edit=<?= (int)$pageId ?>"
         class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2 rounded-lg shadow text-sm">
        Zurück
      </a>

      <button @click="save()"
              class="bg-violet-600 hover:bg-violet-700 text-white px-5 py-2 rounded-lg shadow text-sm">
        Save
      </button>

      <a :href="'/?page=' + encodeURIComponent(meta.slug)"
         target="_blank"
         class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2 rounded-lg shadow text-sm">
        Preview
      </a>
    </div>
  </header>

  <!-- Flash -->
  <template x-if="flash.msg">
    <div class="p-4 rounded-xl"
         :class="flash.type==='ok' ? 'bg-green-800/35 border border-green-700 text-green-200' : 'bg-red-800/35 border border-red-700 text-red-200'">
      <span x-text="flash.msg"></span>
    </div>
  </template>

  <!-- Top controls -->
  <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
      <div>
        <label class="text-gray-300 text-sm">Render Mode</label>
        <select x-model="meta.render_mode"
                class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
          <option value="builder">builder</option>
          <option value="html">html (fallback content)</option>
        </select>
        <p class="text-xs text-gray-500 mt-2">
          <span class="font-mono">builder</span> rendert JSON Blocks. <span class="font-mono">html</span> nutzt cms_pages.content.
        </p>
      </div>

      <div>
        <label class="text-gray-300 text-sm">Status</label>
        <div class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
          <span class="text-gray-400 text-sm">Status:</span>
          <span class="font-mono" x-text="meta.status"></span>
        </div>
      </div>

      <div class="flex gap-3 md:justify-end">
        <button class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-3 rounded-lg shadow text-sm"
                @click="refreshSidebar()">
          Sidebar Refresh
        </button>
      </div>
    </div>
  </section>

  <!-- Builder layout -->
  <section class="grid grid-cols-1 xl:grid-cols-12 gap-6">

    <!-- Palette -->
    <div class="xl:col-span-3 bg-gray-800 rounded-2xl p-6 shadow-xl">
      <h2 class="text-xl font-semibold text-gray-100 mb-4">Blöcke</h2>

      <div class="grid grid-cols-2 gap-3">
        <template x-for="b in palette" :key="b.type">
          <div class="pb-card rounded-xl p-3 cursor-grab active:cursor-grabbing"
               draggable="true"
               @dragstart="onPaletteDragStart($event, b.type)">
            <div class="text-gray-100 font-semibold text-sm" x-text="b.label"></div>
            <div class="text-xs text-gray-500 mt-1" x-text="b.hint"></div>
          </div>
        </template>
      </div>

      <div class="mt-6 text-xs text-gray-500">
        Drag in die Mitte (Canvas). Klick auf Element → Properties rechts.
      </div>
    </div>

    <!-- Canvas -->
    <div class="xl:col-span-6 bg-gray-800 rounded-2xl p-6 shadow-xl">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold text-gray-100">Canvas</h2>

        <div class="flex gap-2">
          <button class="pb-mini-btn bg-gray-700 hover:bg-gray-600 text-white"
                  @click="clearSelection()">Deselect</button>
          <button class="pb-mini-btn bg-gray-700 hover:bg-gray-600 text-white"
                  @click="addBlock('section')">+ Section</button>
        </div>
      </div>

      <div class="rounded-2xl border border-gray-700/60 bg-gray-900/30 p-4 min-h-[520px]"
           :class="dragOverRoot ? 'pb-drop-hint' : ''"
           @dragover.prevent="dragOverRoot=true"
           @dragleave="dragOverRoot=false"
           @drop.prevent="onDropToRoot($event)">

        <template x-if="builder.blocks.length===0">
          <div class="text-gray-500 italic p-6 text-center">
            Keine Blöcke. Zieh links etwas rein.
          </div>
        </template>

        <template x-for="(blk, idx) in builder.blocks" :key="blk.id">
          <div class="mb-3">
            <div class="rounded-2xl border border-gray-700/60 bg-gray-950/40 p-4"
                 :class="selectedId===blk.id ? 'pb-selected' : ''"
                 @click.stop="select(blk.id)">

              <div class="flex items-center justify-between gap-3 mb-3">
                <div class="flex items-center gap-2">
                  <span class="text-xs px-2 py-0.5 rounded-md bg-violet-500/15 text-violet-300 uppercase" x-text="blk.type"></span>
                  <span class="text-gray-100 font-semibold" x-text="blk.props?.label || humanTitle(blk)"></span>
                  <span class="text-gray-500 text-xs font-mono" x-text="blk.id"></span>
                </div>

                <div class="flex gap-2">
                  <button class="pb-mini-btn bg-gray-700 hover:bg-gray-600 text-white"
                          @click.stop="move(idx, -1)">↑</button>
                  <button class="pb-mini-btn bg-gray-700 hover:bg-gray-600 text-white"
                          @click.stop="move(idx, +1)">↓</button>
                  <button class="pb-mini-btn bg-red-600/80 hover:bg-red-600 text-white"
                          @click.stop="remove(idx)">Delete</button>
                </div>
              </div>

              <!-- Simple preview -->
              <div class="text-sm text-gray-200" x-html="previewHtml(blk)"></div>

            </div>
          </div>
        </template>

      </div>
    </div>

    <!-- ✅ Inspector: Properties + Live Preview Tabs -->
    <div class="xl:col-span-3 bg-gray-800 rounded-2xl p-6 shadow-xl" x-data="{ tab: 'props' }">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold text-gray-100">Inspector</h2>
        <div class="flex gap-2">
          <button class="pb-mini-btn"
                  :class="tab==='props' ? 'bg-violet-600 text-white' : 'bg-gray-700 text-white hover:bg-gray-600'"
                  @click="tab='props'">Props</button>
          <button class="pb-mini-btn"
                  :class="tab==='preview' ? 'bg-violet-600 text-white' : 'bg-gray-700 text-white hover:bg-gray-600'"
                  @click="tab='preview'; $nextTick(()=> $dispatch('authly-preview-ping')); ">Live</button>
        </div>
      </div>

      <!-- PROPS -->
      <div x-show="tab==='props'" x-cloak>
        <template x-if="!selectedBlock">
          <div class="text-gray-500 italic">Wähle ein Element im Canvas aus.</div>
        </template>

        <template x-if="selectedBlock">
          <div class="space-y-4">

            <div class="pb-card rounded-xl p-4">
              <div class="text-xs text-gray-500">Block</div>
              <div class="text-gray-100 font-semibold">
                <span x-text="selectedBlock.type"></span>
              </div>
            </div>

            <!-- Common -->
            <div class="pb-card rounded-xl p-4 space-y-3">
              <div class="text-sm font-semibold text-gray-100">Common</div>

              <div>
                <label class="text-gray-300 text-xs">Label (nur intern)</label>
                <input class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                       x-model="selectedBlock.props.label">
              </div>

              <div>
                <label class="text-gray-300 text-xs">Padding</label>
                <select class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                        x-model="selectedBlock.props.pad">
                  <option value="sm">sm</option>
                  <option value="md">md</option>
                  <option value="lg">lg</option>
                </select>
              </div>

              <div>
                <label class="text-gray-300 text-xs">Align</label>
                <select class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                        x-model="selectedBlock.props.align">
                  <option value="left">left</option>
                  <option value="center">center</option>
                  <option value="right">right</option>
                </select>
              </div>
            </div>

            <!-- Type specific -->
            <template x-if="selectedBlock.type==='heading'">
              <div class="pb-card rounded-xl p-4 space-y-3">
                <div class="text-sm font-semibold text-gray-100">Heading</div>
                <div>
                  <label class="text-gray-300 text-xs">Text</label>
                  <input class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                         x-model="selectedBlock.props.text">
                </div>
                <div>
                  <label class="text-gray-300 text-xs">Level</label>
                  <select class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                          x-model="selectedBlock.props.level">
                    <option value="1">H1</option>
                    <option value="2">H2</option>
                    <option value="3">H3</option>
                  </select>
                </div>
              </div>
            </template>

            <template x-if="selectedBlock.type==='text'">
              <div class="pb-card rounded-xl p-4 space-y-3">
                <div class="text-sm font-semibold text-gray-100">Text</div>
                <div>
                  <label class="text-gray-300 text-xs">Content</label>
                  <textarea rows="6"
                    class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                    x-model="selectedBlock.props.text"></textarea>
                  <p class="text-xs text-gray-500 mt-2">Plain text (safe). HTML wird nicht gerendert.</p>
                </div>
              </div>
            </template>

            <template x-if="selectedBlock.type==='button'">
              <div class="pb-card rounded-xl p-4 space-y-3">
                <div class="text-sm font-semibold text-gray-100">Button</div>
                <div>
                  <label class="text-gray-300 text-xs">Text</label>
                  <input class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                         x-model="selectedBlock.props.text">
                </div>
                <div>
                  <label class="text-gray-300 text-xs">Link (href)</label>
                  <input class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                         x-model="selectedBlock.props.href" placeholder="/login/ oder https://...">
                </div>
                <div>
                  <label class="text-gray-300 text-xs">Variant</label>
                  <select class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                          x-model="selectedBlock.props.variant">
                    <option value="primary">primary</option>
                    <option value="secondary">secondary</option>
                  </select>
                </div>
              </div>
            </template>

            <template x-if="selectedBlock.type==='divider'">
              <div class="pb-card rounded-xl p-4 space-y-3">
                <div class="text-sm font-semibold text-gray-100">Divider</div>
                <div>
                  <label class="text-gray-300 text-xs">Style</label>
                  <select class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1"
                          x-model="selectedBlock.props.style">
                    <option value="line">line</option>
                    <option value="spacer">spacer</option>
                  </select>
                </div>
              </div>
            </template>

          </div>
        </template>
      </div>

      <!-- LIVE PREVIEW -->
      <div x-show="tab==='preview'" x-cloak class="space-y-3">
        <div class="text-sm text-gray-400">
          Live Rendering (ohne Speichern). Änderungen werden sofort übernommen.
        </div>

        <div class="rounded-2xl border border-gray-700/60 overflow-hidden bg-gray-900/30">
          <iframe id="authly-preview-frame"
                  src="/admin/builder-preview.php?id=<?= (int)$pageId ?>"
                  class="w-full h-[680px] bg-transparent"
                  loading="lazy"></iframe>
        </div>

        <div class="flex gap-2">
          <button class="pb-mini-btn bg-gray-700 hover:bg-gray-600 text-white"
                  @click="$dispatch('authly-preview-ping')">Refresh Preview</button>
        </div>
      </div>
    </div>

  </section>

<script>
function pageBuilder(pageId) {
  const uid = () => 'b_' + Math.random().toString(16).slice(2) + Date.now().toString(16);

  const defaultProps = (type) => {
    const base = { label:'', pad:'md', align:'left' };
    if (type === 'section') return { ...base, label:'Section' };
    if (type === 'heading') return { ...base, label:'Heading', text:'Überschrift', level:'2' };
    if (type === 'text')    return { ...base, label:'Text', text:'Dein Text…' };
    if (type === 'button')  return { ...base, label:'Button', text:'Zum Login', href:'/login/', variant:'primary' };
    if (type === 'divider') return { ...base, label:'Divider', style:'line' };
    return base;
  };

  return {
    meta: { id: pageId, title:'', slug:'', status:'', render_mode:'builder' },
    flash: { msg:'', type:'ok' },

    palette: [
      { type:'section', label:'Section', hint:'Container/Card' },
      { type:'heading', label:'Heading', hint:'H1/H2/H3' },
      { type:'text',    label:'Text',    hint:'Plain text' },
      { type:'button',  label:'Button',  hint:'Link button' },
      { type:'divider', label:'Divider', hint:'Line/Spacer' },
    ],

    builder: { version: 1, blocks: [] },

    selectedId: null,
    dragType: null,
    dragOverRoot: false,

    previewTimer: null,

    get selectedBlock() {
      return this.builder.blocks.find(b => b.id === this.selectedId) || null;
    },

    humanTitle(blk){
      if (!blk) return '';
      if (blk.type==='heading') return (blk.props?.text || 'Heading');
      if (blk.type==='text') return 'Text';
      if (blk.type==='button') return (blk.props?.text || 'Button');
      if (blk.type==='divider') return 'Divider';
      if (blk.type==='section') return 'Section';
      return blk.type;
    },

    previewHtml(blk){
      const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[m]));
      const align = blk.props?.align==='center' ? 'text-center' : (blk.props?.align==='right' ? 'text-right' : 'text-left');

      if (blk.type==='heading') {
        const lvl = String(blk.props?.level || '2');
        const cls = lvl==='1' ? 'text-2xl font-bold' : (lvl==='3' ? 'text-lg font-semibold' : 'text-xl font-semibold');
        return `<div class="${align} ${cls}">${esc(blk.props?.text)}</div>`;
      }
      if (blk.type==='text') {
        return `<div class="${align} text-gray-300 whitespace-pre-wrap">${esc(blk.props?.text)}</div>`;
      }
      if (blk.type==='button') {
        const v = blk.props?.variant || 'primary';
        const cls = v==='secondary'
          ? 'inline-flex items-center px-4 py-2 rounded-xl bg-gray-700 hover:bg-gray-600 text-white font-semibold'
          : 'inline-flex items-center px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 text-white font-semibold shadow-lg shadow-violet-600/20';
        return `<div class="${align}"><span class="${cls}">${esc(blk.props?.text)}</span><span class="text-xs text-gray-500 ml-2">${esc(blk.props?.href)}</span></div>`;
      }
      if (blk.type==='divider') {
        const st = blk.props?.style || 'line';
        return st==='spacer'
          ? `<div class="h-6"></div>`
          : `<div class="h-px bg-gray-700/70"></div>`;
      }
      if (blk.type==='section') {
        return `<div class="rounded-2xl border border-gray-700/60 bg-gray-900/30 p-4 ${align}">Section Container</div>`;
      }
      return `<div class="text-gray-400">Unknown block</div>`;
    },

    sendPreview(){
      const frame = document.getElementById('authly-preview-frame');
      if (!frame || !frame.contentWindow) return;

      clearTimeout(this.previewTimer);
      this.previewTimer = setTimeout(() => {
        try{
          frame.contentWindow.postMessage({
            type: 'AUTHLY_BUILDER_UPDATE',
            builder: this.builder
          }, '*');
        }catch(e){}
      }, 120);
    },

    onPaletteDragStart(ev, type){
      this.dragType = type;
      ev.dataTransfer.effectAllowed = 'copy';
      ev.dataTransfer.setData('text/plain', type);
    },

    onDropToRoot(ev){
      this.dragOverRoot = false;
      const type = ev.dataTransfer.getData('text/plain') || this.dragType;
      if (!type) return;
      this.addBlock(type);
    },

    addBlock(type){
      const blk = { id: uid(), type, props: defaultProps(type) };
      this.builder.blocks.push(blk);
      this.selectedId = blk.id;
      this.sendPreview();
    },

    select(id){ this.selectedId = id; },
    clearSelection(){ this.selectedId = null; },

    move(idx, dir){
      const n = idx + dir;
      if (n < 0 || n >= this.builder.blocks.length) return;
      const tmp = this.builder.blocks[idx];
      this.builder.blocks[idx] = this.builder.blocks[n];
      this.builder.blocks[n] = tmp;
      this.sendPreview();
    },

    remove(idx){
      const blk = this.builder.blocks[idx];
      if (!confirm('Block löschen?')) return;
      this.builder.blocks.splice(idx,1);
      if (this.selectedId === blk.id) this.selectedId = null;
      this.sendPreview();
    },

    async load(){
      const res = await fetch('/admin/page-builder.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'load', page_id: pageId })
      });
      const j = await res.json().catch(() => null);
      if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Load failed');

      this.meta.id = j.page.id;
      this.meta.title = j.page.title;
      this.meta.slug = j.page.slug;
      this.meta.status = j.page.status;
      this.meta.render_mode = j.page.render_mode || 'builder';

      const raw = j.page.builder_json || '';
      if (raw.trim() !== '') {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object' && Array.isArray(parsed.blocks)) {
          this.builder = parsed;
        }
      }

      this.sendPreview();
    },

    async save(){
      try {
        const res = await fetch('/admin/page-builder.php', {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({
            action:'save',
            page_id: pageId,
            render_mode: this.meta.render_mode,
            builder: this.builder
          })
        });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Save failed');

        this.flash = { msg:'✅ Gespeichert.', type:'ok' };
        setTimeout(() => this.flash = {msg:'', type:'ok'}, 1800);
      } catch (e) {
        this.flash = { msg:'❌ ' + (e.message || 'Save failed'), type:'err' };
      }
    },

    async refreshSidebar(){
      const sidebarMenuEl = document.getElementById('authly-sidebar-menu');
      try{
        const res = await fetch('/admin/page-builder.php', {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'menu_html', page_id: pageId })
        });
        const j = await res.json().catch(() => null);
        if (res.ok && j && j.ok && typeof j.menu_html === 'string') {
          sidebarMenuEl.innerHTML = j.menu_html;
        }
      }catch(_){}
    },

    init(){
      // Listener für "Refresh Preview" Button
      this.$el.addEventListener('authly-preview-ping', () => this.sendPreview());

      // Load initial state
      this.load().then(() => {
        // Deep watch: wenn Props geändert werden -> Preview live updaten
        this.$watch('builder', () => this.sendPreview(), { deep: true });
      }).catch(e => {
        this.flash = { msg:'❌ ' + (e.message || 'Init failed'), type:'err' };
      });
    }
  }
}
</script>

</main>
</body>
</html>
