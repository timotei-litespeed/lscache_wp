<?php
/**
 * Auto registration for LiteSpeed classes
 *
 * @since      	1.1.0
 */
defined('WPINC') || exit();

// Force define for object cache usage before plugin init
!defined('LSCWP_DIR') && define('LSCWP_DIR', __DIR__ . '/'); // Full absolute path '/var/www/html/***/wp-content/plugins/litespeed-cache/' or MU

if (!function_exists('litespeed_autoload')) {
	function litespeed_autoload($cls)
	{
		if (strpos($cls, '.') !== false) {
			return;
		}

		if (strpos($cls, 'LiteSpeed') !== 0) {
			return;
		}

		$file = explode('\\', $cls);
		array_shift($file);
		$file = implode('/', $file);
		$file = str_replace('_', '-', strtolower($file));

		if (strpos($file, 'lib/') === 0 || strpos($file, 'cli/') === 0 || strpos($file, 'thirdparty/') === 0) {
			$file = LSCWP_DIR . $file . '.cls.php';
		} else {
			$file = LSCWP_DIR . 'src/' . $file . '.cls.php';
		}

		if (file_exists($file)) {
			require_once $file;
		}
	}
}
// spl_autoload_register('litespeed_autoload');

// Both
include_once 'src/root.cls.php';
include_once 'src/base.cls.php';
include_once 'src/core.cls.php';
include_once 'src/api.cls.php';
include_once 'src/activation.cls.php';
include_once 'src/conf.cls.php';
include_once 'src/error.cls.php';
include_once 'src/debug2.cls.php';
include_once 'src/lang.cls.php';
include_once 'src/localization.cls.php';
include_once 'src/media.cls.php';
include_once 'src/tag.cls.php';
include_once 'src/esi.cls.php';
include_once 'src/purge.cls.php';
include_once 'src/rest.cls.php';

// Admin
if (is_admin()) {
	include_once 'src/css.cls.php';
	include_once 'src/admin-display.cls.php';
	include_once 'src/admin-settings.cls.php';
	include_once 'src/admin.cls.php';
	include_once 'src/cdn-setup.cls.php';
	include_once 'src/cloud.cls.php';
	include_once 'src/crawler-map.cls.php';
	include_once 'src/crawler.cls.php';
	include_once 'src/db-optm.cls.php';
	include_once 'src/data.upgrade.func.php';
	include_once 'src/doc.cls.php';
	include_once 'src/health.cls.php';
	include_once 'src/img-optm.cls.php';
	include_once 'src/import.cls.php';
	include_once 'src/instance.cls.php';
	include_once 'src/object-cache.cls.php';
	// include_once('src/object.lib.php');
	include_once 'src/optimize.cls.php';
	include_once 'src/optimizer.cls.php';
	include_once 'src/preset.cls.php';
	include_once 'src/report.cls.php';
	include_once 'src/str.cls.php';
	include_once 'src/vpi.cls.php';
} else {
	// Frontend
	include_once 'src/avatar.cls.php';
	include_once 'src/file.cls.php';
}

// ON CACHE CLEAR
include_once 'src/router.cls.php';
include_once 'src/gui.cls.php';
include_once 'src/metabox.cls.php';
include_once 'src/control.cls.php';
include_once 'src/vary.cls.php';
include_once 'src/utility.cls.php';
include_once 'src/htaccess.cls.php';
include_once 'src/tool.cls.php';
include_once 'src/placeholder.cls.php';
include_once 'src/cdn.cls.php';
include_once 'src/task.cls.php';
include_once 'src/data.cls.php';
include_once 'src/ucss.cls.php';

//Third Party
include_once 'thirdparty/aelia-currencyswitcher.cls.php';
include_once 'thirdparty/amp.cls.php';
include_once 'thirdparty/autoptimize.cls.php';
include_once 'thirdparty/avada.cls.php';
include_once 'thirdparty/bbpress.cls.php';
include_once 'thirdparty/beaver-builder.cls.php';
include_once 'thirdparty/caldera-forms.cls.php';
include_once 'thirdparty/divi-theme-builder.cls.php';
include_once 'thirdparty/elementor.cls.php';
include_once 'thirdparty/facetwp.cls.php';
include_once 'thirdparty/gravity-forms.cls.php';
include_once 'thirdparty/litespeed-check.cls.php';
include_once 'thirdparty/nextgengallery.cls.php';
include_once 'thirdparty/perfmatters.cls.php';
include_once 'thirdparty/theme-my-login.cls.php';
include_once 'thirdparty/user-switching.cls.php';
include_once 'thirdparty/wc-pdf-product-vouchers.cls.php';
include_once 'thirdparty/wcml.cls.php';
include_once 'thirdparty/woo-paypal.cls.php';
include_once 'thirdparty/woocommerce.cls.php';
include_once 'thirdparty/wp-polls.cls.php';
include_once 'thirdparty/wp-postratings.cls.php';
include_once 'thirdparty/wpdiscuz.cls.php';
include_once 'thirdparty/wplister.cls.php';
include_once 'thirdparty/wpml.cls.php';
include_once 'thirdparty/wptouch.cls.php';
include_once 'thirdparty/yith-wishlist.cls.php';
include_once 'thirdparty/entry.inc.php';
