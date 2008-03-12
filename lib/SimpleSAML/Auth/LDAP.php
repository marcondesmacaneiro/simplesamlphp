<?php

require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Configuration.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Utilities.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Logger.php');

/**
 * The LDAP class holds helper functions to access an LDAP database.
 *
 * @author Andreas �kre Solberg, UNINETT AS. <andreas.solberg@uninett.no>
 * @author Anders Lund, UNINETT AS. <anders.lund@uninett.no>
 * @package simpleSAMLphp
 * @version $Id: Session.php 244 2008-02-04 08:36:24Z andreassolberg $
 */
class SimpleSAML_Auth_LDAP {


	/**
	 * LDAP link
	 */
	private $ldap = null;
	
	/**
	 * private constructor restricts instantiaton to getInstance()
	 */
	public function __construct($hostname,$enable_tls=true) {

		$this->ldap = @ldap_connect($hostname);
		if (empty($this->ldap)) 
			throw new Exception('Could not connect to LDAP server. Please try again, and if the problem persists, please report the error.');

        if (!preg_match("/ldaps:/i",$hostname) and $enable_tls) {
            if (!ldap_start_tls($this->ldap)) {
                throw new Exception('Could not force LDAP into TLS-session. Please verify certificates and configuration');
            }
        }
		$this->setV3();

	}
	
	/**
	 * Set LDAP version 3 option on the connection handler. Will throw an error if not possible.
	 */
	private function setV3() {
		// Give error if LDAPv3 is not supported
		if (!@ldap_set_option($this->ldap, LDAP_OPT_PROTOCOL_VERSION, 3)) 
			throw new Exception('Failed to set LDAP Protocol version to 3: ' . ldap_error($this->ldap) );
	}
	
	/**
	 * Search for a DN. You specify an attribute name and an attribute value
	 * and the function will return the DN of the result of the search.
	 */
	public function searchfordn($searchbase, $searchattr, $searchvalue) {
	
		SimpleSAML_Logger::debug('Library - LDAP: Search for DN (base:' . 
			$searchbase . ' attr:' . $searchattr . ' value:' . $searchvalue . ')');

		// Search for ePPN
		$search = '(' . $searchattr . '=' . $searchvalue. ')';
		$search_result = @ldap_search($this->ldap, $searchbase, $search);

		if ($search_result === false) {
			throw new Exception('Failed performing a LDAP search: ' . ldap_error($this->ldap) . ' search:' . $search);
		}

		// Check number of entries. ePPN should be unique!
		if (@ldap_count_entries($this->ldap, $search_result) > 1 ) 
			throw new Exception("Found multiple entries in LDAP search: " . $search . ' base: ' . $searchbase);
		
		if (@ldap_count_entries($this->ldap, $search_result) == 0) 
			throw new Exception('LDAP search returned zero entries: ' . $search . ' base: ' . $searchbase);
		
		// Authenticate user and fetch attributes
		$entry = ldap_first_entry($this->ldap, $search_result);
		
		if (empty($entry))
			throw new Exception('Could not retrieve result of LDAP search: ' . $search);
		
		$dn = @ldap_get_dn($this->ldap, $entry);
		
		if (empty($dn))
			throw new Exception('Error retrieving DN from search result.');
			
		return $dn;

	}
	
	/**
	 * Bind to LDAP with a specific DN and password.
	 */
	public function bind($dn, $password) {
		if (@ldap_bind($this->ldap, $dn, $password)) {
			SimpleSAML_Logger::debug('Library - LDAP: Bind successfull with ' . $dn);
			return true;
		}
		SimpleSAML_Logger::debug('Library - LDAP: Bind failed with ' . $dn);
		return false;
	}


	/**
	 * Search DN for attributes, and return associative array.
	 */
	public function getAttributes($dn, $attributes) {
	
		$searchtxt = (is_array($attributes) ? join(',', $attributes) : 'all attributes');
		SimpleSAML_Logger::debug('Library - LDAP: Get attributes from ' . $dn . ' (' . $searchtxt . ')');
		
		if (is_array($attributes)) 
			$sr = @ldap_read($this->ldap, $dn, 'objectClass=*', $attributes );
		else 
			$sr = @ldap_read($this->ldap, $dn, 'objectClass=*');
			
		if ($sr === false) 
			throw new Exception('Could not retrieve attributes for user: ' . ldap_error($this->ldap));
		
		$ldapentries = @ldap_get_entries($this->ldap, $sr);
		
		if ($ldapentries === false)
			throw new Exception('Could not retrieve results from attribute retrieval for user:' . ldap_error($this->ldap));
		
		
		$attributes = array();
		for ($i = 0; $i < $ldapentries[0]['count']; $i++) {
			$values = array();
			if ($ldapentries[0][$i] == 'jpegphoto') continue;
			for ($j = 0; $j < $ldapentries[0][$ldapentries[0][$i]]['count']; $j++) {
				$values[] = $ldapentries[0][$ldapentries[0][$i]][$j];
			}
			
			$attributes[$ldapentries[0][$i]] = $values;
		}
		
		SimpleSAML_Logger::debug('Library - LDAP: Found attributes (' . join(',', array_keys($attributes)) . ')');
		return $attributes;
	
	}


}

?>
