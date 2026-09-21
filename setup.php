<?php

/**
 * Field Lock - lock GLPI form fields per profile.
 */

define('PLUGIN_FIELDLOCK_VERSION', '1.2.0');
define('PLUGIN_FIELDLOCK_MIN_GLPI', '10.0.0');
define('PLUGIN_FIELDLOCK_MAX_GLPI', '11.9.99');

/**
 * Plugin bootstrap: register hooks.
 */
function plugin_init_fieldlock()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['fieldlock'] = true;
    $PLUGIN_HOOKS['config_page']['fieldlock']    = 'front/config.form.php';

    Plugin::registerClass('PluginFieldlockLock');

    // Front-end enforcement: shipped on every authenticated page.
    // The path differs between GLPI 10 and 11; see PluginFieldlockCompat.
    $PLUGIN_HOOKS['add_css']['fieldlock']        = [PluginFieldlockCompat::assetPath('css/fieldlock.css')];
    $PLUGIN_HOOKS['add_javascript']['fieldlock'] = [PluginFieldlockCompat::assetPath('js/fieldlock.js')];

    // Ship the active profile's locks inline in <head> rather than fetching them
    // over AJAX, so fields are already greyed out on first paint.
    $payload = plugin_fieldlock_get_locks_payload();
    if ($payload !== null) {
        $PLUGIN_HOOKS['add_header_tag']['fieldlock'] = [
            [
                'tag'        => 'meta',
                'properties' => [
                    'name'    => 'fieldlock:locks',
                    'content' => $payload,
                ],
            ],
        ];
    }

    // Server-side enforcement. `Plugin::doHook()` dispatches item hooks by exact
    // class name (no wildcard), so every managed itemtype must be registered.
    foreach (PluginFieldlockLock::getManagedItemtypes() as $itemtype) {
        $PLUGIN_HOOKS['pre_item_add']['fieldlock'][$itemtype]    = 'plugin_fieldlock_pre_item_add';
        $PLUGIN_HOOKS['pre_item_update']['fieldlock'][$itemtype] = 'plugin_fieldlock_pre_item_update';
    }
}

function plugin_version_fieldlock()
{
    return [
        'name'         => 'Field Lock',
        'version'      => PLUGIN_FIELDLOCK_VERSION,
        'author'       => 'Ampris',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_FIELDLOCK_MIN_GLPI,
                'max' => PLUGIN_FIELDLOCK_MAX_GLPI,
            ],
            'php' => ['min' => '7.4'],
        ],
    ];
}

function plugin_fieldlock_check_prerequisites()
{
    return true;
}

function plugin_fieldlock_check_config($verbose = false)
{
    return true;
}

/**
 * JSON blob of the locks that apply to the session's active profile.
 *
 * Returns null when there is nothing to enforce (no session, plugin not installed
 * yet, or no lock defined) so that no empty meta tag is emitted.
 */
function plugin_fieldlock_get_locks_payload(): ?string
{
    global $DB;

    if (!isset($_SESSION['glpiactiveprofile']['id'])) {
        return null;
    }
    if (!($DB instanceof DBmysql) || !$DB->connected) {
        return null;
    }
    if (!$DB->tableExists(PluginFieldlockLock::getTable())) {
        return null;
    }

    $locks = PluginFieldlockLock::getLocksForCurrentProfile();
    if ($locks === []) {
        return null;
    }

    return json_encode($locks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
