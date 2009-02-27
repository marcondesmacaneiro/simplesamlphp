<?php


$config = SimpleSAML_Configuration::getInstance();
$session = SimpleSAML_Session::getInstance();

$isAdmin = FALSE;
$secretURL = NULL;
if (array_key_exists('orgtest', $_REQUEST)) {
	$secretKey = sha1('ldapstatus|' . $config->getValue('secret') . '|' . $_REQUEST['orgtest']);
	$secretURL = SimpleSAML_Utilities::addURLparameter(
		SimpleSAML_Utilities::selfURLNoQuery(), array(
			'orgtest' => $_REQUEST['orgtest'],
			'key' => $secretKey,
		)
	);
	if (array_key_exists('key', $_REQUEST) && $_REQUEST['key'] == $secretKey ) {
		// OK Access
	} else {
		if (!$session->isValid('login-admin') ) {
			SimpleSAML_Utilities::redirect('/' . $config->getBaseURL() . 'auth/login-admin.php',
				array('RelayState' => SimpleSAML_Utilities::selfURL())
			);
		}
		$isAdmin = TRUE;
	}

} else {

	// Require admin access to overview page...
	if (!$session->isValid('login-admin') ) {
		SimpleSAML_Utilities::redirect('/' . $config->getBaseURL() . 'auth/login-admin.php',
			array('RelayState' => SimpleSAML_Utilities::selfURL())
		);
	}
	$isAdmin = TRUE;

}



function backtrace() {
	return join(' - ', debug_backtrace());
}

function myErrorHandler($errno, $errstr, $errfile, $errline) {


	echo('<div style="border: 1px dotted #ccc; margin: .3em; padding: .4em;">');
	switch ($errno) {
		case E_USER_ERROR:
			echo('<p>PHP_ERROR   : [' . $errno . '] ' . $errstr . '. Fatal error on line ' . $errline . ' in file ' . $errfile);
			break;
	
		case E_USER_WARNING:
			echo('<p>PHP_WARNING : [' . $errno . '] ' . $errstr . '. Warning on line ' . $errline . ' in file ' . $errfile);
			break;
	
		case E_USER_NOTICE:
			echo('<p>PHP_WARNING : [' . $errno . '] ' . $errstr . '. Warning on line ' . $errline . ' in file ' . $errfile);        
			break;
	
		default:
			echo('<p>PHP_UNKNOWN : [' . $errno . '] ' . $errstr . '. Unknown error on line ' . $errline . ' in file ' . $errfile);        
			break;
    }
    
#    echo('<div style="font-style:monospace; font-size: x-small; margin: 1em; color: #966"><li>' . join('</li><li>', debug_backtrace()) . '</li></div>');
    echo('<pre style="font-style:monospace; font-size: small; margin: 1em; color: #966">');
    echo(debug_print_backtrace()); 
    echo('</pre>');
	echo('</div>');

    
    flush();

    /* Don't execute PHP internal error handler */
    return true;
}




$ldapconfig = SimpleSAML_Configuration::getConfig('config-login-feide.php');
$ldapStatusConfig = SimpleSAML_Configuration::getConfig('module_ldapstatus.php');

$debug = $ldapconfig->getValue('ldapDebug', FALSE);
$orgs = $ldapconfig->getValue('organizations');
$locationTemplate = $ldapconfig->getValue('locationTemplate');



$results = $session->getData('module:ldapstatus', 'results');
if (empty($results)) {
	$results = array();
} elseif (array_key_exists('reset', $_GET) && $_GET['reset'] === '1') {
	$results = array();
}

#echo('<pre>'); print_r($results); exit;


$start = microtime(TRUE);
$previous = microtime(TRUE);

$maxtime = $ldapStatusConfig->getValue('maxExecutionTime', 15); 


if (array_key_exists('orgtest', $_REQUEST)) {
	#$old_error_handler = set_error_handler("myErrorHandler");
	
	$locindex = 0;
	if (array_key_exists('locindex', $_REQUEST)) $locindex = $_REQUEST['locindex'];
	
	$orgconfig = SimpleSAML_Configuration::loadFromArray($orgs[$_REQUEST['orgtest']], 'org:[' . $_REQUEST['orgtest'] . ']');
	$orgloc = $orgs[$_REQUEST['orgtest']]['locations'][$locindex];
	$orgloc = mergeWithTemplate($orgloc, $locationTemplate);
	$classname = SimpleSAML_Module::resolveClass($orgloc['testType'], 'Auth_Backend_Test');
	$tester = new $classname(
			SimpleSAML_Configuration::loadFromArray($orgloc, 'Location@[' . $_REQUEST['orgtest'] . ']'),
			$orgconfig);
			
	$res = $tester->test();

	$t = new SimpleSAML_XHTML_Template($config, 'ldapstatus:ldapsinglehost.php');
	
	$t->data['res'] = $res;
	$t->data['org'] = $orgs[$_REQUEST['orgtest']];
	if ($isAdmin) $t->data['secretURL'] = $secretURL;
	$t->show();
	exit;
}

function mergeWithTemplate($location, $template) {
	foreach($template AS $key => $value) {
		if (!array_key_exists($key, $location)) $location[$key] = $value;
	}
	return $location;
}

$start = microtime(TRUE);
foreach($orgs AS $orgkey => $org) {
	if (array_key_exists($orgkey, $results)) continue;
	$orgconfig = SimpleSAML_Configuration::loadFromArray($org, 'org:[' . $orgkey . ']');
	$orglocs = $org['locations'];
	$results[$orgkey] = array();
	foreach($orglocs AS $orgloc) {
		$orgloc = mergeWithTemplate($orgloc, $locationTemplate);
		$classname = SimpleSAML_Module::resolveClass($orgloc['testType'], 'Auth_Backend_Test');
		$tester = new $classname(
			SimpleSAML_Configuration::loadFromArray($orgloc, 'Location@[' . $orgkey . ']'),
			$orgconfig);
		$results[$orgkey][] = $tester->test();
	}
	if ((microtime(TRUE) - $start) > $maxtime) {
		SimpleSAML_Logger::debug('ldapstatus: Completing execution after maxtime [' .(microtime(TRUE) - $start) . ' of maxtime ' . $maxtime . ']');
		break;
	}
	
}



$session->setData('module:ldapstatus', 'results', $results);

#echo '<pre>'; print_r($results); exit;

$lightCounter = array(0,0,0);

function resultCode($res) {
	global $lightCounter;
	$code = '';
	$columns = array('configMeta', 'config', 'ping', 'adminBind', 'ldapSearchBogus', 'configTest', 'ldapSearchTestUser', 'ldapBindTestUser', 'getTestOrg', );
	foreach ($columns AS $c) {
		if (array_key_exists($c, $res)) {
			if ($res[$c][0]) {
				$code .= '0';
				$lightCounter[0]++;
			} else {
				$code .= '2';
				$lightCounter[2]++;
			}
			
		} else {
			$code .= '0';
			$lightCounter[1]++;
		}
	}
	return $code;
}
	
	
$ressortable = array();
foreach ($results AS $key => $res) {
	$ressortable[$key] = resultCode($res[0]);
}
asort($ressortable);
#echo '<pre>'; print_r($ressortable); exit;


$t = new SimpleSAML_XHTML_Template($config, 'ldapstatus:ldapstatus.php');

$t->data['showcomments'] = array_key_exists('showcomments', $_REQUEST);
$t->data['completeNo'] = count($results);
$t->data['completeOf'] = count($orgs);
$t->data['results'] = $results;
$t->data['orgconfig'] = $orgs;
$t->data['lightCounter'] = $lightCounter;
$t->data['sortedOrgIndex'] = array_keys($ressortable);
$t->show();
exit;

?>
