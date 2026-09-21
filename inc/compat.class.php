<?php

/**
 * The handful of places where GLPI 10 and GLPI 11 differ.
 *
 * Everything version-dependent lives here so the rest of the plugin can be
 * written once. Each method documents what actually differs rather than just
 * branching on a version number.
 */
class PluginFieldlockCompat
{
    /**
     * True on GLPI 11 and later.
     *
     * GLPI 11 rewrote plugin routing, asset serving and request bootstrapping;
     * 10.0.x is the other supported branch.
     */
    public static function isGlpi11(): bool
    {
        return version_compare(GLPI_VERSION, '11.0', '>=');
    }

    /**
     * Path of a bundled asset, as it must be registered/addressed.
     *
     * Both versions read the file from `<plugin>/public/`, but they address it
     * differently: GLPI 11 resolves `/plugins/<key>/css/x.css` to
     * `<plugin>/public/css/x.css`, while GLPI 10 maps the URL straight onto
     * the filesystem and so needs the `public/` segment spelled out.
     *
     * @param string $relative path under `public/`, e.g. `css/picker.css`
     */
    public static function assetPath(string $relative): string
    {
        return self::isGlpi11() ? $relative : 'public/' . $relative;
    }

    /**
     * Web URL of a bundled asset.
     *
     * Uses `Plugin::getWebDir()` rather than a hardcoded `/plugins/fieldlock`
     * so the plugin works from both `plugins/` and `marketplace/`.
     */
    public static function assetUrl(string $relative): string
    {
        return self::pluginWebDir() . '/' . self::assetPath($relative);
    }

    /** Web path of the plugin directory, without the GLPI root prefix. */
    public static function pluginWebDir(): string
    {
        $dir = Plugin::getWebDir('fieldlock', false);

        return is_string($dir) && $dir !== '' ? '/' . ltrim($dir, '/') : '/plugins/fieldlock';
    }

    /** Cache-busting token for an asset, so an upgrade is picked up at once. */
    public static function assetVersion(string $relative): string
    {
        $mtime = @filemtime(dirname(__DIR__) . '/public/' . $relative);

        return (string) ($mtime ?: PLUGIN_FIELDLOCK_VERSION);
    }

    /**
     * HTML-escape a string.
     *
     * GLPI 11 ships a global `htmlescape()`; GLPI 10 does not.
     */
    public static function escape($value): string
    {
        if (function_exists('htmlescape')) {
            return htmlescape((string) $value);
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Run a write query.
     *
     * `DBmysql::doQuery()` replaced `query()` partway through the 10.0 series,
     * and `query()` is deprecated in 11.
     */
    public static function dbQuery(string $sql)
    {
        global $DB;

        return method_exists($DB, 'doQuery') ? $DB->doQuery($sql) : $DB->query($sql);
    }

    /**
     * Whether GLPI validates the CSRF token on its own for this request.
     *
     * GLPI 11 checks every POST in a kernel listener, reading the token from
     * the `X-Glpi-Csrf-Token` header on XHR. GLPI 10 leaves it to the script,
     * so the plugin has to check it itself.
     */
    public static function checkAjaxCsrf(): void
    {
        if (self::isGlpi11()) {
            return; // already validated upstream
        }

        $token = $_POST['_glpi_csrf_token']
            ?? ($_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '');

        Session::checkCSRF(['_glpi_csrf_token' => $token]);
    }
}
