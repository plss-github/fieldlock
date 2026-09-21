<?php

/**
 * Create, update or drop locks from the configuration screen.
 *
 * On GLPI 11 CSRF is enforced upstream by the kernel listener, which reads the
 * `X-Glpi-Csrf-Token` header on XHR requests. GLPI 10 leaves it to the script,
 * so the check happens here and a fresh token is handed back: GLPI 10 burns the
 * token on use, and the picker saves on every click.
 */

// GLPI 10 executes plugin scripts directly and expects them to bootstrap the
// core themselves. GLPI 11 boots its kernel before requiring the file, where
// including this again would be an error.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);
PluginFieldlockCompat::checkAjaxCsrf();

header('Content-Type: application/json; charset=UTF-8');

$action   = (string) ($_POST['action'] ?? '');
$itemtype = (string) ($_POST['itemtype'] ?? '');
$field    = (string) ($_POST['field'] ?? '');
$mode     = (string) ($_POST['mode'] ?? PluginFieldlockLock::MODE_READONLY);
$profiles = array_values(array_filter(array_map('intval', (array) ($_POST['profiles'] ?? []))));

if (!PluginFieldlockLock::isManagedItemtype($itemtype)) {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported itemtype']);
    return;
}

if ($profiles === []) {
    http_response_code(400);
    echo json_encode(['error' => 'no profile selected']);
    return;
}

switch ($action) {
    case 'lock':
        $ok = PluginFieldlockLock::setLock($profiles, $itemtype, $field, $mode);
        break;
    case 'unlock':
        $ok = PluginFieldlockLock::removeLock($profiles, $itemtype, $field);
        break;
    case 'clear':
        $ok = PluginFieldlockLock::clearItemtype($profiles, $itemtype);
        break;
    default:
        $ok = false;
}

if (!$ok) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid request']);
    return;
}

echo json_encode([
    'success'    => true,
    'locks'      => PluginFieldlockLock::getLocksForProfilesAllItemtypes($profiles),
    'csrf_token' => Session::getNewCSRFToken(),
]);
