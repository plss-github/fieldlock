<?php

/**
 * A single "this field is locked for this profile" record.
 *
 * One row per (profile, itemtype, field) triplet. `field` holds the HTML `name`
 * attribute of the input as GLPI renders it, normalised by self::normalizeField()
 * so that `foo[]` and `foo` collapse onto the same key.
 */
class PluginFieldlockLock extends CommonDBTM
{
    public static $rightname = 'config';

    public const MODE_READONLY = 'readonly';
    public const MODE_HIDDEN   = 'hidden';

    /**
     * Input keys that must survive filtering whatever the configuration says.
     *
     * These are GLPI plumbing rather than user-editable fields; dropping them
     * would break the write instead of protecting it. Underscore-prefixed keys
     * in general *are* filtered, because real form fields such as `_actors`
     * live in that namespace and are exactly what admins want to lock.
     */
    private const NEVER_FILTERED = ['id', '_glpi_csrf_token', 'entities_id', 'is_recursive'];

    public static function getTypeName($nb = 0)
    {
        return __('Field lock', 'fieldlock');
    }

    public static function getModes(): array
    {
        return [
            self::MODE_READONLY => __('Read-only (greyed out)', 'fieldlock'),
            self::MODE_HIDDEN   => __('Hidden', 'fieldlock'),
        ];
    }

    /**
     * Itemtypes offered in the configuration UI, grouped into tabs.
     *
     * Only classes that actually exist in this GLPI install are returned, so the
     * list degrades gracefully across versions and editions.
     *
     * @return array<string, array<int, string>> tab label => itemtype list
     */
    public static function getItemtypeGroups(): array
    {
        $groups = [
            __('Assistance') => [
                'Ticket', 'Problem', 'Change', 'TicketRecurrent',
                'ITILFollowup', 'ITILSolution', 'TicketTask', 'TicketValidation',
            ],
            __('Assets') => [
                'Computer', 'Monitor', 'Software', 'NetworkEquipment', 'Peripheral',
                'Phone', 'Printer', 'Rack', 'Enclosure', 'PDU', 'Cable',
            ],
            __('Management') => [
                'SoftwareLicense', 'Budget', 'Supplier', 'Contact', 'Contract',
                'Document', 'Line', 'Certificate', 'Datacenter', 'Cluster',
                'Domain', 'Appliance', 'Database', 'DatabaseInstance',
            ],
            __('Tools') => [
                'Project', 'ProjectTask', 'Reminder', 'RSSFeed', 'KnowbaseItem',
            ],
            __('Administration') => [
                'User', 'Group', 'Entity', 'Profile', 'Location', 'Manufacturer', 'State',
            ],
        ];

        $available = [];
        foreach ($groups as $label => $itemtypes) {
            $existing = array_values(array_filter($itemtypes, static fn($it) => class_exists($it)));
            if ($existing !== []) {
                $available[$label] = $existing;
            }
        }

        return $available;
    }

    /**
     * Itemtypes that have no form page of their own.
     *
     * `front/itilfollowup.form.php` and friends are POST-only action handlers -
     * they answer 400/500 to a GET and never render anything. Their fields are
     * rendered inside the parent ITIL object's timeline instead, so the preview
     * loads the host's form and picks them up from there.
     *
     * @return array<string, string> itemtype => host itemtype
     */
    public static function getPreviewHosts(): array
    {
        return [
            'ITILFollowup'     => 'Ticket',
            'ITILSolution'     => 'Ticket',
            'TicketTask'       => 'Ticket',
            'TicketValidation' => 'Ticket',
        ];
    }

    /**
     * How the configuration screen should preview one itemtype.
     *
     * `needs_id` marks a form that only exists for an already-saved item, either
     * because the itemtype is hosted in a parent's form or because the fields
     * worth locking (status, solution, approvals) are not rendered on creation.
     *
     * @return array{itemtype: string, host: string, needs_id: bool}
     */
    public static function getPreviewSpec(string $itemtype): array
    {
        $host = self::getPreviewHosts()[$itemtype] ?? $itemtype;

        return [
            'itemtype' => $itemtype,
            'host'     => $host,
            'needs_id' => $host !== $itemtype,
        ];
    }

    /**
     * Flat list of every itemtype the plugin may lock.
     *
     * @return array<int, string>
     */
    public static function getManagedItemtypes(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::getItemtypeGroups()))));
    }

    public static function isManagedItemtype(string $itemtype): bool
    {
        return in_array($itemtype, self::getManagedItemtypes(), true);
    }

    /**
     * Collapse the variations GLPI uses for one logical field onto a single key.
     *
     * `users_id[]` and `users_id` are the same field; leading/trailing whitespace
     * and array indexes (`_actors[requester][0]`) are dropped.
     */
    public static function normalizeField(string $field): string
    {
        $field = trim($field);
        // Drop any trailing array notation, including indexed ones.
        $field = preg_replace('/(\[[^\]]*\])+$/', '', $field) ?? $field;

        return $field;
    }

    /**
     * Locks defined for a set of profiles on one itemtype.
     *
     * @param array<int, int> $profiles_ids
     * @return array<string, array{mode: string, profiles: array<int,int>}> keyed by field
     */
    public static function getLocksForProfiles(array $profiles_ids, string $itemtype): array
    {
        global $DB;

        $profiles_ids = array_values(array_filter(array_map('intval', $profiles_ids)));
        if ($profiles_ids === [] || !self::isManagedItemtype($itemtype)) {
            return [];
        }

        $locks = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype'    => $itemtype,
                'profiles_id' => $profiles_ids,
            ],
        ]);

        foreach ($iterator as $row) {
            $field = $row['field'];
            if (!isset($locks[$field])) {
                $locks[$field] = ['mode' => $row['lock_mode'], 'profiles' => []];
            }
            $locks[$field]['profiles'][] = (int) $row['profiles_id'];
        }

        return $locks;
    }

    /**
     * Locks defined for a set of profiles across every managed itemtype.
     *
     * @param array<int, int> $profiles_ids
     * @return array<string, array<string, array{mode: string, profiles: array<int,int>}>>
     */
    public static function getLocksForProfilesAllItemtypes(array $profiles_ids): array
    {
        global $DB;

        $profiles_ids = array_values(array_filter(array_map('intval', $profiles_ids)));
        if ($profiles_ids === []) {
            return [];
        }

        $locks = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['profiles_id' => $profiles_ids],
        ]);

        foreach ($iterator as $row) {
            $itemtype = $row['itemtype'];
            $field    = $row['field'];
            if (!isset($locks[$itemtype][$field])) {
                $locks[$itemtype][$field] = ['mode' => $row['lock_mode'], 'profiles' => []];
            }
            $locks[$itemtype][$field]['profiles'][] = (int) $row['profiles_id'];
        }

        return $locks;
    }

    /**
     * Every lock that applies to the session's currently active profile.
     *
     * @return array<string, array<string, string>> itemtype => (field => mode)
     */
    public static function getLocksForCurrentProfile(): array
    {
        global $DB;

        $profiles_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profiles_id <= 0) {
            return [];
        }

        // Hit once per request: this runs on plugin init and again on every
        // add/update, which massive actions call in a loop.
        static $cache = [];
        if (isset($cache[$profiles_id])) {
            return $cache[$profiles_id];
        }

        $locks = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['profiles_id' => $profiles_id],
        ]);

        foreach ($iterator as $row) {
            $locks[$row['itemtype']][$row['field']] = $row['lock_mode'];
        }

        $cache[$profiles_id] = $locks;

        return $locks;
    }

    /**
     * Create or update a lock for each given profile.
     *
     * @param array<int, int> $profiles_ids
     */
    public static function setLock(array $profiles_ids, string $itemtype, string $field, string $mode): bool
    {
        $field = self::normalizeField($field);
        if ($field === '' || !self::isManagedItemtype($itemtype)) {
            return false;
        }
        if (!array_key_exists($mode, self::getModes())) {
            return false;
        }

        foreach (array_map('intval', $profiles_ids) as $profiles_id) {
            if ($profiles_id <= 0) {
                continue;
            }

            $lock = new self();
            $existing = $lock->find([
                'profiles_id' => $profiles_id,
                'itemtype'    => $itemtype,
                'field'       => $field,
            ], [], 1);

            if ($existing !== []) {
                $row = reset($existing);
                if ($row['lock_mode'] !== $mode) {
                    $lock->update(['id' => $row['id'], 'lock_mode' => $mode]);
                }
                continue;
            }

            $lock->add([
                'profiles_id' => $profiles_id,
                'itemtype'    => $itemtype,
                'field'       => $field,
                'lock_mode'   => $mode,
            ]);
        }

        return true;
    }

    /**
     * Drop the lock on one field for each given profile.
     *
     * @param array<int, int> $profiles_ids
     */
    public static function removeLock(array $profiles_ids, string $itemtype, string $field): bool
    {
        $field = self::normalizeField($field);
        if ($field === '') {
            return false;
        }

        $lock = new self();
        foreach (array_map('intval', $profiles_ids) as $profiles_id) {
            if ($profiles_id <= 0) {
                continue;
            }
            foreach ($lock->find([
                'profiles_id' => $profiles_id,
                'itemtype'    => $itemtype,
                'field'       => $field,
            ]) as $row) {
                $lock->delete(['id' => $row['id']], true);
            }
        }

        return true;
    }

    /**
     * Drop every lock a set of profiles has on one itemtype.
     *
     * @param array<int, int> $profiles_ids
     */
    public static function clearItemtype(array $profiles_ids, string $itemtype): bool
    {
        $lock = new self();
        foreach (array_map('intval', $profiles_ids) as $profiles_id) {
            if ($profiles_id <= 0) {
                continue;
            }
            foreach ($lock->find(['profiles_id' => $profiles_id, 'itemtype' => $itemtype]) as $row) {
                $lock->delete(['id' => $row['id']], true);
            }
        }

        return true;
    }

    /**
     * Strip locked fields from an item's input before GLPI writes it.
     *
     * This is the enforcement that matters: the CSS/JS layer only greys the field
     * in the browser and can be undone from the dev console, so the write path has
     * to drop the values independently.
     */
    public static function filterInput(CommonDBTM $item): void
    {
        if (!is_array($item->input) || $item->input === []) {
            return;
        }

        $itemtype = get_class($item);
        $locks    = self::getLocksForCurrentProfile()[$itemtype] ?? [];
        if ($locks === []) {
            return;
        }

        foreach (array_keys($item->input) as $key) {
            $field = self::normalizeField((string) $key);
            if (in_array($field, self::NEVER_FILTERED, true)) {
                continue;
            }
            if (isset($locks[$field])) {
                unset($item->input[$key]);
            }
        }
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            return;
        }

        $charset   = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        $query = "CREATE TABLE `$table` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `profiles_id`   INT UNSIGNED NOT NULL DEFAULT '0',
            `itemtype`      VARCHAR(255) NOT NULL,
            `field`         VARCHAR(255) NOT NULL,
            `lock_mode`     VARCHAR(20)  NOT NULL DEFAULT 'readonly',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`profiles_id`, `itemtype`, `field`),
            KEY `profiles_id` (`profiles_id`),
            KEY `itemtype` (`itemtype`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC;";

        PluginFieldlockCompat::dbQuery($query);
        $migration->displayMessage(sprintf(__('Table %s created', 'fieldlock'), $table));
    }

    public static function uninstall(): void
    {
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            PluginFieldlockCompat::dbQuery("DROP TABLE `$table`");
        }
    }
}
