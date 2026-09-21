<?php

function plugin_fieldlock_install()
{
    $migration = new Migration(PLUGIN_FIELDLOCK_VERSION);

    PluginFieldlockLock::install($migration);

    $migration->executeMigration();

    return true;
}

function plugin_fieldlock_uninstall()
{
    PluginFieldlockLock::uninstall();

    return true;
}

/**
 * Drop locked fields from the input before GLPI creates the item.
 */
function plugin_fieldlock_pre_item_add(CommonDBTM $item)
{
    PluginFieldlockLock::filterInput($item);
}

/**
 * Drop locked fields from the input before GLPI updates the item.
 */
function plugin_fieldlock_pre_item_update(CommonDBTM $item)
{
    PluginFieldlockLock::filterInput($item);
}
