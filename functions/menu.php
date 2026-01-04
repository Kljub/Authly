<?php
// ============================================================================
// MENU.PHP – Menüsystem für Authly (NUR DB ROUTES, KEINE JSON FILES MEHR)
// ----------------------------------------------------------------------------
// LOGIK (FIX):
//  - Menü wird NICHT nur über active_project bestimmt,
//    sondern über "Kontext": bist du auf Projekt/Management-Seiten oder nicht?
//
// MENU_KEY:
//  - dashboard  -> nur Dashboard Bereich
//  - management -> nur Projekt/Management Bereich
//  - both       -> in beiden sichtbar
//
// STRUKTUR:
//  - Title (Section Header)
//      - Group (Dropdown, kann Groups/Items enthalten)
//          - Item (Link)
//      - Item (Link)
//
// FEATURES:
//  - Rollenprüfung (admin/user) + Vererbung (Title/Group -> Kind)
//  - Group-in-Group möglich (mehrstufige Dropdowns)
//  - Alpine.js Dropdown stabil
//  - Fallback: wenn keine Titles existieren -> group als Section
//
// PFAD: /functions/menu.php
// ============================================================================

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/../db/config.php'; // erwartet $pdo (PDO) ODER $conn (mysqli)

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
if (!function_exists('normalize_role')) {
    function normalize_role(string $role): string {
        $role = trim($role);
        if ($role === '1') return 'admin';
        if ($role === '2') return 'user';
        return $role !== '' ? $role : 'user';
    }
}

if (!function_exists('decode_json_array')) {
    function decode_json_array($json): ?array {
        if ($json === null) return null;
        $raw = is_string($json) ? $json : (string)$json;
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'null') return null;
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : null;
    }
}

if (!function_exists('user_has_role')) {
    function user_has_role(?array $roles, string $currentRole): bool {
        if ($roles === null) return true;

        $allowed = array_map('strval', $roles);
        if (in_array('*', $allowed, true)) return true;

        $cur = normalize_role($currentRole);

        $allowedNorm = array_map(function ($r) {
            $r = trim((string)$r);
            if ($r === '1') return 'admin';
            if ($r === '2') return 'user';
            return $r;
        }, $allowed);

        return in_array($cur, $allowedNorm, true);
    }
}

if (!function_exists('norm_kind')) {
    function norm_kind(string $kind): string {
        $k = strtolower(trim($kind));
        return in_array($k, ['title','group','item'], true) ? $k : 'item';
    }
}

if (!function_exists('get_icon_svg')) {
    function get_icon_svg(string $iconVar, int $size = 16): string {
        $paths = '';
        $bounds = ['', '_bound_1', '_bound_2', '_bound_3', '_bound_4', '_bound_5', '_bound_6'];

        foreach ($bounds as $suffix) {
            $var = $iconVar . $suffix;
            if (isset($GLOBALS[$var])) {
                $paths .= '<path d="' . $GLOBALS[$var] . '"/>';
            }
        }

        if ($paths === '') $paths = '<circle cx="8" cy="8" r="7"/>';

        return '<svg class="shrink-0 fill-current" width="'.$size.'" height="'.$size.'" viewBox="0 0 16 16">'
             . $paths .
             '</svg>';
    }
}

// -----------------------------------------------------------------------------
// Kontext-Erkennung (DER FIX)
// -----------------------------------------------------------------------------
if (!function_exists('authly_detect_menu_key')) {
    function authly_detect_menu_key(string $currentPath): string
    {
        $path = parse_url($currentPath, PHP_URL_PATH) ?: '/';
        $path = '/' . ltrim($path, '/');

        $hasProject =
            isset($_SESSION['active_project']) &&
            is_numeric($_SESSION['active_project']) &&
            (int)$_SESSION['active_project'] > 0;

        if (!$hasProject) {
            return 'dashboard';
        }

        // Projekt-/Management-Kontext erkennen (du kannst hier jederzeit erweitern)
        $projectIndicators = [
            '/management',
            '/managment', // legacy typo support
            '/project',
            '/overview',
            '/settings',
            '/users',
            '/tokens',
            '/variables',
            '/files',
            '/logs',
        ];

        foreach ($projectIndicators as $needle) {
            if (stripos($path, $needle) !== false) {
                return 'management';
            }
        }

        // Projekt aktiv, aber Seite ist "Dashboard-Kontext"
        return 'dashboard';
    }
}

// -----------------------------------------------------------------------------
// Renderer (Items + Dropdowns, inkl. Role-Vererbung)
// -----------------------------------------------------------------------------
if (!function_exists('render_menu_item')) {
    function render_menu_item(array $item, string $currentPath, string $role, ?array $inheritedRoles = null): string
    {
        // Rollen-Vererbung: wenn item keine roles hat -> inherited verwenden
        $effectiveRoles = array_key_exists('roles', $item) ? ($item['roles'] ?? null) : null;
        if ($effectiveRoles === null && $inheritedRoles !== null) $effectiveRoles = $inheritedRoles;

        if (!user_has_role($effectiveRoles, $role)) return '';

        $title = htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $href  = htmlspecialchars($item['href'] ?? '#', ENT_QUOTES, 'UTF-8');

        $badge = (isset($item['badge']) && $item['badge'] !== null && $item['badge'] !== '')
            ? '<span class="ml-auto text-[11px] px-2 py-0.5 rounded-md bg-violet-500/15 text-violet-500">'
                . htmlspecialchars((string)$item['badge'], ENT_QUOTES, 'UTF-8') .
              '</span>'
            : '';

        if (!empty($item['icon_var'])) {
            $iconHTML = get_icon_svg((string)$item['icon_var']);
        } elseif (!empty($item['icon'])) {
            $iconHTML = get_icon_svg('ico_' . (string)$item['icon']);
        } else {
            $iconHTML = get_icon_svg('ico_circle');
        }

        $cur = parse_url($currentPath, PHP_URL_PATH) ?: $currentPath;
        $cur = rtrim($cur, '/'); if ($cur === '') $cur = '/';

        $hrefDecoded = htmlspecialchars_decode($href, ENT_QUOTES);
        $hrefPath = parse_url($hrefDecoded, PHP_URL_PATH) ?: $hrefDecoded;
        $hrefPath = rtrim($hrefPath, '/'); if ($hrefPath === '') $hrefPath = '/';

        // ---------------------------------------------------------
        // Leaf-Link
        // ---------------------------------------------------------
        if (empty($item['children']) || !is_array($item['children'])) {
            $active = ($hrefPath === $cur);

            $classes = $active
                ? 'bg-linear-to-r from-violet-500/[0.12] to-violet-500/[0.04] text-violet-500'
                : 'text-gray-300 hover:text-violet-400';

            return '
            <li>
              <a href="'.$href.'" class="flex items-center pl-4 pr-3 py-2 rounded-lg '.$classes.' transition-all duration-150">
                '.$iconHTML.'
                <span class="ml-3 text-sm">'.$title.'</span>
                '.$badge.'
              </a>
            </li>';
        }

        // ---------------------------------------------------------
        // Dropdown
        // ---------------------------------------------------------
        $childHTML = '';
        $isOpen = false;

        foreach ($item['children'] as $child) {
            $rendered = render_menu_item($child, $currentPath, $role, $effectiveRoles);
            if ($rendered !== '') {
                $childHTML .= $rendered;

                if (isset($child['href'])) {
                    $childHrefPath = parse_url((string)$child['href'], PHP_URL_PATH) ?: (string)$child['href'];
                    $childHrefPath = rtrim($childHrefPath, '/'); if ($childHrefPath === '') $childHrefPath = '/';
                    if ($childHrefPath === $cur) $isOpen = true;
                }
            }
        }

        if (trim($childHTML) === '') return '';

        return '
        <li x-data="{ open: '.($isOpen ? 'true' : 'false').' }" @keydown.escape.window="open=false">
          <button
            @click.stop="open = !open"
            class="menu-toggle w-full flex items-center pl-4 pr-3 py-2 rounded-lg text-left text-gray-300 hover:text-violet-400 transition">
            '.$iconHTML.'
            <span class="ml-3 text-sm">'.$title.'</span>
            '.$badge.'
            <svg class="ml-auto w-3 h-3 transform transition-transform duration-200"
                 :class="open ? \'rotate-90 text-violet-400\' : \'text-gray-500\'"
                 viewBox="0 0 12 12" fill="currentColor">
              <path d="M5.9 11.4L.5 6l1.4-1.4 4 4 4-4L11.3 6z"/>
            </svg>
          </button>

          <div x-cloak x-show="open" @click.outside="open=false" x-transition class="overflow-hidden">
              <ul class="ml-5 mt-1 space-y-1 border-l border-gray-700/60 pl-2" @click.stop>
                '.$childHTML.'
              </ul>
          </div>
        </li>';
    }
}

if (!function_exists('render_menu_section')) {
    function render_menu_section(array $section, string $currentPath, string $role): string
    {
        // Title-Rollen wirken als Vererbung für Children
        $sectionRoles = $section['roles'] ?? null;
        if (!user_has_role($sectionRoles, $role)) return '';
        if (empty($section['items']) || !is_array($section['items'])) return '';

        $html = '';
        foreach ($section['items'] as $item) {
            $html .= render_menu_item($item, $currentPath, $role, $sectionRoles);
        }

        if (trim($html) === '') return '';

        $title = htmlspecialchars($section['title'] ?? '', ENT_QUOTES, 'UTF-8');

        return '
        <div class="mb-6">
          <h3 class="text-xs uppercase text-gray-500 pl-4 mb-2">'.$title.'</h3>
          <ul class="space-y-1">'.$html.'</ul>
        </div>';
    }
}

// -----------------------------------------------------------------------------
// DB FETCH (FIX: project-scope nur dann, wenn du es wirklich willst)
// -----------------------------------------------------------------------------
if (!function_exists('routes_fetch_all')) {
    function routes_fetch_all(string $menuKey, bool $hasActiveProject, int $projectId = 0): array
    {
        $keys = [$menuKey, 'both'];

        // hier wichtig:
        // - global immer rein
        // - project nur rein, wenn hasActiveProject true
        $pid = $hasActiveProject ? $projectId : 0;

        // ---- PDO ----
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            /** @var PDO $pdo */
            $pdo = $GLOBALS['pdo'];

            $sql = "
                SELECT *
                FROM routes
                WHERE is_active = 1
                  AND menu_key IN (?, ?)
                  AND (
                        scope = 'global'
                     OR (scope = 'project' AND project_id = ?)
                  )
                ORDER BY sort_order ASC, id ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$keys[0], $keys[1], $pid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        }

        // ---- mysqli ----
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            /** @var mysqli $conn */
            $conn = $GLOBALS['conn'];

            $sql = "
                SELECT *
                FROM routes
                WHERE is_active = 1
                  AND menu_key IN (?, ?)
                  AND (
                        scope = 'global'
                     OR (scope = 'project' AND project_id = ?)
                  )
                ORDER BY sort_order ASC, id ASC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $keys[0], $keys[1], $pid);
            $stmt->execute();
            $res = $stmt->get_result();

            $out = [];
            while ($row = $res->fetch_assoc()) $out[] = $row;
            $stmt->close();
            return $out;
        }

        return [];
    }
}

// -----------------------------------------------------------------------------
// Routes -> Sections Tree (Title/Group/Item) + Group-in-Group
// -----------------------------------------------------------------------------
if (!function_exists('routes_build_sections')) {
    function routes_build_sections(array $rows): array
    {
        if (!$rows) return [];

        // 1) Nodes map
        $byId = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;

            $parentId = null;
            if (array_key_exists('parent_id', $r) && $r['parent_id'] !== null && $r['parent_id'] !== '') {
                $parentId = (int)$r['parent_id'];
            }

            $kind = norm_kind((string)($r['kind'] ?? 'item'));

            $byId[$id] = [
                'id'        => $id,
                'kind'      => $kind,
                'parent_id' => $parentId,
                'sort'      => (int)($r['sort_order'] ?? 0),

                'title'     => (string)($r['title'] ?? ''),
                'href'      => ($r['href'] ?? null),
                'icon_var'  => ($r['icon_var'] ?? null),
                'icon'      => ($r['icon'] ?? null),
                'badge'     => ($r['badge'] ?? null),
                'roles'     => decode_json_array($r['roles_json'] ?? null),

                'children'  => [],
            ];
        }

        if (!$byId) return [];

        // 2) Link children (title+group können children haben)
        foreach ($byId as $id => $n) {
            $pid = $n['parent_id'];
            if ($pid === null) continue;
            if (!isset($byId[$pid])) continue;

            $parentKind = $byId[$pid]['kind'] ?? 'item';
            if ($parentKind === 'item') continue; // item darf keine children

            $byId[$pid]['children'][] = $id;
        }

        // 3) Sort children
        foreach ($byId as $id => $n) {
            if (!empty($byId[$id]['children'])) {
                usort($byId[$id]['children'], function($a,$b) use ($byId) {
                    $sa = (int)($byId[$a]['sort'] ?? 0);
                    $sb = (int)($byId[$b]['sort'] ?? 0);
                    if ($sa === $sb) return $a <=> $b;
                    return $sa <=> $sb;
                });
            }
        }

        // 4) Build render nodes (group + item)
        $buildNode = function(int $id) use (&$buildNode, &$byId): array {
            $n = $byId[$id];

            $node = [
                'title'    => $n['title'],
                'href'     => $n['href'],
                'icon_var' => $n['icon_var'],
                'icon'     => $n['icon'],
                'badge'    => $n['badge'],
                'roles'    => $n['roles'],
            ];

            // group kann children haben (group-in-group + items)
            if (($n['kind'] ?? '') === 'group' && !empty($n['children'])) {
                $children = [];
                foreach ($n['children'] as $cid) {
                    // title darf NICHT als child gerendert werden
                    if (($byId[$cid]['kind'] ?? '') === 'title') continue;
                    $children[] = $buildNode((int)$cid);
                }
                if (!empty($children)) $node['children'] = $children;
            }

            // item kann children NICHT haben (ignorieren)
            return $node;
        };

        // 5) Title sections
        $titleIds = [];
        foreach ($byId as $id => $n) {
            if (($n['kind'] ?? '') === 'title' && $n['parent_id'] === null) {
                $titleIds[] = $id;
            }
        }

        usort($titleIds, function($a,$b) use ($byId) {
            $sa = (int)($byId[$a]['sort'] ?? 0);
            $sb = (int)($byId[$b]['sort'] ?? 0);
            if ($sa === $sb) return $a <=> $b;
            return $sa <=> $sb;
        });

        $sections = [];

        if (!empty($titleIds)) {
            foreach ($titleIds as $tid) {
                $t = $byId[$tid];

                $items = [];
                foreach (($t['children'] ?? []) as $cid) {
                    $ck = $byId[$cid]['kind'] ?? 'item';
                    if ($ck === 'title') continue;

                    if ($ck === 'group') {
                        $items[] = $buildNode((int)$cid);
                    } else {
                        // item
                        $items[] = [
                            'title'    => $byId[$cid]['title'],
                            'href'     => $byId[$cid]['href'],
                            'icon_var' => $byId[$cid]['icon_var'],
                            'icon'     => $byId[$cid]['icon'],
                            'badge'    => $byId[$cid]['badge'],
                            'roles'    => $byId[$cid]['roles'],
                        ];
                    }
                }

                if (!$items) continue;

                $sections[] = [
                    'title' => ($t['title'] !== '' ? $t['title'] : 'TITLE'),
                    'roles' => $t['roles'] ?? null,
                    'items' => $items,
                ];
            }

            if (!empty($sections)) return $sections;
        }

        // 6) Fallback: group als Section (root groups)
        $rootGroups = [];
        foreach ($byId as $id => $n) {
            if (($n['kind'] ?? '') !== 'group') continue;
            if ($n['parent_id'] !== null) continue;
            $rootGroups[] = $id;
        }

        usort($rootGroups, function($a,$b) use ($byId) {
            $sa = (int)($byId[$a]['sort'] ?? 0);
            $sb = (int)($byId[$b]['sort'] ?? 0);
            if ($sa === $sb) return $a <=> $b;
            return $sa <=> $sb;
        });

        $fallback = [];
        foreach ($rootGroups as $gid) {
            $g = $byId[$gid];

            $items = [];
            foreach (($g['children'] ?? []) as $cid) {
                $items[] = $buildNode((int)$cid);
            }

            if (!$items) continue;

            $fallback[] = [
                'title' => ($g['title'] !== '' ? $g['title'] : 'SECTION'),
                'roles' => $g['roles'] ?? null,
                'items' => $items,
            ];
        }

        return $fallback;
    }
}

// -----------------------------------------------------------------------------
// Dynamisches Menü (DB ONLY)
// -----------------------------------------------------------------------------
if (!function_exists('render_menu_dynamic')) {
    function render_menu_dynamic(string $currentPath, string $role): string
    {
        $role = normalize_role($role);

        $hasActiveProject =
            isset($_SESSION['active_project']) &&
            is_numeric($_SESSION['active_project']) &&
            (int)$_SESSION['active_project'] > 0;

        $projectId = $hasActiveProject ? (int)$_SESSION['active_project'] : 0;

        // ✅ FIX: Kontext-basiert statt nur active_project
        $menuKey = authly_detect_menu_key($currentPath);

        $rows = routes_fetch_all($menuKey, $hasActiveProject, $projectId);
        if (!$rows) {
            return '<p class="px-4 text-gray-500 text-sm">⚠️ Kein Menü verfügbar (DB leer?).</p>';
        }

        $sections = routes_build_sections($rows);
        if (!$sections) {
            return '<p class="px-4 text-gray-500 text-sm">⚠️ Kein Menü verfügbar (keine Titles/Items).</p>';
        }

        $html = '';
        foreach ($sections as $section) {
            $html .= render_menu_section($section, $currentPath, $role);
        }

        return $html ?: '<p class="px-4 text-gray-500 text-sm">⚠️ Kein Menü verfügbar.</p>';
    }
}
?>
