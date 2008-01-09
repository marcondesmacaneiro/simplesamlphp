<?php

/*
 * This file is part of simpleSAMLphp. See the file COPYING in the
 * root of the distribution for licence information.
 *
 * This file will help doing XPath queries in SAML 2 XML documents.
 */


/**
 * Configuration of SimpleSAMLphp
 *
 * This class should be extending SimpleXMLElement, but it is not because of some bugs:
 * http://bugs.php.net/bug.php?id=32188&edit=1
 */
class SimpleSAML_XML_Parser  {

	var $simplexml = null;
	
	function __construct($xml) {
		#parent::construct($xml);
		$this->simplexml = new SimpleXMLElement($xml);
		
		$this->simplexml->registerXPathNamespace('saml2',     'urn:oasis:names:tc:SAML:2.0:assertion');
		$this->simplexml->registerXPathNamespace('saml2meta', 'urn:oasis:names:tc:SAML:2.0:metadata');
		$this->simplexml->registerXPathNamespace('ds',        'http://www.w3.org/2000/09/xmldsig#');
		
	}
	
	public static function fromSimpleXMLElement(SimpleXMLElement $element) {
		
		// Traverse all existing namespaces in element.
		$namespaces = $element->getNamespaces();
		foreach ($namespaces AS $prefix => $ns) {
			$element[(($prefix === '') ? 'xmlns' : 'xmlns:' . $prefix)] = $ns;
		}
		
		/* Create a new parser with the xml document where the namespace definitions
		 * are added.
		 */
		$parser = new SimpleSAML_XML_Parser($element->asXML());
		return $parser;
		
	}
	
	public function getValue($xpath, $required = false) {
		
		$result = $this->simplexml->xpath($xpath);
		if (! $result or !is_array($result)) {
			if ($required) throw new Exception('Could not get value from XML document using the following XPath expression: ' . $xpath);
				else return null;
		}
		return (string) $result[0];
	}
	
	public function getValueAlternatives(array $xpath, $required = false) {
		foreach ($xpath AS $x) {
			$seek = $this->getValue($x);
			if ($seek) return $seek;
		}
		if ($required) throw new Exception('Could not get value from XML document using multiple alternative XPath expressions.');
			else return null;
	}
	
}

?>