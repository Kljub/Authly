<?php
// Placeholder roles file for future role checks

// Beispiel (später erweiterbar)
function user_has_role(?array $roles, string $currentRole): bool {
    if (empty($roles)) return true;
    return in_array($currentRole, $roles) || in_array('*', $roles);
}
?>