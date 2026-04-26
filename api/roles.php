<?php
// ==============================================
// NexTalk — Role Helpers
// ==============================================

const NEX_ROLE_MEMBER = "member";
const NEX_ROLE_MODERATOR = "moderator";
const NEX_ROLE_ADMIN = "admin";

function nex_valid_roles() {
    return [NEX_ROLE_MEMBER, NEX_ROLE_MODERATOR, NEX_ROLE_ADMIN];
}

function nex_normalize_role($role) {
    $normalized = strtolower(trim((string)$role));
    return in_array($normalized, nex_valid_roles(), true) ? $normalized : NEX_ROLE_MEMBER;
}

function nex_role_rank($role) {
    $rankMap = [
        NEX_ROLE_MEMBER => 1,
        NEX_ROLE_MODERATOR => 2,
        NEX_ROLE_ADMIN => 3,
    ];
    $normalized = nex_normalize_role($role);
    return $rankMap[$normalized];
}

function nex_has_min_role($actualRole, $requiredRole) {
    return nex_role_rank($actualRole) >= nex_role_rank($requiredRole);
}

function nex_role_label($role) {
    $normalized = nex_normalize_role($role);
    $labels = [
        NEX_ROLE_MEMBER => "Member",
        NEX_ROLE_MODERATOR => "Moderator",
        NEX_ROLE_ADMIN => "Admin",
    ];
    return $labels[$normalized];
}

function nex_role_permissions($role) {
    $normalized = nex_normalize_role($role);
    return [
        "can_manage_roles" => nex_has_min_role($normalized, NEX_ROLE_ADMIN),
        "can_moderate_messages" => nex_has_min_role($normalized, NEX_ROLE_MODERATOR),
        "can_manage_room_settings" => nex_has_min_role($normalized, NEX_ROLE_MODERATOR),
    ];
}
