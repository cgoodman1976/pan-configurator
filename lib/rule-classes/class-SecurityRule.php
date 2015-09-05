<?php


/*
 * Copyright (c) 2014 Palo Alto Networks, Inc. <info@paloaltonetworks.com>
 * Author: Christophe Painchaud <cpainchaud _AT_ paloaltonetworks.com>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.

 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
*/

/**
 * Class SecurityRule
 */
class SecurityRule extends Rule
{
	protected $action = self::ActionAllow;

	protected $logstart = false;
	protected $logend = true;

	/** @var null|DOMElement */
	protected $logstartroot;
	/** @var null|DOMElement */
	protected $logendroot;
	
	protected $negatedSource = false;
	protected $negatedDestination = false;

	protected $logSetting = false;

	/** @var null|DOMElement */
	protected $logsettingroot = null;
	
	protected $secproftype = 'none';

    /** @var null|string[]|DOMElement */
	protected $secprofroot = null;
	protected $secprofgroup = null;
	protected $secprofprofiles = Array();

    /** @var AppRuleContainer */
    public $apps = null;

    const TypeUniversal = 0;
    const TypeIntrazone = 1;
    const TypeInterzone = 2;

    static private $RuleTypes = Array(
        self::TypeUniversal => 'universal',
        self::TypeIntrazone => 'intrazone',
        self::TypeInterzone => 'interzone'
    );


    const ActionAllow       = 0;
    const ActionDeny        = 1;
    const ActionDrop        = 2;
    const ActionResetClient = 3;
    const ActionResetServer = 4;
    const ActionResetBoth   = 5;

    static private $RuleActions = Array(
        self::ActionAllow => 'allow',
        self::ActionDeny => 'deny',
        self::ActionDrop => 'drop',
        self::ActionResetClient => 'reset-client',
        self::ActionResetServer => 'reset-server',
        self::ActionResetBoth => 'reset-both'
    );




    protected $ruleType = self::TypeUniversal;


	/**
	 * @param RuleStore $owner
	 * @param bool $fromTemplateXML
	 */
	public function __construct($owner,$fromTemplateXML=false)
	{
		$this->owner = $owner;

		$this->findParentAddressStore();
		$this->findParentServiceStore();
		
		
		$this->init_tags_with_store();
		$this->init_from_with_store();
		$this->init_to_with_store();
		$this->init_source_with_store();
		$this->init_destination_with_store();
		$this->init_services_with_store();
		$this->init_apps_with_store();
		
		if( $fromTemplateXML )
		{
			$xmlElement = DH::importXmlStringOrDie($owner->xmlroot->ownerDocument, self::$templatexml);
			$this->load_from_domxml($xmlElement);
		}
		
	}



	public function load_from_domxml($xml)
	{
		$this->xmlroot = $xml;
		
		$this->name = DH::findAttribute('name', $xml);
		if( $this->name === FALSE )
			derr("name not found\n");
		
		//print "found rule name '".$this->name."'\n";
		
		//  											//
		//	Begin of <disabled> extraction				//
		//												//
		$this->extract_disabled_from_domxml();
		// End of <disabled> properties extraction		//
		
		//  											//
		//	Begin of <description> extraction			//
		//												//
		$this->extract_description_from_domxml();
		// End of <description> extraction 				//
		
		
		$this->load_source();
		$this->load_destination();
		$this->load_tags();
        $this->load_from();
		$this->load_to();
		
		
		//														//
		// Begin <application> application extraction			//
		//														//
		$tmp = DH::findFirstElementOrCreate('application', $xml);
		$this->apps->load_from_domxml($tmp);
		// end of <application> application extraction
		
		
		
		//										//
		// Begin <service> extraction			//
		//										//
		$tmp = DH::findFirstElementOrCreate('service', $xml);
		$this->services->load_from_domxml($tmp);
		// end of <service> zone extraction

		//
		// Begin <log-setting> extraction
		//
		$tmp = $this->logstartroot = DH::findFirstElement('log-setting', $xml);
		if( $tmp === false )
			$this->logSetting = false;
		else
		{
			$this->logSetting = $tmp->textContent;
		}
		// End of <log-setting>
		
		
		//
		// Begin <log-start> extraction
		//
		$this->logstartroot = DH::findFirstElementOrCreate('log-start', $xml, 'no');
		$this->logstart = yesNoBool($this->logstartroot->textContent);
		// End of <log-start>
		
		
		//
		// Begin <log-end> extraction
		//
		$this->logendroot = DH::findFirstElementOrCreate('log-end', $xml, 'yes');
		$this->logend = yesNoBool($this->logendroot->textContent);
		// End of <log-start>
		
		
		//
		// Begin <profile-setting> extraction
		//
		$this->secprofroot = DH::findFirstElement('profile-setting', $xml);
        if( $this->secprofroot === false )
            $this->secprofroot = null;
		$this->extract_security_profile_from_domxml();
		// End of <profile-setting>
				
		
		
		//
		// Begin <negate-source> extraction
		//
		$negatedSourceRoot = DH::findFirstElement('negate-source', $xml);
		if( $negatedSourceRoot !== false )
			$this->negatedSource = yesNoBool($negatedSourceRoot->textContent);
		else
			$this->negatedSource = false;
		// End of <negate-source>
		//
		
		// Begin <negate-destination> extraction
		//
		$negatedDestinationRoot = DH::findFirstElement('negate-destination', $xml);
		if( $negatedDestinationRoot !== false )
			$this->negatedDestination = yesNoBool($negatedDestinationRoot->textContent);
		else
			$this->negatedDestination = false;
		// End of <negate-destination>


        //
        // Begin <action> extraction
        //
        $tmp = DH::findFirstElement('action', $xml);
        if( $tmp !== false )
        {
            $actionFound = array_search($tmp->textContent, self::$RuleActions);
            if( $actionFound === false )
            {
                mwarning("unsupported action '{$tmp->textContent}' found, allow assumed" , $tmp);
            }
            else
            {
                $this->action = $actionFound;
            }
        }
        else
        {
            mwarning("'<action> not found, assuming 'allow'" ,$xml);
        }
        // End of <rule-type>


        //
        // Begin <rule-type> extraction
        //
        if( $this->owner->version >= 61 )
        {
            $tmp = DH::findFirstElement('rule-type', $xml);
            if( $tmp !== false )
            {
                $typefound = array_search($tmp->textContent, self::$RuleTypes);
                if( $typefound === false )
                {
                    mwarning("unsupported rule-type '{$tmp->textContent}', universal assumed" , $tmp);
                }
                else
                {
                    $this->ruleType = $typefound;
                }
            }
        }
        // End of <rule-type>

	}


    /**
     * @return string type of this rule : 'universal', 'intrazone', 'interzone'
     */
    public function type()
    {
        return self::$RuleTypes[$this->ruleType];
    }


	/**
	*
	* @ignore
	*/
	protected function extract_security_profile_from_domxml()
	{

        if( $this->secprofroot === null || $this->secprofroot === false )
        {
            $this->secprofroot = null;
            return;
        }

		$xml = $this->secprofroot;
		
		//print "Now trying to extract associated security profile associated to '".$this->name."'\n";
		
		$groupRoot = DH::findFirstElement('group', $xml);
		$profilesRoot = DH::findFirstElement('profiles', $xml);
		
		if( $groupRoot !== FALSE )
		{
			//print "Found SecProf <group> tag\n";
			$firstE = DH::firstChildElement($groupRoot);

			if( $firstE !== FALSE )
			{
				$this->secprofgroup = $firstE->textContent;
				$this->secproftype = 'group';
				
				//print "Group name: ".$this->secprofgroup."\n";
			}
		}
		else if( $profilesRoot !== FALSE )
		{
			//print "Found SecProf <profiles> tag\n";
			$this->secproftype = 'profile';
			
			foreach( $profilesRoot->childNodes as $prof )
			{
				if( $prof->nodeType != 1 ) continue;
				$firstE = DH::firstChildElement($prof);
				$this->secprofprofiles[$prof->nodeName] = $firstE->textContent;
				
				
				/* <virus>
       
                      </vulnerability>
                      <url-filtering>

                      </data-filtering>
                      <file-blocking>

                      </spyware>*/

			}
			
		}
		
	}
	
	/**
	* return profile type: 'group' or 'profile' or 'none'
	* @return string 
	*/
	public function securityProfileType()
	{
		return $this->secproftype;
	}
	
	public function securityProfileGroup()
	{
		if( $this->secproftype != 'group' )
			derr('Cannot be called on a rule that is of security type ='.$this->secproftype);
		
		return $this->secprofgroup;
	}
	
	public function removeSecurityProfile()
	{
		$this->secproftype = 'none';
		$this->secprofgroup = null;
		$this->secprofprofiles = Array();
		
		$this->rewriteSecProfXML();
	}
	
	public function setSecurityProfileGroup( $newgroup )
	{
		$this->secproftype = 'group';
		$this->secprofgroup = $newgroup;
		$this->secprofprofiles = Array();
			
		$this->rewriteSecProfXML();
	}

	public function API_setSecurityProfileGroup( $newgroup )
	{
		$this->setSecurityProfileGroup($newgroup);

		$xpath = $this->getXPath().'/profile-setting';
		$con = findConnectorOrDie($this);

		$con->sendEditRequest( $xpath, '<profile-setting><group><member>'.$newgroup.'</member></group></profile-setting>');

	}

	
	public function setSecProf_AV( $newAVprof )
	{
		$this->secproftype = 'profiles';
		$this->secprofgroup = null;
		$this->secprofprofiles['virus'] = $newAVprof;
		
		$this->rewriteSecProfXML();
	}
	
	public function setSecProf_Vuln( $newAVprof )
	{
		$this->secproftype = 'profiles';
		$this->secprofgroup = null;
		$this->secprofprofiles['vulnerability'] = $newAVprof;
		
		$this->rewriteSecProfXML();
	}
	
	public function setSecProf_URL( $newAVprof )
	{
		$this->secproftype = 'profiles';
		$this->secprofgroup = null;
		$this->secprofprofiles['url-filtering'] = $newAVprof;
		
		$this->rewriteSecProfXML();
	}
	
	public function setSecProf_DataFilt( $newAVprof )
	{
		$this->secproftype = 'profiles';
		$this->secprofgroup = null;
		$this->secprofprofiles['data-filtering'] = $newAVprof;
		
		$this->rewriteSecProfXML();
	}
	
	public function setSecProf_FileBlock( $newAVprof )
	{
		$this->secproftype = 'profiles';
		$this->secprofgroup = null;
		$this->secprofprofiles['file-blocking'] = $newAVprof;
		
		$this->rewriteSecProfXML();
	}
	
	public function setSecProf_Spyware( $newAVprof )
	{
		$this->secproftype = 'profiles';
		$this->secprofgroup = null;
		$this->secprofprofiles['spyware'] = $newAVprof;
		
		$this->rewriteSecProfXML();
	}
	
	public function rewriteSecProfXML()
	{

		if( $this->secprofroot !== null )
			DH::clearDomNodeChilds($this->secprofroot);
		if ( $this->secproftype == 'group' )
		{
			if( $this->secprofroot === null || $this->secprofroot === false )
				$this->secprofroot = DH::createElement( $this->xmlroot, 'profile-setting');
			else
				$this->xmlroot->appendChild($this->secprofroot);

			$tmp = $this->secprofroot->ownerDocument->createElement('group');
			$tmp = $this->secprofroot->appendChild($tmp);
			$tmp = $tmp->appendChild( $this->secprofroot->ownerDocument->createElement('member') );
			$tmp->appendChild( $this->secprofroot->ownerDocument->createTextNode($this->secprofgroup) );
		}
		else if ( $this->secproftype == 'profiles' )
		{
			if( $this->secprofroot === null || $this->secprofroot === false)
				$this->secprofroot = DH::createElement( $this->xmlroot, 'profile-setting');
			else
				$this->xmlroot->appendChild($this->secprofroot);

			$tmp = $this->secprofroot->ownerDocument->createElement('profiles');
			$tmp = $this->secprofroot->appendChild($tmp);

			foreach($this->secprofprofiles as $index=>$value)
			{
				$type = $tmp->appendChild( $this->secprofroot->ownerDocument->createElement($index) );
				$ntmp = $type->appendChild( $this->secprofroot->ownerDocument->createElement('member') );
				$ntmp->appendChild( $this->secprofroot->ownerDocument->createTextNode($value) );
			}
		}
		elseif( $this->secprofroot !== null )
			DH::removeChild($this->xmlroot, $this->secprofroot);

	}
	
	
	public function action()
	{
		return self::$RuleActions[$this->action];
	}

	public function actionIsAllow()
	{
		return $this->action == self::ActionAllow;
	}

	public function actionIsDeny()
	{
        return $this->action == self::ActionDeny;
	}

    public function actionIsDrop()
    {
        return $this->action == self::ActionDrop;
    }

    public function actionIsResetClient()
    {
        return $this->action == self::ActionResetClient;
    }

    public function actionIsResetServer()
    {
        return $this->action == self::ActionResetServer;
    }

    public function actionIsResetBoth()
    {
        return $this->action == self::ActionResetBoth;
    }

    public function actionIsNegative()
    {
        return $this->action != self::ActionAllow;
    }
	
	public function setAction($newAction)
	{
		$newAction = strtolower($newAction);
        $actionFound = array_search($newAction, self::$RuleActions);

		if( $actionFound !== FALSE )
		{
            $this->action = $actionFound;
            if( $this->owner->version < 70 && $actionFound > self::ActionDeny )
            {
                derr("action '$newAction' is not supported before PANOS 7.0");
            }
			$domNode = DH::findFirstElementOrCreate('action', $this->xmlroot);
			DH::setDomNodeText($domNode, $newAction);
		}
		else
            derr("'$newAction' is not supported action type\n");
	}

    public function API_setAction($newAction)
    {
        $this->setAction($newAction);

        $domNode = DH::findFirstElementOrDie('action', $this->xmlroot);
        $connector = findConnectorOrDie($this);
        $connector->sendSetRequest($this->getXPath(), $domNode);
    }
	
	
	/**
	* return true if rule is set to Log at Start
	* @return bool
	*/
	public function logStart()
	{
		return $this->logstart;
	}
	
	/**
	* enabled or disabled logging at start
	* @param bool $yes
	 * @return bool
	*/
	public function setLogStart($yes)
	{
		if( $this->logstart != $yes )
		{
			$this->logstartroot->nodeValue =  boolYesNo($yes);

			$this->logstart = $yes;

			return true;
		}

		return false;

	}
	
	/**
	* return true if rule is set to Log at End
	* @return bool
	*/
	public function logEnd()
	{
		return $this->logend;
	}
	
	/**
	* enable or disabled logging at end
	* @param bool $yes
	 * @return bool
	*/
	public function setLogEnd($yes)
	{
		if( $this->logend != $yes )
		{
			$this->logendroot->nodeValue =  boolYesNo($yes);

			$this->logend = $yes;

			return true;
		}
		
		return false;
	}

	/**
	 * enable or disabled logging at end
	 * @param bool $yes
	 * @return bool
	 */
	public function API_setLogEnd($yes)
	{
		if( !$this->setLogEnd($yes) )
		{
			return false;
		}

		$con = findConnectorOrDie($this);

		$con->sendSetRequest($this->getXPath(), "<log-end>".boolYesNo($yes)."</log-end>");
	}

	/**
	 * enable or disabled logging at end
	 * @param bool $yes
	 * @return bool
	 */
	public function API_setLogStart($yes)
	{
		if( !$this->setLogStart($yes) )
		{
			return false;
		}

		$con = findConnectorOrDie($this);

		$con->sendSetRequest($this->getXPath(), "<log-start>".boolYesNo($yes)."</log-start>");
	}
	
	/**
	* return log forwarding profile if any
	* @return string
	*/
	public function logSetting()
	{
		return $this->logSetting;
	}

	/**
	 * @param string $newLogSetting
	 */
	public function setLogSetting($newLogSetting)
	{
		if( $newLogSetting === null || strlen($newLogSetting) < 1 )
		{
			$this->logSetting = false;

			if( $this->logsettingroot !== null )
				$this->xmlroot->removeChild($this->logsettingroot);

			return;
		}

		$this->logSetting = $newLogSetting;

		if( $this->logsettingroot === null )
		{
			$this->logsettingroot = DH::createElement($this->xmlroot, 'log-setting', $newLogSetting);
		}
		else
			$this->logsettingroot->nodeValue = $newLogSetting;
	}

	public function API_setLogSetting($newLogSetting)
	{
		$this->setLogSetting($newLogSetting);

		$con = findConnectorOrDie($this);

		if( $this->logSetting === false )
		{
			$con->sendDeleteRequest($this->getXPath().'/log-setting');
		}
		else
		{
			$con->sendSetRequest($this->getXPath(), "<log-setting>$newLogSetting</log-setting>");
		}
	}
	
	
	public function sourceIsNegated()
	{
		return $this->negatedSource;
	}

	/**
	 * @param bool $yes
	 * @return bool
	 */
	public function setSourceIsNegated($yes)
	{
		if( $this->negatedSource != $yes )
		{
			$tmpRoot = DH::findFirstElement('negate-source', $this->xmlroot);
			if( $tmpRoot === false )
			{
				if($yes)
					DH::createElement($this->xmlroot, 'negate-source', 'yes');
			}
			else
			{
				if( !$yes )
					$this->xmlroot->removeChild($tmpRoot);
				else
					DH::setDomNodeText($tmpRoot, 'yes');
			}

			$this->negatedSource = $yes;

			return true;
		}
		
		return false;
	}

	/**
	 * @param bool $yes
	 * @return bool
	 */
	public function API_setSourceIsNegated($yes)
	{
		$ret = $this->setSourceIsNegated($yes);

		if( $ret )
		{
			$con = findConnectorOrDie($this);
			$con->sendSetRequest($this->getXPath(), '<negate-source>'.boolYesNo($yes).'</negate-source>');
		}

		return $ret;
	}
	
	
	public function destinationIsNegated()
	{
		return $this->negatedDestination;
	}

	/**
	 * @param bool $yes
	 * @return bool
	 */
	public function setDestinationIsNegated($yes)
	{
		if( $this->negatedDestination != $yes )
		{
			$tmpRoot = DH::findFirstElement('negate-destination', $this->xmlroot);
			if( $tmpRoot === false )
			{
				if($yes)
					DH::createElement($this->xmlroot, 'negate-destination', 'yes');
			}
			else
			{
				if( !$yes )
					$this->xmlroot->removeChild($tmpRoot);
				else
                    DH::setDomNodeText($tmpRoot, 'yes');
			}

			$this->negatedDestination = $yes;

			return true;
		}

		return false;
	}

	/**
	 * @param bool $yes
	 * @return bool
	 */
	public function API_setDestinationIsNegated($yes)
	{
		$ret = $this->setDestinationIsNegated($yes);

		if( $ret )
		{
			$con = findConnectorOrDie($this);
			$con->sendSetRequest($this->getXPath(), '<negate-destination>'.boolYesNo($yes).'</negate-destination>');
		}

		return $ret;
	}
	
	
	/**
	* Helper function to quickly print a function properties to CLI
	*/
	public function display( $padding = 0)
	{
        $padding = str_pad('', $padding);

		$dis = '';
		if( $this->disabled )
			$dis = '<disabled>';

        $sourceNegated = '';
        if( $this->sourceIsNegated() )
            $sourceNegated = '*negated*';

        $destinationNegated = '';
        if( $this->destinationIsNegated() )
            $destinationNegated = '*negated*';

		
		print $padding."*Rule named '{$this->name}' $dis\n";
        print $padding."  Action: {$this->action()}    Type:{$this->type()}\n";
		print $padding."  From: " .$this->from->toString_inline()."  |  To:  ".$this->to->toString_inline()."\n";
		print $padding."  Source: $sourceNegated ".$this->source->toString_inline()."\n";
		print $padding."  Destination: $destinationNegated ".$this->destination->toString_inline()."\n";
		print $padding."  Service:  ".$this->services->toString_inline()."    Apps:  ".$this->apps->toString_inline()."\n";
		print $padding."    Tags:  ".$this->tags->toString_inline()."\n";
		print "\n";
	}



	// 'last-30-days','incomplete,insufficient-data'
	public function &API_getAppStats($timePeriod, $excludedApps)
	{
		$con = findConnectorOrDie($this);

		$parentClass = get_class($this->owner->owner); 

		$type = 'panorama-trsum';
		if( $parentClass == 'VirtualSystem' )
		{
			$type = 'trsum';
		}

		$excludedApps = explode(',', $excludedApps);
		$excludedAppsString = '';

		$first = true;

		foreach( $excludedApps as &$e )
		{
			if( !$first )
				$excludedAppsString .= ' and ';

			$excludedAppsString .= "(app neq $e)";

			$first = false; 
		}
		if( !$first )
			$excludedAppsString .= ' and ';

		$dvq = '';

		if( $parentClass == 'VirtualSystem' )
		{
			$dvq = '(vsys eq '.$this->owner->owner->name().')';

		}
		else
		{
			$devices = $this->owner->owner->getDevicesInGroup();
			//print_r($devices);

			$first = true;

			if( count($devices) == 0 )
				derr('cannot request rule stats for a device group that has no member');

			$dvq = '('.array_to_devicequery($devices).')';

		}

		$query = 'type=report&reporttype=dynamic&reportname=custom-dynamic-report&cmd=<type>'
		         .'<'.$type.'><aggregate-by><member>app</member></aggregate-by>'
		         .'<values><member>sessions</member></values></'.$type.'></type><period>'.$timePeriod.'</period>'
		         .'<topn>500</topn><topm>10</topm><caption>untitled</caption>'
		         .'<query>'."$dvq and $excludedAppsString (rule eq '".$this->name."')</query>";

		//print "Query: $query\n";

		$ret = $con->getReport($query);

		return $ret;
	}

	public function &API_getAppContainerStats($timePeriod = 'last-30-days', $fastMode = true, $limit = 50, $excludedApps = Array())
	{
		$con = findConnectorOrDie($this);

		$parentClass = get_class($this->owner->owner); 

		if( $fastMode )
            $type = 'panorama-trsum';
        else
            $type = 'panorama-traffic';

		if( $parentClass == 'VirtualSystem' )
		{
            if( $fastMode )
                $type = 'trsum';
            else
                $type = 'traffic';
		}

		$excludedAppsString = '';

        $first = true;
		foreach( $excludedApps as &$e )
		{
            if( !$first )
                $excludedAppsString .= ' and ';

            $excludedAppsString .= "(app neq $e)";
            $first = false;
		}

		$dvq = '';

		if( $parentClass == 'VirtualSystem' )
		{
			$dvq = ' and (vsys eq '.$this->owner->owner->name().')';

		}
		else
		{
			$devices = $this->owner->owner->getDevicesInGroup();

			if( count($devices) == 0 )
				derr('cannot request rule stats for a device group that has no member');

			$dvq = ' and ('.array_to_devicequery($devices).')';

		}

        $repeatOrCount = 'sessions';

        if( !$fastMode )
            $repeatOrCount = 'repeatcnt';

		$query = 'type=report&reporttype=dynamic&reportname=custom-dynamic-report&cmd=<type>'
		         ."<{$type}><aggregate-by><member>container-of-app</member></aggregate-by>"
		         ."<values><member>{$repeatOrCount}</member></values></{$type}></type><period>{$timePeriod}</period>"
		         ."<topn>{$limit}</topn><topm>50</topm><caption>untitled</caption>"
		         ."<query>(rule eq '{$this->name}') {$dvq} {$excludedAppsString}</query>";

		//print "Query: $query\n";

		$ret = $con->getReport($query);

		return $ret;
	}


	public function &API_getServiceStats($timePeriod, $specificApps=null)
	{
		$con = findConnectorOrDie($this);

		$query_appfilter = '';

		if( !is_null($specificApps) )
		{
			if( !is_array($specificApps) )
			{
				if( is_string($specificApps) )
				{
					$specificApps = explode(',', $specificApps);
				}
				else
					derr('$specificApps is not an array or a string');
			}

			$query_appfilter = ' and (';

			$first = true;
			foreach($specificApps as &$app)
			{
				if( !$first )
					$query_appfilter .= ' or ';
				else
					$first = false;

				$query_appfilter .= "(app eq $app)";
			}

			$query_appfilter .= ') ';
		}

		$parentClass = get_class($this->owner->owner);

		if( $parentClass == 'VirtualSystem' )
		{
			$type = 'traffic';
			$dvq = '(vsys eq '.$this->owner->owner->name().')';

		}
		else
		{
			$type = 'panorama-traffic';

			$devices = $this->owner->owner->getDevicesInGroup();
			//print_r($devices);

			$first = true;

			if( count($devices) == 0 )
				derr('cannot request rule stats for a device group that has no member');

			$dvq = '('.array_to_devicequery($devices).')';
		}

		$query = 'type=report&reporttype=dynamic&reportname=custom-dynamic-report&cmd=<type>'
		         .'<'.$type.'><aggregate-by><member>proto</member><member>dport</member></aggregate-by>'
		         .'</'.$type.'></type><period>'.$timePeriod.'</period>'
		         .'<topn>100</topn><topm>500</topm><caption>untitled</caption>'
		         .'<query>'."$dvq $query_appfilter and (rule eq '".$this->name."')</query>";


		$ret = $con->getReport($query);

		return $ret;
	}

	public function cleanForDestruction()
	{
		$this->from->__destruct();
		$this->to->__destruct();
		$this->source->__destruct();
		$this->destination->__destruct();
		$this->tags->__destruct();
		$this->apps->__destruct();
		$this->services->__destruct();


		$this->from->owner = null;
		$this->from->owner = null;
		$this->to->owner = null;
		$this->source->owner = null;
		$this->destination->owner = null;
		$this->tags->owner = null;
		$this->services->owner = null;
		$this->apps->owner = null;

		$this->from = null;
		$this->to = null;
		$this->source = null;
		$this->destination = null;
		$this->tags = null;
		$this->services = null;
		$this->apps = null;
	}

    public function isSecurityRule()
    {
        return true;
    }

    public function storeVariableName()
    {
        return "securityRules";
    }
	

	static protected $templatexml = '<entry name="**temporarynamechangeme**"><option><disable-server-response-inspection>no</disable-server-response-inspection></option><from><member>any</member></from><to><member>any</member></to>
<source><member>any</member></source><destination><member>any</member></destination><source-user><member>any</member></source-user><category><member>any</member></category><application><member>any</member></application><service><member>any</member>
</service><hip-profiles><member>any</member></hip-profiles><action>allow</action><log-start>no</log-start><log-end>yes</log-end><negate-source>no</negate-source><negate-destination>no</negate-destination><tag/><description/><disabled>no</disabled></entry>'; 
	static protected $templatexmlroot = null;

}


