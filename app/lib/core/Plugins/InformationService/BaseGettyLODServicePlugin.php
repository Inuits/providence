<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Plugins/InformationService/uBio.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2015 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage InformationService
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

  /**
    *
    */ 
    
    
require_once(__CA_LIB_DIR__."/core/Plugins/IWLPlugInformationService.php");
require_once(__CA_LIB_DIR__."/core/Plugins/InformationService/BaseInformationServicePlugin.php");

abstract class BaseGettyLODServicePlugin extends BaseInformationServicePlugin {
	# ------------------------------------------------
	protected $opo_linked_data_conf = null;
	# ------------------------------------------------
	public function __construct() {
		parent::__construct(); // sets app.conf

		$this->opo_linked_data_conf = Configuration::load($this->opo_config->get('linked_data_config'));
	}
	# ------------------------------------------------
	abstract protected function getConfigName();
	# ------------------------------------------------
	/** 
	 * Perform lookup on Getty linked open data service
	 *
	 * @param string $ps_query The sparql query
	 * @return array The decoded JSON result
	 */
	public function queryGetty($ps_query) {
		$o_curl=curl_init();
		curl_setopt($o_curl, CURLOPT_URL, "http://vocab.getty.edu/sparql.json?query={$ps_query}");
		curl_setopt($o_curl, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($o_curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($o_curl, CURLOPT_USERAGENT, 'CollectiveAccess web service lookup');
		$vs_result = curl_exec($o_curl);
		curl_close($o_curl);

		if(!$vs_result) {
			return false;
		}

		$va_result = json_decode($vs_result, true);
		if(!isset($va_result['results']['bindings']) || !is_array($va_result['results']['bindings'])) {
			return false;
		}

		return $va_result['results']['bindings'];
	}
	# ------------------------------------------------
	/** 
	 * Fetch details about a specific item from getty data service
	 *
	 * @param array $pa_settings Plugin settings values
	 * @param string $ps_url The URL originally returned by the data service uniquely identifying the item
	 * @return array An array of data from the data server defining the item.
	 */
	public function getExtendedInformation($pa_settings, $ps_url) {
		$va_service_conf = $this->opo_linked_data_conf->get($this->getConfigName());
		if(!$va_service_conf || !is_array($va_service_conf)) { return array('display' => ''); }
		if(!isset($va_service_conf['detail_view_info']) || !is_array($va_service_conf['detail_view_info'])) { return array('display' => ''); }

		$vs_display = '<div style="margin-top:10px; margin-bottom: 10px;"><a target="_blank" href="'.$ps_url.'">'.$ps_url.'</a></div>';
		foreach($va_service_conf['detail_view_info'] as $va_node) {
			if(!isset($va_node['literal'])) { continue; }

			$vs_uri_for_pull = isset($va_node['uri']) ? $va_node['uri'] : null;

			$vs_display .= "<div class='formLabel'>";
			$vs_display .= isset($va_node['label']) ? $va_node['label'].": " : "";
			$vs_display .= "<span class='formLabelPlain'>".self::getLiteralFromRDFNode($ps_url, $va_node['literal'], $vs_uri_for_pull, $va_node)."</span>";
			$vs_display .= "</div>\n";
		}

		return array('display' => $vs_display);
	}

	/**
	 * Get extra values for search indexing. This is called once when the attribute is saved.
	 * @param array $pa_settings
	 * @param string $ps_url
	 * @return array
	 */
	public function getExtraValuesForSearchIndexing($pa_settings, $ps_url) {
		$va_service_conf = $this->opo_linked_data_conf->get($this->getConfigName());
		if(!$va_service_conf || !is_array($va_service_conf)) { return array(); }
		if(!isset($va_service_conf['additional_indexing_info']) || !is_array($va_service_conf['additional_indexing_info'])) { return array(); }

		$va_return = array();
		foreach($va_service_conf['additional_indexing_info'] as $va_node) {
			if(!isset($va_node['literal'])) { continue; }

			$vs_uri_for_pull = isset($va_node['uri']) ? $va_node['uri'] : null;
			$va_return[] = str_replace('; ', ' ', self::getLiteralFromRDFNode($ps_url, $va_node['literal'], $vs_uri_for_pull));
		}

		return $va_return;
	}
	# ------------------------------------------------
	// HELPERS
	# ------------------------------------------------
	/**
	 * Fetches a literal property string value from given node
	 * @param string $ps_base_node
	 * @param string $ps_literal_propery EasyRdf property definition
	 * @param string|null $ps_node_uri Optional related node URI to pull from
	 * @param array $pa_options Available options are
	 * 			limit -> limit number of processed related notes, defaults to 10
	 * 			stripAfterLastComma -> strip everything after (and including) the last comma in the individual literal string
	 * 				this is useful for gvp:parentString where the top-most category is usually not very useful
	 * @return string|bool
	 */
	static function getLiteralFromRDFNode($ps_base_node, $ps_literal_propery, $ps_node_uri=null, $pa_options) {
		if(!isURL($ps_base_node)) { return false; }

		$pn_limit = (int) caGetOption('limit', $pa_options, 10);
		$pb_strip_after_last_comma = (bool) caGetOption('stripAfterLastComma', $pa_options, false);

		if(!($o_graph = self::getURIAsRDFGraph($ps_base_node))) { return false; }

		$va_pull_graphs = array();
		if(strlen($ps_node_uri) > 0) {
			$o_related_nodes = $o_graph->all($ps_base_node, $ps_node_uri);
			if(is_array($o_related_nodes)) {
				$vn_i = 0;
				foreach($o_related_nodes as $o_related_node) {
					$vs_pull_uri = (string) $o_related_node;
					if(!($o_pull_graph = self::getURIAsRDFGraph($vs_pull_uri))) { return false; }
					$va_pull_graphs[$vs_pull_uri] = $o_pull_graph;

					if((++$vn_i) >= $pn_limit) { break; }
				}
			}
		} else {
			$va_pull_graphs[$ps_base_node] = $o_graph;
		}

		$va_return = array();

		$vn_j = 0;
		foreach($va_pull_graphs as $vs_uri => $o_g) {
			$va_literals = $o_g->all($vs_uri, $ps_literal_propery);

			foreach($va_literals as $o_literal) {
				if($o_literal instanceof EasyRdf_Literal) {
					$vs_string_to_add = htmlentities($o_literal->getValue());
				} else {
					$vs_string_to_add = (string) $o_literal;
				}

				if($pb_strip_after_last_comma) {
					$vn_last_comma_pos = strrpos($vs_string_to_add, ',');
					$vs_string_to_add = substr($vs_string_to_add, 0, ($vn_last_comma_pos - strlen($vs_string_to_add)));
				}

				// make links click-able
				if(isURL($vs_string_to_add)) {
					$vs_string_to_add = "<a href='{$vs_string_to_add}' target='_blank'>{$vs_string_to_add}</a>";
				}

				$va_return[] = $vs_string_to_add;
				if((++$vn_j) >= $pn_limit) { break; }
			}
		}

		return join('; ', $va_return);
	}
	# ------------------------------------------------
	/**
	 * Try to load a given URI as RDF Graph
	 * @param string $ps_uri
	 * @return bool|EasyRdf_Graph
	 */
	static function getURIAsRDFGraph($ps_uri) {
		if(!$ps_uri) { return false; }

		if(MemoryCache::contains($ps_uri, 'GettyLinkedDataRDFGraphs')) {
			return MemoryCache::fetch($ps_uri, 'GettyLinkedDataRDFGraphs');
		}

		try {
			$o_graph = new EasyRdf_Graph("http://vocab.getty.edu/download/rdf?uri={$ps_uri}.rdf");
			$o_graph->load();
		} catch(Exception $e) {
			return false;
		}

		MemoryCache::save($ps_uri, $o_graph, 'GettyLinkedDataRDFGraphs');
		return $o_graph;
	}
	# ------------------------------------------------
}
