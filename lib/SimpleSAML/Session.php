<?php

require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Configuration.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Utilities.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/SessionHandler.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Logger.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/ModifiedInfo.php');

/**
 * The Session class holds information about a user session, and everything attached to it.
 *
 * The session will have a duration, and validity, and also cache information about the different
 * federation protocols, as Shibboleth and SAML 2.0. On the IdP side the Session class holds 
 * information about all the currently logged in SPs. This is used when the user initiate a 
 * Single-Log-Out.
 *
 * @author Andreas �kre Solberg, UNINETT AS. <andreas.solberg@uninett.no>
 * @package simpleSAMLphp
 * @version $Id$
 */
class SimpleSAML_Session implements SimpleSAML_ModifiedInfo {

	const STATE_ONLINE = 1;
	const STATE_LOGOUTINPROGRESS = 2;
	const STATE_LOGGEDOUT = 3;

	/**
	 * This variable holds the instance of the session - Singleton approach.
	 */
	private static $instance = null;
	
	/**
	 * The track id is a new random unique identifier that is generate for each session.
	 * This is used in the debug logs and error messages to easily track more information
	 * about what went wrong.
	 */
	private $trackid = 0;
	
	/**
	 * The authentication requests are an array with cached information about the requests.
	 * This is mostly used at the Shib and SAML 2.0 IdP side, at the SSOService endpoint.
	 */
	private $authnrequests = array();
	private $logoutrequest = null;
	private $idp = null;
	
	private $authenticated = null;
	private $attributes = null;
	
	private $sessionindex = null;
	private $nameid = null;
	
	private $sp_at_idpsessions = array();
	
	private $authority = null;
	
	// Session duration parameters
	private $sessionstarted = null;
	private $sessionduration = null;
	
	// Track whether the session object is modified or not.
	private $dirty = false;
		

	/**
	 * This is an array of registered logout handlers.
	 * All registered logout handlers will be called on logout.
	 */
	private $logout_handlers = array();


	/**
	 * This is an array of objects which will autoexpire after a set time. It is used
	 * where one needs to store some information - for example a logout request, but doesn't
	 * want it to be stored forever.
	 *
	 * The data store contains three levels of nested associative arrays. The first is the data type, the
	 * second is the identifier, and the third contains the expire time of the data and the data itself.
	 */
	private $dataStore = null;


	/**
	 * private constructor restricts instantiaton to getInstance()
	 */
	private function __construct($authenticated = true) {

		$this->authenticated = $authenticated;
		if ($authenticated) {
			$this->sessionstarted = time();
		}
		
		$configuration = SimpleSAML_Configuration::getInstance();
		$this->sessionduration = $configuration->getValue('session.duration');
		
		$this->trackid = SimpleSAML_Utilities::generateTrackID();

	}


	/**
	 * This function is called after this class has been deserialized.
	 */
	public function __wakeup() {

	}
	
	
	public static function getInstance($allowcreate = false) {

		/* Check if we already have initialized the session. */
		if (isset(self::$instance)) {
			return self::$instance;
		}


		/* Check if we have stored a session stored with the session
		 * handler.
		 */
		$sh = SimpleSAML_SessionHandler::getSessionHandler();
		if($sh->get('SimpleSAMLphp_SESSION') !== NULL) {
			self::$instance = $sh->get('SimpleSAMLphp_SESSION');
			self::$instance->dirty = false;
			return self::$instance;
		}

		/* We don't have a session. Create one if allowed to. Return
		 * null if not.
		 */
		if ($allowcreate) {
			self::init();
			return self::$instance;
		} else {
			return null;
		}
	}
	
	
	
	
	public static function init($authenticated = false, $authority = null) {
		
		$preinstance = self::getInstance();
		
		if (isset($preinstance)) {
		
			$preinstance->clean();
			if (isset($authenticated)) $preinstance->setAuthenticated($authenticated, $authority);
			
		} else {	
			self::$instance = new SimpleSAML_Session($authenticated, $authority);

			/* Save the new session with the session handler. */
			$sh = SimpleSAML_SessionHandler::getSessionHandler();
			$sh->set('SimpleSAMLphp_SESSION', self::$instance);
		}
	}
	
	
	
	
	
	/**
	 * Get a unique ID that will be permanent for this session.
	 * Used for debugging and tracing log files related to a session.
	 */
	public function getTrackID() {
		return $this->trackid;
	}
	
	/**
	 * Who authorized this session. could be in example saml2, shib13, login,login-admin etc.
	 */
	public function getAuthority() {
		return $this->authority;
	}
	
	
	
	// *** SP list to be used with SAML 2.0 SLO ***
	// *** *** *** *** *** *** *** *** *** *** ***
	
	public function add_sp_session($entityid) {
		SimpleSAML_Logger::debug('Library - Session: Adding SP session: ' . $entityid);
		$this->sp_at_idpsessions[$entityid] = self::STATE_ONLINE;
	}
	
	public function get_next_sp_logout() {
		
		if (!$this->sp_at_idpsessions) return null;
		
		foreach ($this->sp_at_idpsessions AS $entityid => $sp) {
			if ($sp == self::STATE_ONLINE) {
				$this->sp_at_idpsessions[$entityid] = self::STATE_LOGOUTINPROGRESS;
				return $entityid;
			}
		}
		return null;
	}
	
	public function get_sp_list($state = self::STATE_ONLINE) {
		
		$list = array();
		if (!$this->sp_at_idpsessions) return $list;
		
		foreach ($this->sp_at_idpsessions AS $entityid => $sp) {
			if ($sp == $state) {
				$list[] = $entityid;
			}
		}
		return $list;
	}
	
	public function set_sp_logout_completed($entityid) {
		SimpleSAML_Logger::debug('Library - Session: Setting SP state completed for : ' . $entityid);
		$this->dirty = true;
		$this->sp_at_idpsessions[$entityid] = self::STATE_LOGGEDOUT;
	}
	
	
	public function dump_sp_sessions() {
		foreach ($this->sp_at_idpsessions AS $entityid => $sp) {
			SimpleSAML_Logger::debug('Dump sp sessions: ' . $entityid . ' status: ' . $sp);
		}
	}
	// *** --- ***


	
	
	/**
	 * This method retrieves from session a cache of a specific Authentication Request
	 * The complete request is not stored, instead the values that will be needed later
	 * are stored in an assoc array.
	 *
	 * @param $protocol 		saml2 or shib13
	 * @param $requestid 		The request id used as a key to lookup the cache.
	 *
	 * @return Returns an assoc array of cached variables associated with the
	 * authentication request.
	 */
	public function getAuthnRequest($protocol, $requestid) {


		SimpleSAML_Logger::debug('Library - Session: Get authnrequest from cache ' . $protocol . ' time:' . time() . '  id: '. $requestid );

		$configuration = SimpleSAML_Configuration::getInstance();
		if (isset($this->authnrequests[$protocol])) {
			/*
			 * Traverse all cached authentication requests in this session for this user using this protocol
			 */
			foreach ($this->authnrequests[$protocol] AS $id => $cache) {
				/*
				 * If any of the cached requests is elder than the session.requestcache duration, then just
				 * simply delete it :)
				 */
				if ($cache['date'] < time() - $configuration->getValue('session.requestcache', 4*(60*60)) ) {
					SimpleSAML_Logger::debug('Library - Session: Deleting expired authn request with id ' . $id);
					unset($this->authnrequests[$protocol][$id]);
				}
			}
		}
		/*
		 * Then look if the request id that was requested exists, if so return it.
		 */
		if (isset($this->authnrequests[$protocol][$requestid])) {
			return $this->authnrequests[$protocol][$requestid];
		}

		/*
		 * Could not find requested ID. Throw an error. Could be that it is never set, or that it is deleted due to age.
		 */
		throw new Exception('Could not find cached version of authentication request with ID ' . $requestid . ' (' . $protocol . ')');
	}
	
	/**
	 * This method sets a cached assoc array to the authentication request cache storage.
	 *
	 * @param $protocol 		saml2 or shib13
	 * @param $requestid 		The request id used as a key to lookup the cache.
	 * @param $cache			The assoc array that will be stored.
	 */
	public function setAuthnRequest($protocol, $requestid, array $cache) {
	
		SimpleSAML_Logger::debug('Library - Session: Set authnrequest ' . $protocol . ' time:' . time() . ' size:' . count($cache) . '  id: '. $requestid );

		$this->dirty = true;
		$cache['date'] = time();
		$this->authnrequests[$protocol][$requestid] = $cache;

	}
	



	public function setIdP($idp) {
	
		SimpleSAML_Logger::debug('Library - Session: Set IdP to : ' . $idp);
		$this->dirty = true;
		$this->idp = $idp;
	}
	public function getIdP() {
		return $this->idp;
	}
	
	
	
	
	
	public function setLogoutRequest($requestcache) {
		SimpleSAML_Logger::debug('Library - Session: Adding LogoutRequest cache.');
		$this->dirty = true;
		$this->logoutrequest = $requestcache;
	}
	
	public function getLogoutRequest() {
		return $this->logoutrequest;
	}
	
	
	
	

	public function setSessionIndex($sessionindex) {
		SimpleSAML_Logger::debug('Library - Session: Set sessionindex: ' . $sessionindex);
		$this->dirty = true;
		$this->sessionindex = $sessionindex;
	}
	public function getSessionIndex() {
		return $this->sessionindex;
	}
	public function setNameID($nameid) {
		SimpleSAML_Logger::debug('Library - Session: Set nameID: ');
		$this->dirty = true;
		$this->nameid = $nameid;
	}
	public function getNameID() {
		return $this->nameid;
	}

	public function setAuthenticated($auth, $authority = null) {
		
		SimpleSAML_Logger::debug('Library - Session: Set authenticated ' . ($auth ? 'yes': 'no'). ' authority:' . 
			(isset($authority) ? $authority : 'null'));
		$this->authority = $authority;
		$this->authenticated = $auth;
		$this->dirty = true;

		if ($auth) {	
			$this->clearNeedAuthFlag();
			$this->sessionstarted = time();
		} else {
			/* Call logout handlers. */
			$this->callLogoutHandlers();
		}
	}
	
	public function setSessionDuration($duration) {
		SimpleSAML_Logger::debug('Library - Session: Set session duration ' . $duration);
		$this->dirty = true;
		$this->sessionduration = $duration;
	}
	
	
	/*
	 * Is the session representing an authenticated user, and is the session still alive.
	 * This function will return false after the user has timed out.
	 */
	public function isValid($authority = null) {
		SimpleSAML_Logger::debug('Library - Session: Check if session is valid.' .
			' checkauthority:' . (isset($authority) ? $authority : 'null') . 
			' thisauthority:' . (isset($this->authority) ? $this->authority : 'null') .
			' isauthenticated:' . ($this->isAuthenticated() ? 'yes' : 'no') . 
			' remainingtime:' . $this->remainingTime());
			
		if (!$this->isAuthenticated()) return false;
		if (!empty($authority) && ($authority != $this->authority) ) return false;
		return $this->remainingTime() > 0;
	}
	
	/*
	 * If the user is authenticated, how much time is left of the session.
	 */
	public function remainingTime() {
		return $this->sessionduration - (time() - $this->sessionstarted);
	}

	/* 
	 * Is the user authenticated. This function does not check the session duration.
	 */
	public function isAuthenticated() {
		return $this->authenticated;
	}
	
	
	// *** Attributes ***
	
	public function getAttributes() {
		return $this->attributes;
	}

	public function getAttribute($name) {
		return $this->attributes[$name];
	}

	public function setAttributes($attributes) {
		$this->dirty = true;
		$this->attributes = $attributes;
	}
	
	public function setAttribute($name, $value) {
		$this->dirty = true;
		$this->attributes[$name] = $value;
	}
	
	/**
	 * Clean the session object.
	 */
	public function clean($cleancache = false) {
	
		SimpleSAML_Logger::debug('Library - Session: Cleaning Session. Clean cache: ' . ($cleancache ? 'yes' : 'no') );
	
		if ($cleancache) {
			$this->authnrequests = array();
			$this->logoutrequest = null;
			$this->idp = null;
		}
		
		$this->authority = null;
	
		$this->authenticated = null;
		$this->attributes = null;
	
		$this->sessionindex = null;
		$this->nameid = null;
	
		$this->sp_at_idpsessions = array();	
	}
	 
	/**
	 * Is this session modified since loaded?
	 */
	public function isModified() {
		return $this->dirty;
	}
	
	/**
	 * Calculates the size of the session object after serialization
	 *
	 * @return The size of the session measured in bytes.
	 */
	public function getSize() {
		$s = serialize($this);
		return strlen($s);
	}


	/**
	 * This function registers a logout handler.
	 *
	 * @param $file  The file which contains the logout handler.
	 * @param $classname  The class which contains the logout handler.
	 * @param $functionname  The logout handler function.
	 */
	public function registerLogoutHandler($file, $classname, $functionname) {
		$this->logout_handlers[] = array('file' => $file, 'class' => $classname, 'function' => $functionname);
	}


	/**
	 * This function calls all registered logout handlers.
	 */
	private function callLogoutHandlers() {
		foreach($this->logout_handlers as $handler) {

			/* Load the file with the logout handler. */
			require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . $handler['file']);

			/* Call the logout handler. */
			$classname = $handler['class'];
			$functionname = $handler['function'];
			call_user_func(array($classname, $functionname));
		}

		/* We require the logout handlers to register themselves again if they want to be called later. */
		$this->logout_handlers = array();
	}


	/**
	 * This function iterates over all current authentication requests, and removes any 'NeedAuthentication' flags
	 * from them.
	 */
	private function clearNeedAuthFlag() {
		foreach($this->authnrequests as &$cache) {
			foreach($cache as &$request) {
				if(array_key_exists('NeedAuthentication', $request)) {
					$request['NeedAuthentication'] = FALSE;
				}
			}
		}
	}


	/**
	 * This function removes expired data from the data store.
	 */
	private function expireData() {

		$ct = time();

		foreach($this->dataStore as &$typedData) {
			foreach($typedData as $id => $info) {
				if($ct > $info['expires']) {
					unset($typedData[$id]);
				}
			}
		}
	}


	/**
	 * This function stores data in the data store.
	 *
	 * @param $type     The type of the data. This is checked when retrieving data from the store.
	 * @param $id       The identifier of the data.
	 * @param $timeout  The number of seconds this data should be stored after its last access.
	 * @param $data     The data.
	 */
	public function setData($type, $id, $timeout, $data) {
		assert(is_string($type));
		assert(is_string($id));
		assert(is_int($timeout));

		/* Clean out old data. */
		$this->expireData();

		$dataInfo = array('expires' => time() + $timeout, 'timeout' => $timeout, 'data' => $data);

		if(!is_array($this->dataStore)) {
			$this->dataStore = array();
		}

		if(!array_key_exists($type, $this->dataStore)) {
			$this->dataStore[$type] = array();
		}

		$this->dataStore[$type][$id] = $dataInfo;

		$this->dirty = TRUE;
	}


	/**
	 * This function retrieves data from the data store.
	 *
	 * @param $type  The type of the data. This must match the type used when adding the data.
	 * @param $id    The identifier of the data.
	 * @return The data of the given type with the given id or NULL if the data doesn't exist in the data store.
	 */
	public function getData($type, $id) {
		assert(is_string($type));
		assert(is_string($id));

		$this->expireData();

		if(!is_array($this->dataStore)) {
			return NULL;
		}

		if(!array_key_exists($type, $this->dataStore)) {
			return NULL;
		}

		if(!array_key_exists($id, $this->dataStore[$type])) {
			return NULL;
		}

		$dataInfo =& $this->dataStore[$type][$id];
		$dataInfo['expires'] = time() + $dataInfo['timeout'];

		$this->dirty = TRUE;

		return $dataInfo['data'];
	}

}

?>