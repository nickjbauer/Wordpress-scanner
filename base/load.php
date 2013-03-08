<?php
/**
	A Wordpress Scanner
	Copyright (C) 2013  Ramadhan Amizudin

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
**/

defined('ROOT_PATH') or die();

require ROOT_PATH . '/base/helper.php';
require ROOT_PATH . '/base/requirement.php';
require ROOT_PATH . '/base/wp-version.php';
require ROOT_PATH . '/base/wp-vuln.php';
require ROOT_PATH . '/base/wp-plugin.php';

function banner() {
$text = "
 __    __              _                         
/ / /\ \ \___  _ __ __| |_ __  _ __ ___  ___ ___ 
\ \/  \/ / _ \| '__/ _` | '_ \| '__/ _ \/ __/ __|
 \  /\  / (_) | | | (_| | |_) | | |  __/\__ \__ \
  \/  \/ \___/|_|  \__,_| .__/|_|  \___||___/___/
                        |_|                      
                                    Scanner v".Version."\n";
echo $text;
}

class WPScan {

	var $url, $wp_path, $xmlrpc_path, $rss_path, $robots_path, $readme_path, $theme_name, $sdb_path;
	var $is_multisite, $registration_enabled;
	var $list_plugins = false;
	var $wp_content_path = 'wp-content';
	var $plugin_path = 'plugins';
	protected $homepage_sc;
	
	function __construct($host) {
		$this->url = $this->new_url( rtrim( $host, '/' ) );
	}
		
	function get_version() {
		$wpversion = new WPVersion($this->url);
		return $wpversion->get_version();
	}

	function is_wordpress() {
		$this->homepage_sc = HTTPRequest($this->url);
		if(preg_match('#wp-content#i', $this->homepage_sc)) {
			return true;
		} else {
			$resp = HTTPRequest($this->xmlrpc_path);
			if(preg_match('#XML-RPC server accepts POST requests only#i', $resp)) {
				return true;
			}
		}
		return false;
	}
	
	function search_plugins() {
		$data_path = ROOT_PATH . '/base/data/list-plugins.txt';
		$data = array_map('trim', file($data_path));
		preg_match_all("/wp-content\/plugins\/(.*?)\//i", $this->homepage_sc, $match);
		$plugins = array_unique($match[1]);
		$_plugins = array();
		foreach($plugins as $plugin) {
			if(in_array($plugin, $data)) {
				$_plugins[] = array('plugin_name' => $plugin,
								   'url' => 'http://wordpress.org/extend/plugins/'.$plugin.'/',
								   'svn' => 'http://svn.wp-plugins.org/' . $plugin . '/');
			} else {
				$_plugins[] = array('plugin_name' => $plugin);
			}
		}
		$this->list_plugins = $_plugins;
	}
	
	function parser() {
		preg_match('/x-pingback: (.+)/i', $this->homepage_sc, $xmlrpc);
		preg_match('#<link .* type="application/rss\+xml" .* href="([^"]+)" />#i', $this->homepage_sc, $rss);
		preg_match('#themes/(.*?)/style.css#i', $this->homepage_sc, $theme);
		$this->rss_path = (isset($rss[1])) ? trim($rss[1]) : false;
		$this->xmlrpc_path = (isset($xmlrpc[1])) ? trim($xmlrpc[1]) : false;
		$this->robots_path = (preg_match('/200 ok/i', HTTPRequest($this->url.'/robots.txt'))) ? $this->url.'/robots.txt' : false;
		$this->readme_path = (preg_match('/200 ok/i', HTTPRequest($this->url.'/readme.html'))) ? $this->url.'/readme.html' : false;
		$this->theme_name = (isset($theme[1])) ? trim($theme[1]) : false;
		$this->sdb_path = $this->__search_sdb();
		$this->__is_multisite();
		$this->__registration_enabled();
	}
	
	private function __registration_enabled() {
		$path = ($this->is_multisite) ? '/wp-signup.php' : '/wp-login.php?action=register';
		$response = HTTPRequest($this->url . $path, false, '', false);
		if(stripos($response, '302 Found')) {
			$this->registration_enabled = false;
		} elseif(preg_match('/<form id="setupform" method="post" action="[^"]*wp-signup\.php[^"]*">/i', $response)) {
			$this->registration_enabled = true;
		} elseif(preg_match('/<form name="registerform" id="registerform" action="[^"]*wp-login\.php[^"]*"/i', $response)) {
			$this->registration_enabled = true;
		} else {
			$this->registration_enabled = false;
		}
	}
	
	private function __is_multisite() {
		$response = HTTPRequest($this->url . '/wp-signup.php', false, '', false);	
		$headers = explode("\r\n", $response);
		foreach($headers as $header) {
			if(stripos($header, 'location:') === 0) {
				if(preg_match('/wp-login\.php\?action=register/i', $header)) {
					$this->is_multisite =  false;
				} elseif(preg_match('/wp-signup\.php/i', $header)) {
					$this->is_multisite =  true;
				}
			}
			if(stripos($header, 'HTTP/1.1 200 OK') !== false) {
				$this->is_multisite =  true;
			}
		}
		$this->is_multisite =  false;		
	}
	
	private function __search_sdb() {
		$files = array('sdb.php', 'searchreplacedb2.php');
		foreach($files as $file) {
			$response = HTTPRequest($this->url . '/' . $file);
			if(stripos($response, 'by interconnect') !== false) {
				return $this->url . '/' . $file;
			}
		}
		return false;
	}
	
	private function new_url($current) {
		$response = HTTPRequest($current, false, '', false);
		$headers = explode("\r\n", $response);
		foreach($headers as $header) {
			if(stripos($header, 'location:') === 0) {
				return rtrim( ltrim( str_ireplace('location:', '', $header) ), '/' );
			}
		}
		return $current;
	}
}
?>