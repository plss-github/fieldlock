<?php

/**
 * Every lock the given profiles hold, grouped by itemtype.
 *
 * Not scoped to one itemtype on purpose: a single preview can host several
 * (an existing Ticket also renders the ITILFollowup, TicketTask, ITILSolution
 * and TicketValidation forms in its timeline), and the table is small enough
 * that one round-trip beats re-fetching whenever a new form turns up.
 */

// GLPI 10 executes plugin scripts directly and expects them to bootstrap the
// core themselves. GLPI 11 boots its kernel before requiring the file, where
// including this again would be an error.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);

header('Content-Type: application/json; charset=UTF-8');

$profiles = array_values(array_filter(array_map('intval', (array) ($_GET['profiles'] ?? []))));

echo json_encode([
    'profiles' => $profiles,
    'locks'    => PluginFieldlockLock::getLocksForProfilesAllItemtypes($profiles),
]);
