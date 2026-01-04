<?php
// ============================================================================
// PFAD: /functions/admin_functions/routes_repo.php
// ============================================================================
function routes_repo_fetch_all(PDO $pdo, array $filter): array
{
    $mk = $filter['menu_key'] ?? 'both';
    $sc = $filter['scope'] ?? 'global';
    $pidInt = $filter['project_id'] ?? null;

    $where = [];
    $args  = [];

    if ($mk === 'both') $where[] = "menu_key IN ('dashboard','management','both')";
    elseif ($mk === 'dashboard') $where[] = "menu_key IN ('dashboard','both')";
    elseif ($mk === 'management') $where[] = "menu_key IN ('management','both')";
    else $where[] = "menu_key IN ('dashboard','management','both')";

    $where[] = "scope = :sc";
    $args[':sc'] = $sc;

    if ($sc === 'project') {
        if ($pidInt === null) $where[] = "1=0";
        else { $where[] = "project_id = :pid"; $args[':pid'] = $pidInt; }
    } else {
        $where[] = "project_id IS NULL";
    }

    $sql = "SELECT * FROM routes WHERE " . implode(" AND ", $where) . " ORDER BY parent_id ASC, sort_order ASC, id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function routes_repo_fetch_id_kind_editable(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT id, kind, editable FROM routes WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function routes_repo_insert(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO routes
            (menu_key, scope, project_id, kind, parent_id, title, href, icon_var, badge, roles_json, editable, sort_order, is_active)
        VALUES
            (:menu_key, :scope, :project_id, :kind, :parent_id, :title, :href, :icon_var, :badge, :roles_json, :editable, :sort_order, :is_active)
    ");
    $stmt->execute($data);
}

function routes_repo_update(PDO $pdo, int $id, array $data): void
{
    $data[':id'] = $id;
    $stmt = $pdo->prepare("
        UPDATE routes SET
            menu_key   = :menu_key,
            scope      = :scope,
            project_id = :project_id,
            kind       = :kind,
            parent_id  = :parent_id,
            title      = :title,
            href       = :href,
            icon_var   = :icon_var,
            badge      = :badge,
            roles_json = :roles_json,
            editable   = :editable,
            sort_order = :sort_order,
            is_active  = :is_active
        WHERE id = :id
    ");
    $stmt->execute($data);
}

function routes_repo_toggle_active(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("UPDATE routes SET is_active = IF(is_active=1,0,1) WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

function routes_repo_delete(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("DELETE FROM routes WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

function routes_repo_update_parent_sort(PDO $pdo, int $id, $parentId, int $sortOrder): void
{
    $st = $pdo->prepare("
        UPDATE routes SET
            parent_id  = :parent_id,
            sort_order = :sort_order
        WHERE id = :id
    ");
    $st->execute([
        ':id' => $id,
        ':parent_id' => ($parentId === null ? null : (int)$parentId),
        ':sort_order' => $sortOrder
    ]);
}

function routes_repo_fetch_id_parent_for_filter(PDO $pdo, array $filter): array
{
    $mk = $filter['menu_key'] ?? 'both';
    $sc = $filter['scope'] ?? 'global';
    $pidInt = $filter['project_id'] ?? null;

    $where = [];
    $args  = [];

    if ($mk === 'both') $where[] = "menu_key IN ('dashboard','management','both')";
    elseif ($mk === 'dashboard') $where[] = "menu_key IN ('dashboard','both')";
    elseif ($mk === 'management') $where[] = "menu_key IN ('management','both')";
    else $where[] = "menu_key IN ('dashboard','management','both')";

    $where[] = "scope = :sc";
    $args[':sc'] = $sc;

    if ($sc === 'project') {
        if ($pidInt === null) $where[] = "1=0";
        else { $where[] = "project_id = :pid"; $args[':pid'] = $pidInt; }
    } else {
        $where[] = "project_id IS NULL";
    }

    $stAll = $pdo->prepare("SELECT id,parent_id FROM routes WHERE " . implode(" AND ", $where));
    $stAll->execute($args);
    return $stAll->fetchAll(PDO::FETCH_ASSOC);
}
