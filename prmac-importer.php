<?php
/*
Plugin Name: prMac Release Importer
Plugin URI: http://prmac.com/agency/
Description: Pulls the latest press releases from prMac.com and posts them to your blog.
Version: 3.0.1
Author: prMac
Author URI: http://prmac.com/
Copyright 2019  prMac | GeekSuit LLC
*/

$prmac_wp_plugin_version = '3.0.1';


// If you're running the import script on a cron job and want the posts to come from a different user, use this
$prmac_cron_user_id = 1;

// Script will check for this many releases
$prmac_batch_import_size = 20;


// To run on a cron script, use the following line:
// wget http://example.com/PathToWordPress/wp-content/plugin/prmac-importer/prmac-importer.php?prmac_force=1

if (!defined('ABSPATH')) {
  $scanpath = realpath(dirname(__FILE__) . '/..');
  while ($scanpath !== '/') {
    $loadfile = $scanpath . '/wp-load.php';
    if (file_exists($loadfile)) {
      require_once($loadfile);
      break;
    }
    else {
      $scanpath = realpath($scanpath . '/..');
    }
  }
  if (!defined('ABSPATH')) {
    die('Can\'t define ABSPATH.');
  }
}

if (prmac_plugin_is_active())
	require_once("prmac_framework.php");
$_prmac_enableCache = false;

$prmac_agency_code = get_option('prmac_agency_code');
$prmac_post_closed = (get_option('prmac_post_closed')) == 'true';
$prmac_all_channels = (get_option('prmac_all_channels')) == 'true';
$prmac_ignore_publish_date = (get_option('prmac_ignore_publish_date')) == 'true';
$prmac_use_automated_import = (get_option('prmac_use_automated_import')) == 'true';
$prmac_import_category = get_option('prmac_import_category');


$_run_cron_import = !empty($_GET['prmac_force']);
$_is_automated_ping = !empty($_GET['prmac_ping']);

if (!$_run_cron_import)
{
	register_activation_hook(__FILE__, 'prmac_register_automation_if_enabled');
	add_action('wp_login', 'prmac_register_automation_if_enabled');
	add_action('init', 'prmac_init');
}
else
{
	if (!prmac_plugin_is_active())
		exit("Plugin isn't active");

	require_once(ABSPATH . "/wp-includes/pluggable.php");
	if ($_is_automated_ping)
	{
		if (!$prmac_use_automated_import)
			exit("CEASE");

		print "ACCEPTED";
	}

	error_reporting(E_ERROR);
	extract(prmac_perform_import());
	if (!$_is_automated_ping)
		print "{$importedCount} release(s) imported. {$erroredCount} failed to import.";

	exit();
}



function prmac_init()
{
	register_deactivation_hook(__FILE__, 'prmac_unregister_automation');
	
	add_action('admin_menu', 'prmac_config_page');
	add_action('wp_enqueue_scripts', 'prmac_enqueue_scripts');

	// remove the default WordPress canonical URL function
	if( function_exists( 'rel_canonical' ) )
	{
		remove_action( 'wp_head', 'rel_canonical' );
	}
	
	// replace the default WordPress canonical URL function with your own
	add_action( 'wp_head', 'prmac_set_rel_canonical_link_for_post' );
	
	add_action( 'wp_head', 'prmac_add_view' );
}

function prmac_add_view()
{
	if( !is_singular() || get_option('prmac_use_automated_import') == 'false' || get_option('prmac_post_closed') == 'true')
        return;
	
	global $prmac_agency_code;
	
	$prmac_release_id = get_post_meta( get_the_ID(), 'prmac_release_id', true );
	
	prmac_getReleaseDetailByID( $prmac_release_id, $prmac_agency_code );
}

/**
 * Implements the wp_enqueue_scripts() hook.
 */
function prmac_enqueue_scripts() {
  wp_enqueue_script('prmac-linker', plugins_url('prmac-linker.js', __FILE__), array(), FALSE, TRUE);
}

function prmac_config_page()
{
	add_submenu_page('options-general.php', __('prMac Importer'), __('prMac Importer'), 'manage_options', 'prmac-config', 'prmac_conf');
	add_submenu_page('edit.php', __('Run prMac Importer'), __('prMac Importer'), 'manage_options', 'prmac-run', 'prmac_do_import');
}

if ( !function_exists('wp_nonce_field') ) {
	function prmac_nonce_field($action = -1) { return; }
	$prmac_nonce = -1;
} else {
	function prmac_nonce_field($action = -1) { return wp_nonce_field($action); }
	$prmac_nonce = 'prmac-config';
}

function prmac_conf()
{
	global $prmac_agency_code, $prmac_last_error;

	if ( isset($_POST['submit']) )
	{
		if ( function_exists('current_user_can') && !current_user_can('manage_options') )
			die(__('Invalid user.'));

		ini_set("display_errors", "on");

		$prmac_agency_code = trim($_POST['prmac_agency_code']);

		$invalid_key = $prmac_agency_code == "";


		update_option('prmac_agency_code', $prmac_agency_code, "", false);

		update_option('prmac_post_closed',          isset($_POST['prmac_post_closed']) ? 'true' : 'false', "", false);
		update_option('prmac_all_channels',         isset($_POST['prmac_all_channels']) ? 'true' : 'false', "", false);
		update_option('prmac_use_automated_import', isset($_POST['prmac_use_automated_import']) ? 'true' : 'false', "", false);
		update_option('prmac_ignore_publish_date',  isset($_POST['prmac_ignore_publish_date']) ? 'false' : 'true', "", false);
		update_option('prmac_import_category',      $_POST['prmac_import_category'], "", false);

		if (isset($_POST['prmac_use_automated_import']))
			prmac_register_automation();
		else
			prmac_unregister_automation();
	}

	?>
	<?php if ( !empty($_POST) ) : ?>
		<div id="message" class="updated fade"><p><strong>Options saved.<?php if ($invalid_key) { _e(' However, your agency code was invalid.'); } ?></strong></p></div>
	<?php endif; ?>

	<div class="wrap">
		<h2><?php _e('prMac Importer'); ?></h2>
		<div class="narrow">
		<p>The prMac Importer plug-in allows you to easily post the latest Mac news directly from <a href="http://prmac.com/" target="_BLANK">prMac</a>. The manual importer page is located under Posts. You can also set the Importer to automatically import new releases as they are posted to prMac.</p>
		<form action="" method="post" id="prmac-conf" style="margin: auto; width: 400px; ">
			<h3>prMac.com Agency Code</h3>
			<p><input id="prmac_agency_code" name="prmac_agency_code" type="text" size="8" maxlength="9" value="<?php echo htmlspecialchars($prmac_agency_code); ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" /> <a href="http://prmac.com/agency/bundle" target="_BLANK">find at prMac.com</a></p>
			<?php if ($invalid_key) { ?>
				<h3><?php _e('Why might my agency code be invalid?'); ?></h3>
				<p><?php _e('This is probably because you didn\'t copy the entire agency code.'); ?></p>
			<?php } ?>

			<br/>
			<h3>Import Settings</h3>

			<p><label><input name="prmac_use_automated_import" id="prmac_use_automated_import" value="true" type="checkbox" <?php if ( get_option('prmac_use_automated_import') == 'true' ) echo ' checked="checked" '; ?> /> Use automated import (releases will be imported immediately after they are published on prMac.com)</label></p>

			<p><label><input name="prmac_post_closed" id="prmac_post_closed" value="true" type="checkbox" <?php if ( get_option('prmac_post_closed') == 'true' ) echo ' checked="checked" '; ?> /> Import releases as drafts</label></p>

			<p><label><input name="prmac_all_channels" id="prmac_all_channels" value="true" type="checkbox" <?php if ( get_option('prmac_all_channels') == 'true' ) echo ' checked="checked" '; ?> /> Import releases from all channels (if left unchecked, only releases in the channels your agency receives email alerts for will be imported)</label></p>

			<p><label><input name="prmac_ignore_publish_date" id="prmac_ignore_publish_date" value="true" type="checkbox" <?php if ( get_option('prmac_ignore_publish_date') == 'false' ) echo ' checked="checked" '; ?> /> Use prMac publish date as the post date</label></p>

			<?php $categories = get_categories('hierarchical=0&orderby=name&hide_empty=0'); ?>
				<br/>
				<h3>Import Categories</h3>

				<p>Import releases into category:
					<select name="prmac_import_category">
						<option>Don't Set Category</option>
						<?php $selectedCatID = get_option('prmac_import_category'); ?>
						<?php foreach ((array)$categories as $cat) : ?>
							<option value="<?php echo $cat->cat_ID?>" <?php if ($cat->cat_ID == $selectedCatID) echo 'selected="selected"'?>><?php echo attribute_escape($cat->name)?> </option>


						<?php endforeach ?>
					</select>
				</p>


			<p class="submit"><input type="submit" name="submit" value="<?php _e('Update options &raquo;'); ?>" /></p>
		</form>
		</div>
	</div>
	<?php
}


function prmac_do_import()
{
	global $prmac_agency_code, $prmac_post_closed, $prmac_all_channels, $prmac_ignore_publish_date, $prmac_import_category, $userdata, $prmac_batch_import_size, $wpdb;


	print '<div class="wrap">';

	if (!$prmac_agency_code)
	{
		print '<h2>Invalid prMac agency code</h2>';
		print "<h3>You haven't configured your prMac agency code. Go to the settings page and set your Agency Code.</h3></div>";
		return;
	}


	if (!($newInfo = prmac_get_new_releases()))
	{
		//print '<h2>Unable to contact prMac server</h2>';
		//print "<p>The prMac server is unreachable. It may be down, or a new version of this plugin may be required. Check prMac.com for details.</p></div>";
		return false;
	}

	if ( $newInfo == 'no releases' )
	{
		print '<h2>prMac Importer</h2>';
		print "<p>There are currently no new releases available for import based on your subscribed channels. If you would like to change your subscribed channels for this plugin, please refer to your E-mail subscriptions within your <a href=\"https://secure.prmac.com/login\" target=\"_blank\">prMac.com account</a>. Likewise, if you would like to import releases from all channels, please refer to your <a href=\"options-general.php?page=prmac-config\">Wordpress Plugin settings</a>. Alternatively, you can kindly <a href=\"javascript:location.reload(true)\">Check again</a></p></div>";
		return false;
	}

	extract($newInfo); // gives $alreadyImportedReleases, $newReleaseCount, $newReleases

	if (!isset($_POST['DoRunImporter']))
	{
		print '<h2>prMac Importer</h2>';

		if ($newReleaseCount)
		{
			print "<p>There ".prmac_pluralize("are", $newReleaseCount)." $newReleaseCount new prMac release".prmac_pluralize($newReleaseCount)." ready to be imported at this time.</p><p>";



			print 'The newly created posts on your blog will:<br/><ul>';
			if ($prmac_post_closed)
				print "<li>&hellip; have their publish status set to drafts, so they won't be published until you're ready</li>";
			else
				print "<li>&hellip; be immediately published</li>";

			if ($prmac_import_category && $that_category = get_category($prmac_import_category))
				print "<p>&hellip;  be saved under <em><strong>'".attribute_escape($that_category->name)."'</strong></em></li>";
			else
				print '<li>&hellip; not have a category set.</li>';

			print '</ul><form action="" method="post">';
			print '<input type="submit" value="Import '.$newReleaseCount.' prMac article'.prmac_pluralize($newReleaseCount).'" name="DoRunImporter" />';
			print '</form>';
		}
		else
		{
			print '<p>There aren\'t any new prMac releases which haven\'t been imported yet. <a href="javascript:location.reload(true)">Check again</a></p>';
		}
		print "</div>";

		return;
	}


	print '<h2>Running prMac Import script</h2>';

	extract(prmac_import_new_articles($newReleases, $alreadyImportedReleases));	// gives $importedCount, $erroredCount, $fetchLog

	print join("<br/>\n", $fetchLog);

	print "<br /><br /><h3>Import finished</h3>";
	print "<p>{$importedCount} release".prmac_pluralize($importedCount)." ".prmac_pluralize("was", $importedCount)." successfully imported";
	print $erroredCount ? ", and {$erroredCount} release".prmac_pluralize($erroredCount)." couldn't be imported." : ".";
	if ($prmac_all_channels)
		print " All prMac Channels were polled for new releases.";
	else
		print " Only the prMac Channels you have subscribed to in your prMac Email Control were polled for new releases.";
	print "</p>";

	if ($prmac_post_closed && $importedCount)
		print "<p>Since you have set the importer to post ".prmac_pluralize("this", $importedCount)." release".prmac_pluralize($importedCount)." as drafts, you'll need to publish ".prmac_pluralize("it", $importedCount)." yourself.</p>";

	if (!$prmac_ignore_publish_date && $importedCount)
		print "<p>Since you have set the importer to post ".prmac_pluralize("this", $importedCount)." release".prmac_pluralize($importedCount)." using the prMac publish date,  ".prmac_pluralize("they", $importedCount)." may be older than newer non-prMac posts.</p>";


	print "</div>";

}

function prmac_get_new_releases()
{
	global $prmac_agency_code, $prmac_all_channels, $userdata, $prmac_batch_import_size;

	$alreadyImportedReleases = prmac_get_imported_release_list();
	$newReleases = prmac_getReleaseList($prmac_agency_code, $prmac_batch_import_size, $prmac_all_channels, get_site_url());


	if ( !is_array( $alreadyImportedReleases ) )
		$alreadyImportedReleases = array();

	if ( $newReleases == 'no releases' )
		return( 'no releases' );

	if (!$newReleases)
	{
		print '<h3 style="color: #F7280E">Error fetching the release list</h3><p>' . prmac_getLastError() . "</p>";
		return false;
	}

	// Compare the full list to the ones that have already been posted
	for ($i = 0; $i < count($newReleases); )
	{
		if (in_array($newReleases[$i]['id'], $alreadyImportedReleases))
			array_splice($newReleases, $i, 1);
		else
			$i++;
	}

	return array(
			"alreadyImportedReleases"  => $alreadyImportedReleases,
			"newReleases"              => $newReleases,
			"newReleaseCount"          => count($newReleases)
			);
}

function prmac_import_new_articles($newReleases, $alreadyImportedReleases)
{
	global $prmac_agency_code, $prmac_post_closed, $prmac_all_channels, $prmac_ignore_publish_date, $prmac_import_category, $prmac_batch_import_size, $prmac_cron_user_id, $user_ID, $wpdb;

	$importedCount = $erroredCount = $logI = 0;
	$fetchLog = array();

	foreach ($newReleases as $releaseInfo)
	{
		$fetchLog[$logI] = "<strong>Importing</strong> <em><a href=\"{$releaseInfo['url']}\">{$releaseInfo['title']}</a></em> ... ";

		$releaseDetail = prmac_getReleaseDetail($releaseInfo['xmlurl'], $prmac_agency_code);

		if (!$releaseDetail)
		{
			$fetchLog[$logI] .= "failed: there was a problem getting the details from prMac. Framework desc: '" . prmac_getLastError() . "'. Will retry next import.";
			$erroredCount++;
			continue;
		}

		if (!$prmac_ignore_publish_date)
		{
			$post_date_gmt = gmdate('Y-m-d H:i:s', $releaseDetail['timestamp']);
			$post_dt = get_date_from_gmt($post_date_gmt);
		}
		else
		{
			$post_dt = current_time('mysql');
			$post_date_gmt = "";
		}

		$post_content = strtr(htmlentities($releaseDetail['full'], ENT_NOQUOTES, "UTF-8"), array_flip(get_html_translation_table(HTML_SPECIALCHARS)));

		$newPost = array(
			'post_author'		=> $user_ID ? $user_ID : $prmac_cron_user_id,
			'post_date'		    => $post_dt,
			'post_date_gmt'		=> $post_date_gmt,
			'post_modified'		=> $post_dt,
			'post_modified_gmt'	=> $post_date_gmt,
			'post_title'		=> $wpdb->escape($releaseDetail['title']),
			'post_content'		=> $wpdb->escape($post_content),
			'post_excerpt'		=> $wpdb->escape($releaseDetail['summary']),
			'post_status'		=> $prmac_post_closed ? "draft" : "publish",
			'post_name'         => $releaseDetail['title'],
			'post_type'         => 'post',
			'comment_status'	=> get_option('default_comment_status'),
			'ping_status'		=> get_option('default_ping_status'),
			'to_ping'           => $releaseDetail['trackbackurl'],
			'comment_count'		=> 0,
			'post_category'     => array($prmac_import_category)
			);
		$addedPost = wp_insert_post($newPost);
		
		add_post_meta( $addedPost, 'prmac_release_id', $releaseInfo['id'] );

		if (!$prmac_post_closed)
		{
			do_trackbacks($addedPost);
			wp_publish_post($addedPost);
		}

		if ($addedPost)
		{
			$fetchLog[$logI] .= "finished";
			$importedCount++;
		}
		else
		{
			$fetchLog[$logI] .= "failed: there was a problem inserting it into the WordPress database. Will not retry next import.";
			$erroredCount++;
		}
		$logI++;
		$alreadyImportedReleases[] = $releaseInfo['id'];
 	}

	prmac_save_imported_release_list($alreadyImportedReleases);

	return array(
			"importedCount" => $importedCount,
			"erroredCount"  => $erroredCount,
			"fetchLog"      => $fetchLog
			);
}

function prmac_perform_import()
{
	if ( !($newInfo = prmac_get_new_releases()) )
		return false;
	return prmac_import_new_articles($newInfo['newReleases'], $newInfo['alreadyImportedReleases']);
}


function prmac_pluralize($n, $c = 0)
{
	if (is_numeric($n))
		return ($n == 0 || $n > 1) ? "s" : "";

	$table = array("was" => array("were", "was"), "this" => array("these", "this"), "it" => array("them", "it"), "they" => array("they", "it"), "are" => array("are", "is"));
	return $table[$n][($c == 1) ? 1 : 0];
}


function prmac_get_imported_release_list()
{
	$x = get_option('prmac_imported_releases');

	return $x ? unserialize($x) : array();
}

function prmac_save_imported_release_list($releases)
{
	update_option('prmac_imported_releases', serialize($releases), "", 'no');
}

function prmac_register_automation_if_enabled()
{
	global $prmac_use_automated_import;

	if ($prmac_use_automated_import)
		prmac_register_automation();
}

function prmac_register_automation()
{
	global $prmac_agency_code, $prmac_last_error;
	$serverURL = "https://prmac.com/agency/integration.php?ac={$prmac_agency_code}&q=register";

	$params = array("url" => prmac_form_ping_url());

	if ( !($response = prmac_submit_http_request($serverURL, $params)) )
		return false;

	if ( !($accepted = prmac_check_response($response, "ACCEPTED")) )
	{
		$prmac_last_error = $response;
	}
	return $accepted;
}

function prmac_unregister_automation()
{
	global $prmac_agency_code, $prmac_last_error;
	$serverURL = "https://prmac.com/agency/integration.php?ac={$prmac_agency_code}&q=unregister";

	$params = array("url" => prmac_form_ping_url());

	if ( !($response = prmac_submit_http_request($serverURL, $params)) )
		return false;

	if ( !($accepted = prmac_check_response($response, "ACCEPTED")) )
	{
		$x = explode("\n", $response);
		$prmac_last_error = $x[1];
	}
	return $accepted;
}


function prmac_submit_http_request($url, $args)
{
	global $prmac_last_error;

	$postData = prmac_build_post_query($args);
	if (ini_get("allow_url_fopen"))
	{
		$params = array('http' => array(
					'method' => 'POST',
					'content' => $postData,
					'header'=> "Content-type: application/x-www-form-urlencoded\r\n" .
					           "Content-length: " . strlen($postData) . "\r\n"
					));
		$ctx = stream_context_create($params);

		if ( !($fp = fopen($url, 'rb', false, $ctx)) ) {
			$prmac_last_error = "Problem registering with integration server - couldn't connect to host";
			return false;
		}

		if ( ($response = fgets($fp, 1024)) === false) {
			$prmac_last_error = "Problem registering with integration server - couldn't send data";
			return false;
		}
		$response .= fgets($fp, 1024);
		fclose($fp);
		return $response;
	}
	else if (is_callable("curl_init"))
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOP_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}
	else
	{
		print "No way to connect to prMac integration server (allow_url_fopen and libcurl both disabled)";
		return false;
	}
}

function prmac_form_ping_url()
{
	$docRootLength = strlen(realpath($_SERVER['DOCUMENT_ROOT']));
	$path = str_replace("\\", "/", substr(__FILE__, $docRootLength, strlen(__FILE__)-$docRootLength));
	return "http://{$_SERVER['SERVER_NAME']}" . ($path[0] == '/' ? '' : '/') . $path;
}

function prmac_check_response($haystack, $needle)
{
	return (($x = strpos($haystack, $needle)) !== false) && ($x < 5);
}

// PHP4 compatible version of http_build_query
function prmac_build_post_query($arr)
{
	$ret = "";
	$i = 0;
	foreach ($arr as $k => $v)
	{
		if ($i++ > 0)
			$ret .= "&";

		$ret .= urlencode($k) . "=" . urlencode($v);
	}

	return $ret;
}

function prmac_plugin_is_active()
{
	$allPlugins = ($x = get_option('active_plugins')) ? $x : array();
	return in_array("prmac-importer/prmac-importer.php", $allPlugins);
}
