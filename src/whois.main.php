<?php

/*
  Whois2.php	PHP classes to conduct whois queries

  Copyright (C)1999,2000 easyDNS Technologies Inc. & Mark Jeftovic

  Maintained by Mark Jeftovic <markjr@easydns.com>

  For the most recent version of this package:

  http://www.easydns.com/~markjr/whois2/

  This program is free software; you can redistribute it and/or
  modify it under the terms of the GNU General Public License
  as published by the Free Software Foundation; either version 2
  of the License, or (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
*/

class Whois {
	// Windows based ?

	var $windows = false;

	// Recursion allowed ?
	var $gtld_recurse = true;

	// Full code and data version string (e.g. 'Whois2.php v3.01:16')
	var $VERSION;

	// This release of the package
	var $CODE_VERSION = "4.0.0";
	
	// Network Solutions registry server
	var $NSI_REGISTRY = "whois.nsiregistry.net";

	// Network Solutions registrar server (?)
	var $NSI_REGISTRAR = "whois.networksolutions.com";

	// Default WHOIS port
	var $PORT = 43;

	// Maximum number of retries on connection failure
	var $RETRY = 0;

	// Time to wait between retries
	var $SLEEP = 2;

	// Read buffer size (0 == char by char)
	var $BUFFER = 0;

	// Status response codes
	var $STAT = array(
		-1 => "error",
		0 => "ready",
		1 => "ok"
		);

	// Array to contain all query variables
	var $Query = array(
		"tld" => "",
		"type" => "domain",
		"string" => "", 
		"status",
		"server"
		);
	
	// Various hacks. In a perfect world we don't need these.
	var $HACKS = array(
		// force "dom" keywork
		"nsi_force_dom" => 1,
		// set if nsiregistry gives wrong whois server for netsol
		"nsi_referral_loop" => 0,
		// ???
		"wrong_netsol_whois" => "rs.internic.net",
		// ???
		"real_netsol_whois" => "whois.networksolutions.com",
		// force english output on .jp for us ethnocentric types, unset or comment out for Japanese output
		"force_slash_e" => "whois.nic.ad.jp",
		// whois.nic.cx hangs forever
		"cx_is_broken" => 1
		);

	// List of servers and handlers (loaded from servers.whois)
	var $DATA = array(); 	

	// Communications timeout

	var $timeout = 10;
	/*
	 * Constructor function
	 */
	function Whois ($query = "") {
		// Load DATA array
		@require('whois.servers.php');

		$pos = strpos(strtolower(getenv ("OS")), "win");

                if ($pos === false) $this->windows=false;
                else $this->windows=true;

		// Set version
		$this->VERSION = sprintf("Whois2.php v%s:%s", $this->CODE_VERSION, $this->DATA_VERSION);

		$query = trim($query);

		// If domain to query was not set
		if(!isSet($query) || $query=="") {
			// Configure to use default whois server
			$this->Query["server"] = $this->NSI_REGISTRY;
			return;
		}

		// Set domain to query in query array

		$this->Query["string"] = $domain = strtolower($query);

                // If query is an ip address do ip lookup

                if($query == long2ip(ip2long($query)) || !strpos($query,'.')) {
                        // Prepare to do lookup via the 'ipw' handler
                        $ip = @gethostbyname($query);
                        $this->Query["server"] = "whois.arin.net";
                        $this->Query["host_ip"] = $ip;
                        $this->Query["file"] = "ipw.whois";
                        $this->Query["handler"] = "ipw";
                        $this->Query["string"] = $ip;
                        $this->Query["tld"] = "ipw";
			$this->Query["host_name"] = @gethostbyaddr($ip);
                        return;
                }

                // Test if we know in advance that no whois server is
                // available for this domain and that we can get the
                // data via http or whois request

                reset($this->HTTPW);

                while (list($key, $val)=each($this->HTTPW))
                        if (substr($query,-strlen($key)-1)==".$key")
                           {
							$domain=substr($query,0,-strlen($key)-1);	
							$val = str_replace("{domain}",$domain,$val);
							$this->Query["server"] = str_replace("{tld}",$key,$val);
							$this->Query["tld"] = $key;

                             // If a handler exists for the tld
			     
                             if(isSet($this->DATA[$key])) {
                                // Set file/handler in query array
                                $handler = $this->DATA[$key];
                                $this->Query["file"] = "whois.$handler.php";
                                $this->Query["handler"] = $handler;
                               }
                             return;
                           }

		// Determine the top level domain, and it's whois server using
                // DNS lookups on 'whois-servers.net'.
		// Assumes a valid DNS response indicates a recognised tld (!?)
		$tld = "";
		$server = "";
		$dp = explode(".", $domain);
		$np = count($dp) -1;
		$tldtests = array();

		for ($i=0; $i<$np; $i++) {
			array_shift($dp);
                      	$tldtests[] = implode(".",$dp);
		    	}

		foreach($tldtests as $tld) {

			if ($this->windows)
				$cname = $this->checkdnsrr_win($tld.".whois-servers.net", "CNAME");
			else
				$cname = checkdnsrr($tld.".whois-servers.net", "CNAME");

			if(!$cname) continue;
			//This also works
			//$server = gethostbyname($tld.".whois-servers.net");
			$server = $tld.".whois-servers.net";
			break;
		}

		if($tld && $server) {
			// If found, set tld and whois server in query array
			$this->Query["server"] = $server;
			$this->Query["tld"] = $tld;
			// If a handler exists for the tld

			foreach($tldtests as $htld)
				{
				if(isSet($this->DATA[$htld]))
					{
					// Set file/handler in query array
					$handler = $this->DATA[$htld];
					$this->Query['file'] = "whois.$handler.php";
					$this->Query["handler"] = $handler;
					break;
					}				
				}
			return;
		}

		// If tld not known, and domain not in DNS, return error
		unset($this->Query["server"]);
		$this->Query["status"] = -1;
		$this->Query["errstr"][] = $this->Query["string"]." domain is not supported";
		return;
	}

	/*
	*  Checks dns reverse records on win platform
	*/

	function checkdnsrr_win($hostName, $recType= '')
        {
                if(!empty($hostName)) {
			if( $recType == '' ) $recType = "MX";
			exec("nslookup -type=$recType $hostName", $result);
			// check each line to find the one that starts with the host
			// name. If it exists thenthe function succeeded.
			foreach ($result as $line) {
				if(eregi("^$hostName",$line)) return true;
			}
			// otherwise there was no mail handler for the domain
			return false;
		}
		return false;
	}

	/*
	 * Open a socket to the whois server.
	 *
	 * Returns a socket connection pointer on success, or -1 on failure.
	 */
	function Connect () {
		// Fail if server not set
		if(!isSet($this->Query["server"]))
			return(-1);

		// Enter connection attempt loop
		$server = $this->Query["server"];
		$retry = 0;
		while($retry <= $this->RETRY) {
			// Set query status
			$this->Query["status"] = "ready";

			// Connect to whois port
			$ptr = @fsockopen($server, $this->PORT);
			if($ptr > 0) {
				$this->Query["status"]="ok";
				return($ptr);
			}
			
			// Failed this attempt
			$this->Query["status"] = "error";
			$retry++;

			// Sleep before retrying
			sleep($this->SLEEP);
		}
		
		// If we get this far, it hasn't worked
		return(-1);
	} 

	/*
	 * Post-process result with handler class. On success, returns the result
	 * from the handler. On failure, returns passed in result unaltered.
	 */
	function Process (&$result) {

		// If the handler has not already been included somehow, include it now
		$HANDLER_FLAG = sprintf("__%s_HANDLER__", strtoupper($this->Query["handler"]));

		if(!defined($HANDLER_FLAG))
			@include($this->Query["file"]);

		// If the handler has still not been included, append to query errors list and return
		if(!defined($HANDLER_FLAG)) {
			$this->Query["errstr"][] = "Can't find ".$this->Query["tld"]." handler: ".$this->Query["file"];
			return($result);
		}

		if (!$this->gtld_recurse && $this->Query["file"]=='whois.com.php')
			return $result;

		// Pass result to handler
		$object = $this->Query['handler'].'_handler';
		$handler = new $object('');

		// If handler returned an error, append it to the query errors list
		if(isSet($handler->Query["errstr"]))
			$this->Query["errstr"][] = $handler->Query["errstr"];

		// Return the result
		return $handler->parse($result);
	}

	/*
	*   Convert html output to plain text
	*/
	function httpQuery ($query) {
		$lines = file($this->Query["server"]);
             	$output = "";
		$pre = "";

		while (list($key, $val)=each($lines)) {
			$val = trim($val);

			$pos=strpos(strtoupper($val),"<PRE>");
			if ($pos!==false) {
				$pre = "\n";
				$output.=substr($val,0,$pos)."\n";
				$val = substr($val,$pos+5);
			}
			$pos=strpos(strtoupper($val),"</PRE>");
                        if ($pos!==false) {
                                $pre = "";
                                $output.=substr($val,0,$pos)."\n";
                                $val = substr($val,$pos+6);                    
                        }
			$output.=$val.$pre;
		}
			
		$search = array (
				"<BR>", "<P>", "</TITLE>",
				"</H1>", "</H2>", "</H3>,",
				"<br>", "<p>", "</title>",
				"</h1>", "</h2>", "</h3>"  );

                $output = str_replace($search,"\n",$output);
		$output = str_replace("<TD"," <td",$output);
		$output = str_replace("<td"," <td",$output);
		$output = str_replace("<tr","\n<tr",$output);
		$output = str_replace("<TR","\n<tr",$output);
		$output = str_replace('&nbsp;',' ',$output);
		$output = html_entity_decode($output);
		$output = explode("\n",strip_tags($output));

		$rawdata = array();
		$null = 0;

		while (list($key, $val)=each($output)) {
			$val=trim($val);
			if ($val=='') {
				if (++$null>2) continue;
			}
			else $null=0;
			$rawdata[]=$val;
		}

		return $rawdata;
	}

	/*
	*  Fix and/or add name server information
	*/
	function FixResult (&$result,$domain) {

		// Add usual fields
		$result['regrinfo']['domain']['name']=$domain;

		// Check if nameservers exist

		if (!isset($result['regrinfo']['registered'])) {
                        if ($this->windows)
                                $has_ns = $this->checkdnsrr_win($domain, "NS");
                        else
                                $has_ns = checkdnsrr($domain, "NS");

			if ($has_ns)
				$result['regrinfo']['registered']='yes';
			else
				$result['regrinfo']['registered']='unknown';
			}

		if (!isset($result['regrinfo']['domain']['nserver']))
			return;

		// Normalize nameserver fields
		$nserver=$result['regrinfo']['domain']['nserver'];

		if (!is_array($result['regrinfo']['domain']['nserver'])) {
			unset($result['regrinfo']['domain']['nserver']);
			return;
			}

		$dns=array();

		while (list($key, $val) = each($nserver)) {
			$val=str_replace('[','',trim($val));
			$val=str_replace(']','',$val);
			$val=str_replace("\t",' ',$val);
			$parts=explode(' ',$val);
			$host='';
			$ip='';

			while (list($k, $p) = each($parts)) {
				if ($p=='') continue;
				if (ip2long($p)===-1)
					{
					if ($host=='') $host=$p;
					}
				else
					$ip=$p;
				}
			if ($ip=='')
				{
				$ip=gethostbyname($host);
				if ($ip==$host)
					$ip='(DOES NOT EXIST)';
				}

			$dns[strtolower($host)]=$ip;
			} 

		$result['regrinfo']['domain']['nserver']=$dns;
	}

	/*
	 * Perform lookup. Returns an array. The 'rawdata' element contains an
	 * array of lines gathered from the whois query. If a top level domain
	 * handler class was found for the domain, other elements will have been
	 * populated too.
	 */
	function Lookup ($query = "") {
		// If domain to query passed in, use it, otherwise use domain from initialisation
		$string = !empty($query) ? $query : $this->Query["string"];

		if (!isset($this->Query["server"])) {
			$this->Query["status"] = -1;
                        $this->Query["errstr"][] = "No server specified";
                        return(array());
			}

		// Check if protocol is http
		if (substr($this->Query["server"],0,7)=="http://" ||
                    substr($this->Query["server"],0,8)=="https://")
                      {
                        $result['rawdata'] = $this->httpQuery($this->Query["server"]);

                        // If we have a handler, post-process it with that

                        if(isSet($this->DATA[$this->Query["tld"]]))
                                $result = $this->Process($result);

						$result['regyinfo']['whois'] = strtok($this->Query["server"],'?');
						// Fix/add nameserver information
						$this->FixResult($result,$string);
                        return($result);
		      }

		// If the '.cx' whois server is broken, return an error now (saves attempting and timing out)
		if($this->HACKS["cx_is_broken"] && $this->Query["tld"] == "cx") {
			$this->Query["errstr"][] = ".cx doesn't work. Turn off HACKS[\"cx_is_broken\"] if ".$this->Query["server"]." finally got fixed.";
			return("");
		}

		// Connect to whois server, or return if failed
		$ptr = $this->Connect();
		
		if($ptr < 0) {
			$this->Query["status"] = -1;
			$this->Query["errstr"][] = "Connect failed to: ".$this->Query["server"];
			return(array());
		}

		stream_set_timeout($ptr,$this->timeout);

		if (isset($this->WHOIS_PARAM[$this->Query["tld"]]))
			fputs($ptr, $this->WHOIS_PARAM[$this->Query["tld"]].trim($string)."\r\n");
		else
			fputs($ptr, trim($string)."\r\n");

		// Prepare to receive result
		$raw = "";
		$output = array();
		while(!feof($ptr)) {
			// If a buffer size is set, fetch line-by-line into an array
			if($this->BUFFER)
				$output[] = fgets($ptr, $this->BUFFER);
			// If not, fetch char-by-char into a string
			else
				$raw .= fgetc($ptr);
		}

		// If captured char-by-char, convert to an array of lines
		if(!$this->BUFFER)
			$output = explode("\n", $raw);

		// Drop empty last line
		unset($output[count($output)-1]);
		
		// Create result and set 'rawdata'
		$result = array();
		$result['rawdata'] = $output;

		// Set whois server
		$result['regyinfo']['whois'] = $this->Query["server"];

		// If we have a handler, post-process it with that

		if(isSet($this->Query["handler"]))
                        $result = $this->Process($result);

		// Add error information if any
		if (isset($this->Query["errstr"]))
			$result["errstr"] = $this->Query["errstr"];

		// If no rawdata use rawdata from first whois server
		if (!isset($result["rawdata"]))
			$result["rawdata"] = $output;
		
		// Fix/add nameserver information
		if (method_exists($this,'FixResult') && $this->Query['tld']!='ipw')
			$this->FixResult($result,$string);
		return($result);
	}
}

?>