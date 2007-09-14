<?php


/**
 * SimpleSAMLphp
 *
 * PHP versions 4 and 5
 *
 * LICENSE: See the COPYING file included in this distribution.
 *
 * @author Andreas �kre Solberg, UNINETT AS. <andreas.solberg@uninett.no>
 */
 
require_once('SimpleSAML/Configuration.php');
require_once('SimpleSAML/XML/MetaDataStore.php');
 
/**
 * Configuration of SimpleSAMLphp
 */
class SimpleSAML_XML_Shib13_AuthnRequest {

	private $configuration = null;
	private $metadata = null;
	
	private $issuer = null;
	private $relayState = null;
	
	private $requestid = null;
	
	
	const PROTOCOL = 'shibboleth';


	function __construct(SimpleSAML_Configuration $configuration, SimpleSAML_XML_MetaDataStore $metadatastore) {
		$this->configuration = $configuration;
		$this->metadata = $metadatastore;
	}
	
	public function setRelayState($relayState) {
		$this->relayState = $relayState;
	}
	
	public function getRelayState() {
		return $this->relayState;
	}
	
	public function setIssuer($issuer) {
		$this->issuer = $issuer;
	}
	public function getIssuer() {
		return $this->issuer;
	}
	


	public function parseGet($get) {
		return null;
	}
	
	public function setNewRequestID() {	
		$this->requestid = $this->generateID();
	}
	
	public function getRequestID() {
		return $this->requestid;
	}
	
	public function createSession() {
		
		$session = SimpleSAML_Session::getInstance();
		
		if (!isset($session)) {
			SimpleSAML_Session::init(self::PROTOCOL);
			$session = SimpleSAML_Session::getInstance();
		}

		$session->setAuthnRequest($this->getRequestID(), $this);
		
		/*
		if (isset($this->relayState)) {
			$session->setRelayState($this->relayState);
		}
		*/
		return $session;
	}
	
	public function createRedirect($destination) {
		$idpmetadata = $this->metadata->getMetaData($destination, 'shib13-idp-remote');
		$spmetadata = $this->metadata->getMetaData($this->getIssuer(), 'shib13-sp-hosted');
	
		$desturl = $idpmetadata['SingleSignOnUrl'];
		$shire = $spmetadata['AssertionConsumerService'];
		$target = $this->getRelayState();
		
		$url = $desturl . '?' .
	    	'providerId=' . urlencode($this->getIssuer()) .
		    '&shire=' . urlencode($shire) .
		    (isset($target) ? '&target=' . urlencode($target) : '');
		return $url;
	}
	
	public static function generateID() {
		$length = 42;
		$key = "_";
		for ( $i=0; $i < $length; $i++ ) {
			 $key .= dechex( rand(0,15) );
		}
		return $key;
	}
	
	
}

?>