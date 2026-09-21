<?php

/**
 * Field Lock configuration screen.
 *
 * Pick one or more profiles, pick a form, then click fields in the live preview
 * to lock them. Every click round-trips to ajax/setlock.php immediately.
 */

// GLPI 10 executes plugin scripts directly and expects them to bootstrap the
// core themselves. GLPI 11 boots its kernel before requiring the file, where
// including this again would be an error.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// GLPI 11 requires legacy scripts from inside a controller method, so the
// globals have to be imported explicitly.
global $CFG_GLPI;

Session::checkRight('config', UPDATE);

Html::header(
    __('Field Lock', 'fieldlock'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$profiles = [];
foreach ((new Profile())->find([], ['name']) as $profile) {
    $profiles[(int) $profile['id']] = $profile['name'];
}

$groups     = PluginFieldlockLock::getItemtypeGroups();
$js_groups  = [];
$first_type = null;

foreach ($groups as $group_label => $itemtypes) {
    foreach ($itemtypes as $itemtype) {
        $first_type ??= $itemtype;
        $spec = PluginFieldlockLock::getPreviewSpec($itemtype);

        $js_groups[$group_label][] = [
            'itemtype'  => $itemtype,
            'label'     => $itemtype::getTypeName(1),
            // Hosted itemtypes are previewed through their parent's form.
            'url'       => Toolbox::getItemTypeFormURL($spec['host'], false),
            'host'      => $spec['host'],
            'host_label' => $spec['host']::getTypeName(1),
            'needs_id'  => $spec['needs_id'],
        ];
    }
}

$config = [
    'root_doc'   => $CFG_GLPI['root_doc'],
    'ajax_base'  => $CFG_GLPI['root_doc'] . PluginFieldlockCompat::pluginWebDir() . '/ajax',
    'picker_css' => $CFG_GLPI['root_doc'] . PluginFieldlockCompat::assetUrl('css/picker.css'),
    'csrf_token' => Session::getNewCSRFToken(),
    'version'    => PluginFieldlockCompat::assetVersion('css/picker.css'),
    'groups'     => $js_groups,
    'modes'      => PluginFieldlockLock::getModes(),
    'itemtypes'  => PluginFieldlockLock::getManagedItemtypes(),
    'i18n'       => [
        'no_profile'   => __('Select at least one profile to start locking fields.', 'fieldlock'),
        'loading'      => __('Loading form…', 'fieldlock'),
        'locked'       => __('locked field(s)', 'fieldlock'),
        'saved'        => __('Saved', 'fieldlock'),
        'error'        => __('Could not save the change', 'fieldlock'),
        'confirm_wipe' => __('Remove every lock on this form for the selected profiles?', 'fieldlock'),
        'partial'      => __('Locked for some of the selected profiles only', 'fieldlock'),
        'unsupported'  => __('This form has no field that can be locked.', 'fieldlock'),
        'needs_id'     => __('This form only exists for a saved item. Enter an existing %s ID above.', 'fieldlock'),
        'load_failed'  => __('GLPI refused to render this form (HTTP %s).', 'fieldlock'),
        'foreign'      => __('Belongs to %s, not to the selected form', 'fieldlock'),
    ],
];

// Stamp each asset with its own mtime. Html::css()/Html::script() default to
// GLPI's core version, which does not change when the plugin does, so browsers
// would keep serving a stale copy (they are sent with a one-month max-age).
echo Html::css(
    PluginFieldlockCompat::assetUrl('css/picker.css'),
    ['version' => PluginFieldlockCompat::assetVersion('css/picker.css')]
);
?>

<div class="fieldlock-config">
  <div class="card mb-3">
    <div class="card-body">
      <div class="row align-items-end g-3">
        <div class="col-md-8">
          <label class="form-label" for="fieldlock-profiles">
            <?php echo __('Profiles to configure', 'fieldlock'); ?>
          </label>
          <?php
            Dropdown::showFromArray('fieldlock_profiles', $profiles, [
                'multiple' => true,
                'values'   => [],
                'width'    => '100%',
                'rand'     => 'fieldlock',
            ]);
          ?>
          <div class="form-hint">
            <?php echo __('Locks are applied to every selected profile at once.', 'fieldlock'); ?>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?php echo __('Lock mode', 'fieldlock'); ?></label>
          <select id="fieldlock-mode" class="form-select">
            <?php foreach (PluginFieldlockLock::getModes() as $value => $label) { ?>
              <option value="<?php echo PluginFieldlockCompat::escape($value); ?>"><?php echo PluginFieldlockCompat::escape($label); ?></option>
            <?php } ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <ul class="nav nav-tabs" id="fieldlock-group-tabs" role="tablist">
      <?php $i = 0; foreach (array_keys($js_groups) as $group_label) { ?>
        <li class="nav-item" role="presentation">
          <button class="nav-link <?php echo $i === 0 ? 'active' : ''; ?>"
                  type="button"
                  data-fieldlock-group="<?php echo PluginFieldlockCompat::escape($group_label); ?>">
            <?php echo PluginFieldlockCompat::escape($group_label); ?>
          </button>
        </li>
      <?php $i++; } ?>
    </ul>

    <div class="card-body border-bottom py-2">
      <div id="fieldlock-itemtypes" class="fieldlock-pills"></div>
    </div>

    <div class="card-body border-bottom py-2 d-flex align-items-center gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 text-nowrap" for="fieldlock-item-id">
          <?php echo __('Item ID', 'fieldlock'); ?>
        </label>
        <input type="number" min="1" step="1" id="fieldlock-item-id"
               class="form-control form-control-sm" style="width: 8rem"
               placeholder="<?php echo __('new item', 'fieldlock'); ?>">
        <span class="fieldlock-hint">
          <?php echo __('Leave empty for the creation form. Fill it to reach fields that only exist on a saved item.', 'fieldlock'); ?>
        </span>
      </div>
      <span id="fieldlock-counter" class="badge bg-secondary">0 <?php echo PluginFieldlockCompat::escape($config['i18n']['locked']); ?></span>
      <span id="fieldlock-status" class="fieldlock-status text-muted"></span>
      <div class="ms-auto d-flex gap-2">
        <button type="button" id="fieldlock-reload" class="btn btn-sm btn-outline-secondary">
          <?php echo __('Reload preview', 'fieldlock'); ?>
        </button>
        <button type="button" id="fieldlock-clear" class="btn btn-sm btn-outline-danger">
          <?php echo __('Clear locks on this form', 'fieldlock'); ?>
        </button>
      </div>
    </div>

    <div class="card-body p-0">
      <div id="fieldlock-empty" class="p-4 text-center text-muted">
        <?php echo PluginFieldlockCompat::escape($config['i18n']['no_profile']); ?>
      </div>
      <div id="fieldlock-frame-wrap" class="fieldlock-frame-wrap d-none">
        <iframe id="fieldlock-frame" class="fieldlock-frame" title="<?php echo __('Form preview', 'fieldlock'); ?>"></iframe>
      </div>
    </div>
  </div>
</div>

<script type="application/json" id="fieldlock-config">
<?php echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>

<?php
echo Html::script(
    PluginFieldlockCompat::assetUrl('js/picker.js'),
    ['version' => PluginFieldlockCompat::assetVersion('js/picker.js')]
);

Html::footer();
