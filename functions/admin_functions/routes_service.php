<?php
// ============================================================================
// PFAD: /functions/admin_functions/routes_service.php
// ============================================================================
function routes_build_filter_from_request(array $getOrPost): array
{
    $menu_key = strtolower(trim((string)($getOrPost['menu_key'] ?? 'both')));
    if (!routes_is_valid_enum($menu_key, ['dashboard','management','both'])) $menu_key = 'both';

    $scope = strtolower(trim((string)($getOrPost['scope'] ?? 'global')));
    if (!routes_is_valid_enum($scope, ['global','project'])) $scope = 'global';

    $project_id_raw = (string)($getOrPost['project_id'] ?? '');
    $project_id_int = ($project_id_raw !== '' && ctype_digit($project_id_raw)) ? (int)$project_id_raw : null;

    return [
        'menu_key'   => $menu_key,
        'scope'      => $scope,
        'project_id' => ($scope === 'project' ? $project_id_int : null),
    ];
}

function routes_build_children_map(array $idParentRows): array
{
    $childrenMap = [];
    foreach ($idParentRows as $r) {
        $id = (int)$r['id'];
        $p  = ($r['parent_id'] !== null) ? (int)$r['parent_id'] : 0;
        if ($p > 0) $childrenMap[$p][] = $id;
    }
    return $childrenMap;
}

function routes_service_create(PDO $pdo, array $in): void
{
    $menu_key   = strtolower(trim((string)($in['menu_key'] ?? 'dashboard')));
    $scope      = strtolower(trim((string)($in['scope'] ?? 'global')));
    $project_id = trim((string)($in['project_id'] ?? ''));

    $kind       = routes_norm_kind((string)($in['kind'] ?? 'item'));
    $parent_id  = trim((string)($in['parent_id'] ?? ''));

    $title      = trim((string)($in['title'] ?? ''));
    $href       = trim((string)($in['href'] ?? ''));
    $icon_var   = trim((string)($in['icon_var'] ?? ''));
    $badge      = trim((string)($in['badge'] ?? ''));
    $roles_csv  = (string)($in['roles_csv'] ?? '');
    $editable   = isset($in['editable']) ? 1 : 0;
    $sort_order = (int)($in['sort_order'] ?? 0);
    $is_active  = isset($in['is_active']) ? 1 : 0;

    if (!routes_is_valid_enum($menu_key, ['dashboard','management','both'])) $menu_key = 'dashboard';
    if (!routes_is_valid_enum($scope, ['global','project'])) $scope = 'global';

    $pid = null;
    if ($scope === 'project') {
        if ($project_id === '' || !ctype_digit($project_id)) {
            throw new RuntimeException("Bei scope=project muss project_id gesetzt sein.");
        }
        $pid = (int)$project_id;
    }

    $pid_parent = null;
    if ($parent_id !== '' && ctype_digit($parent_id)) $pid_parent = (int)$parent_id;

    if ($title === '') throw new RuntimeException("Title darf nicht leer sein.");

    if ($kind === 'title') {
        $pid_parent = null;
        $href = '';
    } elseif ($kind === 'group') {
        $href = '';
    }

    $roles_json = routes_roles_csv_to_json($roles_csv);

    routes_repo_insert($pdo, [
        ':menu_key'   => $menu_key,
        ':scope'      => $scope,
        ':project_id' => $pid,
        ':kind'       => $kind,
        ':parent_id'  => $pid_parent,
        ':title'      => $title,
        ':href'       => ($href !== '' ? $href : null),
        ':icon_var'   => ($icon_var !== '' ? $icon_var : null),
        ':badge'      => ($badge !== '' ? $badge : null),
        ':roles_json' => $roles_json,
        ':editable'   => $editable,
        ':sort_order' => $sort_order,
        ':is_active'  => $is_active,
    ]);
}

function routes_service_update(PDO $pdo, int $id, array $in): void
{
    if ($id <= 0) throw new RuntimeException("Ungültige ID.");

    $chk = routes_repo_fetch_id_kind_editable($pdo, $id);
    if (!$chk) throw new RuntimeException("Route nicht gefunden.");
    if ((int)$chk['editable'] === 0) throw new RuntimeException("Diese Route ist locked (editable=0).");

    $menu_key   = strtolower(trim((string)($in['menu_key'] ?? 'dashboard')));
    $scope      = strtolower(trim((string)($in['scope'] ?? 'global')));
    $project_id = trim((string)($in['project_id'] ?? ''));

    $kind       = routes_norm_kind((string)($in['kind'] ?? ($chk['kind'] ?? 'item')));
    $parent_id  = trim((string)($in['parent_id'] ?? ''));

    $title      = trim((string)($in['title'] ?? ''));
    $href       = trim((string)($in['href'] ?? ''));
    $icon_var   = trim((string)($in['icon_var'] ?? ''));
    $badge      = trim((string)($in['badge'] ?? ''));
    $roles_csv  = (string)($in['roles_csv'] ?? '');
    $editable   = isset($in['editable']) ? 1 : 0;
    $sort_order = (int)($in['sort_order'] ?? 0);
    $is_active  = isset($in['is_active']) ? 1 : 0;

    if (!routes_is_valid_enum($menu_key, ['dashboard','management','both'])) $menu_key = 'dashboard';
    if (!routes_is_valid_enum($scope, ['global','project'])) $scope = 'global';

    $pid = null;
    if ($scope === 'project') {
        if ($project_id === '' || !ctype_digit($project_id)) {
            throw new RuntimeException("Bei scope=project muss project_id gesetzt sein.");
        }
        $pid = (int)$project_id;
    }

    $pid_parent = null;
    if ($parent_id !== '' && ctype_digit($parent_id)) $pid_parent = (int)$parent_id;

    if ($title === '') throw new RuntimeException("Title darf nicht leer sein.");

    if ($kind === 'title') {
        $pid_parent = null;
        $href = '';
    } elseif ($kind === 'group') {
        $href = '';
    }

    $roles_json = routes_roles_csv_to_json($roles_csv);

    routes_repo_update($pdo, $id, [
        ':menu_key'   => $menu_key,
        ':scope'      => $scope,
        ':project_id' => $pid,
        ':kind'       => $kind,
        ':parent_id'  => $pid_parent,
        ':title'      => $title,
        ':href'       => ($href !== '' ? $href : null),
        ':icon_var'   => ($icon_var !== '' ? $icon_var : null),
        ':badge'      => ($badge !== '' ? $badge : null),
        ':roles_json' => $roles_json,
        ':editable'   => $editable,
        ':sort_order' => $sort_order,
        ':is_active'  => $is_active,
    ]);
}

function routes_service_toggle_active(PDO $pdo, int $id): void
{
    if ($id <= 0) throw new RuntimeException("Ungültige ID.");
    routes_repo_toggle_active($pdo, $id);
}

function routes_service_delete(PDO $pdo, int $id): void
{
    if ($id <= 0) throw new RuntimeException("Ungültige ID.");

    $chk = routes_repo_fetch_id_kind_editable($pdo, $id);
    if (!$chk) throw new RuntimeException("Route nicht gefunden.");
    if ((int)$chk['editable'] === 0) throw new RuntimeException("Diese Route ist locked (editable=0).");

    routes_repo_delete($pdo, $id);
}

function routes_service_reorder(PDO $pdo, array $filter, array $updates): void
{
    if (!is_array($updates)) throw new RuntimeException('Missing updates array.');

    $all = routes_repo_fetch_id_parent_for_filter($pdo, $filter);
    $childrenMap = routes_build_children_map($all);

    $pdo->beginTransaction();

    try {
        foreach ($updates as $u) {
            if (!is_array($u)) continue;

            $id = (int)($u['id'] ?? 0);
            if ($id <= 0) continue;

            $row = routes_repo_fetch_id_kind_editable($pdo, $id);
            if (!$row) continue;
            if ((int)$row['editable'] === 0) continue;

            $kind = routes_norm_kind((string)($row['kind'] ?? 'item'));

            $parentId = $u['parent_id'] ?? null;
            $parentId = ($parentId === null || $parentId === '' || $parentId === 0) ? null : (int)$parentId;

            if ($kind === 'title') $parentId = null;

            if ($parentId !== null) {
                if ($parentId === $id) {
                    throw new RuntimeException("Ungültiger Parent: Route #$id kann nicht sich selbst parenten.");
                }
                if (routes_is_descendant($childrenMap, $id, $parentId)) {
                    throw new RuntimeException("Ungültiger Parent: Route #$parentId ist Kind/Enkel von #$id (Cycle).");
                }
            }

            $sortOrder = (int)($u['sort_order'] ?? 0);
            routes_repo_update_parent_sort($pdo, $id, $parentId, $sortOrder);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
