<?php
/*******************************************************************
* prMac Integration Bundle framework v 2.0 * Copyright (c) prMac
*
* Publicly accessable functions in this framework:
* 	prmac_getReleaseList       - Retrieves the list of latest n releases for the specified agency.
* 	prmac_getReleaseDetail     - Retrieves the full details for a prMac release, including the body.
* 	prmac_getReleaseDetailByID - Retrieves the full details for a prMac release, including the body given a prMac release ID.
* 	prmac_getLastError         - Get the last error returned from prMac. If you get null for any of the above functions, you can use this
* So, to get the content of the latest 10 articles, you request a list of the articles with prmac_getReleaseList(), then for each article, use prmac_getReleaseDetail() and fetch the content. Note that the 'body' portion of the release includes basic HTML - <br /> for linebreaks, and <a>'s for links.
*
* If you are using these data directly on your site as user-viewable content, it's necessary to enable caching so that your script won't ping prMac everytime a user views a page. If you're writing a cron job that runs daily and just inserts prMac articles into a database (or any other situation where you use the data infrequently), caching isn't neccessary. Please be kind to our server :)
*
* To enable caching, set the $_prmac_enableCache flag to true. You'll then need a PHP-writable directory where the framework will store the cached content. The cache is randomly garbage collected to stay under $_prmac_maxCacheEntries. If you use the cache on a very high-traffic site, it's advisable to call prmacp_garbageCollectCache() in a daily cron job and disable $_prmac_autoGarbageCollect.
*
* Full list of flags (to set them, simply alter the variable after include()ing this file):
*   $_prmac_enableCache (default: false) - Enable caching of XML data?
* 	$_prmac_cacheDir    (default: "prmac_cache") - Path (absolute or relative to the script using this file) to a PHP-writable directory where the cache will reside.
* 	$_prmac_cacheExpire (default: 6*60*60) - Length of times items will be left in the cache.
*	$_prmac_maxCacheEntries (default: 20) - Max number of items in the cache at a time.
*	$_prmac_autoGarbageCollect (default: true) - Automatically (once every 50 hits) clean the cache to 20 entries.
*
*******************************************************************/

$prmac_framework_version = '2.0.1';

/*	prmac_getReleaseList - Retrieves the list of latest n releases for the specified agency.
	Arguments:
		$agencyCode - Your agency code (find this on your agency homepage)
		[$releaseCount] - (Optional) The number of releases to gather. There is a cap of 100 releases.
	Returns: an indexed array of releases, with each release being an associative array containing the following fields
		title - The releases's full title
		id - the prMac release ID number
		date - DD-MM-YYYY of date published
		xmlurl - HTTP url to the full xml content for the article
		url - HTTP url to the normal html view for the article
		summary - Brief (500 wds or fewer) summary of the release
*/
function prmac_getReleaseList($agencyCode, $releaseCount = 25, $allChannels = FALSE, $server = NULL) {

  if (empty($server)) {
    if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || $_SERVER['SERVER_PORT'] == 443) {
      $server = 'https://';
    }
    else {
      $server = 'http://';
    }
    $server .= isset($_SERVER) && isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
  }

	$url = str_replace(array("%agency_code%", "%count%","%dm%", "%all%"), array($agencyCode, $releaseCount, $server, $allChannels ? 1 : ""), PRMAC_LISTURL);

	$xml = prmacp_fetchContent($url);

	$xmlParser = new PMXMLParser($xml);
	$xmlTree = $xmlParser->document;

	if (isset($xmlTree['ERROR']))
	{
		prmacp_setLastError("Error fetching data from prMac: " . $xmlTree['ERROR'][0]['data']);
		return NULL;
	}

	$rawReleases = $xmlTree['RELEASES'][0]['RELEASE'];

	$refinedReleases = array();
	foreach ((array)$rawReleases as $rawRelease)
	{
		$refinedReleases[] =
				array("title"        => $rawRelease['TITLE'][0]['data'],
				      "id"           => $rawRelease['ID'][0]['data'],
				      "date"         => $rawRelease['DATE'][0]['data'],
				      "timestamp"    => $rawRelease['TIMESTAMP'][0]['data'],
				      "xmlurl"       => $rawRelease['XMLURL'][0]['data'],
				      "url"          => $rawRelease['URL'][0]['data'],
				      "trackbackurl" => $rawRelease['TRACKBACKURL'][0]['data'],
				      "summary"      => $rawRelease['SUMMARY'][0]['data']
				      );
	}

	if ( empty( $refinedReleases ) )
		return 'no releases';
	else
		return $refinedReleases;
}



/*	prmac_getReleaseDetail - Retrieves the full details for a prMac release, including the body.
	Arguments:
		$releaseURL - http URL to XML data of release (must be properly url-encoded, without any spaces). Usually the 'xmlurl' field retrieved from prmac_getReleases
		[$agencyCode] - (Optional) Your agency code. If given, trackback URLs will be directly usable
	Returns: an associative array of details for the release, containing the following fields:
		title - The releases's full title
		id - the prMac release ID number
		date - DD-MM-YYYY of date published
		url - HTTP url to the normal html view for the article
		trackbackurl - Trackback url. If $agencyCode is given, this will be immediately usable. You must add '&ac=' . $your_agency_code to the end for it to work otherwise
		summary - Brief (500 wds or fewer) summary of the release
		full - 'body', with 'corporateidentity' appended to the end (use this when publishing a release)
		body - full text of body, including external links (HTML formatted line breaks and links)
		corporateidentity - Brief history of the company making the release and what they do
*/
function prmac_getReleaseDetail($releaseURL, $agencyCode = "")
{
	$xml = prmacp_fetchContent($releaseURL);

	$xmlParser = new PMXMLParser($xml);
	$xmlTree = $xmlParser->document;

	if (isset($xmlTree['ERROR']))
	{
		prmacp_setLastError("Error fetching data from prMac: " . $xmlTree['ERROR'][0]['data']);
		return null;
	}

	$rawRelease = $xmlTree['RELEASE'][0];

	return array("title"        => $rawRelease['TITLE'][0]['data'],
		         "id"           => $rawRelease['ID'][0]['data'],
		         "date"         => $rawRelease['DATE'][0]['data'],
		         "timestamp"    => $rawRelease['TIMESTAMP'][0]['data'],
		         "url"          => $rawRelease['URL'][0]['data'],
		         "trackbackurl" => $rawRelease['TRACKBACKURL'][0]['data'] . "&ac={$agencyCode}",
		         "summary"      => $rawRelease['SUMMARY'][0]['data'],
		         "body"         => $rawRelease['BODY'][0]['data'],
	             "full"         => $rawRelease['BODY'][0]['data'] . "<br /><br />" . $rawRelease['CORPORATEIDENTITY'][0]['data'],
		         "corporateidentity" => $rawRelease['CORPORATEIDENTITY'][0]['data']
		         );
}


/* prmac_getReleaseDetailByID - Same as prmac_getReleaseDetail, but takes a prMac release ID instead of a full URL
*/
function prmac_getReleaseDetailByID($releaseID, $agencyCode = "")
{
	$url = str_replace("%release_id%", $releaseID, PRMAC_RELEASEURL);

	return prmac_getReleaseDetail($url, $agencyCode);
}

/* prmac_set_rel_canonical_link_for_post - Set custom cannonical link for prmac */
function prmac_set_rel_canonical_link_for_post()
{
    if( !is_singular() || get_option('prmac_use_automated_import') == 'false' || get_option('prmac_post_closed') == 'true')
        return;

    global $wp_the_query;
    if( !$id = $wp_the_query->get_queried_object_id() )
        return;

    // check whether the current post has release id in the content
    $content = get_post_field('post_content', $id );
    if( '' != $content )
    {
      $dom = new DOMDocument();
      $dom->strictErrorChecking = false;
      @$dom->loadHTML($content);
      $links = $dom->getElementsByTagName('a');
      foreach($links as $link_tag) {
         if ($link_tag->hasAttribute('data-pr-ping-path')) {
            $ping_path = $link_tag->getAttribute('data-pr-ping-path');
            $value = explode("&", $ping_path);
            if (is_array($value)) {
              $release_id = str_replace("release=", "", $value[1]);
              $link = "https://prmac.com/release-id-".$release_id.".htm";
              break;
            }
            else
              $link = "";
         }
      }
    }
    else
    {
        $link = "";
    }

    if ($link == "")
        return;

    echo "<link rel='canonical' href='" . esc_url( $link ) . "' title='This content piece was originally published on prMac.com' />\n";
}

function prmac_getLastError()
{
	global $_prmac_lastError;
	return $_prmac_lastError;
}



/*******************************************************************
* Internal routines
*
* You shouldn't need to use anything beyond this point
*******************************************************************/

// Constants
define("PRMAC_LISTURL", "https://prmac.com/agency/xml_feed2.php?ac=%agency_code%&count=%count%&all=%all%&dm=%dm%&pn=Wordpress");
define("PRMAC_RELEASEURL", "https://prmac.com/release.php?id=%release_id%&redir=0&xml=1");

$_prmac_cacheDir = "prmac_cache";
$_prmac_enableCache = true;
$_prmac_cacheExpire = 6*60*60;
$_prmac_maxCacheEntries = 20;
$_prmac_autoGarbageCollect = true;

$_prmac_lastError = "";

function prmacp_fetchContent($url)
{
	global $_prmac_enableCache, $_prmac_cacheExpire, $_prmac_autoGarbageCollect;

	$cacheHash = md5($url);

	// Check the cache first
	if ($_prmac_enableCache)
	{
		if ($latestEntry = prmacp_findNewestCacheFile($cacheHash))
		{
			if (time() - $latestEntry[1] > $_prmac_cacheExpire)
				prmacp_clearEntriesForHash($cacheHash);
			else
				return file_get_contents(prmacp_cacheFileName($latestEntry));
		}
	}

  global $prmac_framework_version;
	global $prmac_wp_plugin_version;
	global $wp_version;
	global $stalone_version;

	$ua = "prMac Framework/{$prmac_framework_version}";
	if (!empty($prmac_wp_plugin_version)) {
		$ua .= " prMac WP Plugin/{$prmac_wp_plugin_version}";
  	if (!empty($wp_version)) {
    	$ua .= " WordPress/{$wp_version}";
  	}
  }
  elseif (!empty($stalone_version)) {
    $ua .= " prMac Standalone/{$stalone_version}";
  }
  else {
    $ua .= " (Cannot determine parent system)";
  }

	if (ini_get('allow_url_fopen')) {
    $opts = array(
    	'http' => array(
      	'header' => "User-Agent: {$ua}\r\n",
    	)
    );
    $context = stream_context_create($opts);
		$data = file_get_contents($url, FALSE, $context);
  }
	else if (is_callable("curl_init"))
	{
		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
		$data = curl_exec($ch);
		curl_close($ch);
	}
	else
	{
		exit("No method to fetch the XML from prMac. Either enable fopen wrappers (see the php docs for 'allow_url_fopen') or enable libcurl.");
	}

	if ($_prmac_enableCache)
	{
		$newEntry = array($cacheHash, time());
		$fh = fopen(prmacp_cacheFileName($newEntry), "w");
		flock($fh, LOCK_EX);
		fwrite($fh, $data);
		fclose($fh);
		if ($_prmac_autoGarbageCollect && (rand(1, 50) == 1))
			prmacp_garbageCollectCache();
	}

	return $data;
}

function prmacp_findNewestCacheFile($hash)
{
	global $_prmac_cacheDir;

	$dh = opendir($_prmac_cacheDir);
	while (($file = readdir($dh)) !== false)
	{
		if (strpos($file, $hash) === 0)
			return explode("-", $file);
	}
	return false;
}

function prmacp_clearEntriesForHash($hash)
{
	while ($entry = prmacp_findNewestCacheFile($hash))
		unlink(prmacp_cacheFileName($entry));
}

function prmacp_cacheFileName($entry)
{
	global $_prmac_cacheDir;
	return $_prmac_cacheDir . "/" . join("-", $entry);
}

function prmacp_garbageCollectCache()
{
	global $_prmac_cacheDir, $_prmac_maxCacheEntries;

	$cacheEntries = array();
	$dh = opendir($_prmac_cacheDir);
	while (($file = readdir($dh)) !== false)
	{
		if (preg_match("/[a-z0-9]*-[0-9]*/", $file))
			$cacheEntries[] = explode("-", $file);
	}

	usort($cacheEntries, "prmacp_compareCacheEntry");

	while (count($cacheEntries) > $_prmac_maxCacheEntries)
	{
		unlink(prmacp_cacheFileName(array_shift($cacheEntries)));
	}
}

function prmacp_compareCacheEntry($a, $b)
{
    if ($a[1] == $b[1])
        return 0;

    return ($a[1] < $b[1]) ? -1 : 1;
}

function prmacp_setLastError($err)
{
	global $_prmac_lastError;
	$_prmac_lastError = $err;
}

class PMXMLParser
{
    var $parser;
    var $document;
    var $currTag;
    var $tagStack;
	var $data;

    function __construct($xmlContent)
    {
		$this->parser = xml_parser_create();
		$this->data = $xmlContent;
		$this->document = array();
		$this->currTag =& $this->document;
		$this->tagStack = array();
		$this->parse();
    }

    function parse()
    {
        xml_set_object($this->parser, $this);
        xml_set_character_data_handler($this->parser, 'dataHandler');
        xml_set_element_handler($this->parser, 'startHandler', 'endHandler');

        if(!xml_parse($this->parser, $this->data))
        {
            die(sprintf("XML error: %s at line %d. " /*. "\n\nXML content:%s"*/,
                        xml_error_string(xml_get_error_code($this->parser)),
                        xml_get_current_line_number($this->parser)/*,
						$this->data*/));
        }

    	xml_parser_free($this->parser);

        return true;
    }

    function startHandler($parser, $name, $attribs)
    {
        if(!isset($this->currTag[$name]))
            $this->currTag[$name] = array();

        $newTag = array();
        if(!empty($attribs))
            $newTag['attr'] = $attribs;
        array_push($this->currTag[$name], $newTag);

        $t =& $this->currTag[$name];
        $this->currTag =& $t[count($t)-1];
        array_push($this->tagStack, $name);
    }

    function dataHandler($parser, $data)
    {
        if(!empty($data))
        {
            if(isset($this->currTag['data']))
                $this->currTag['data'] .= $data;
            else
                $this->currTag['data'] = $data;
        }
    }

    function endHandler($parser, $name)
    {
        $this->currTag =& $this->document;
        array_pop($this->tagStack);

        for($i = 0; $i < count($this->tagStack); $i++)
        {
            $t =& $this->currTag[$this->tagStack[$i]];
            $this->currTag =& $t[count($t)-1];
        }
    }
}


?>
