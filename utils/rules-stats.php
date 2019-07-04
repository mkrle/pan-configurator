<?php

/*
 * Copyright (c) 2014-2019 Christophe Painchaud <shellescape _AT_ gmail.com>                      and Sven Waschkut <pan-c _AT_ waschkut.net>
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


print "\n***********************************************\n";
print "************ RULE-STATS UTILITY ****************\n\n";

set_include_path( dirname(__FILE__).'/../'. PATH_SEPARATOR .get_include_path() );
require_once("lib/panos_php_framework.php");
require_once("common/actions.php");


function display_usage_and_exit($shortMessage = false)
{
    global $argv;
    print PH::boldText("USAGE: ")."php ".basename(__FILE__)." in=inputfile.xml\n";
    print "php ".basename(__FILE__)." help          : more help messages\n";

    if( !$shortMessage )
    {
        print PH::boldText("\nListing available arguments\n\n");

        global $supportedArguments;

        ksort($supportedArguments);
        foreach( $supportedArguments as &$arg )
        {
            print " - ".PH::boldText($arg['niceName']);
            if( isset( $arg['argDesc']))
                print '='.$arg['argDesc'];
            //."=";
            if( isset($arg['shortHelp']))
                print "\n     ".$arg['shortHelp'];
            print "\n\n";
        }

        print "\n\n";
    }

    exit(1);
}

function display_error_usage_exit($msg)
{
    fwrite(STDERR, PH::boldText("\n**ERROR** ").$msg."\n\n");
    display_usage_and_exit(true);
}

print "\n";

$configType = null;
$configInput = null;
$configOutput = null;
$doActions = null;
$dryRun = false;
$rulesLocation = 'shared';
$rulesFilter = null;
$errorMessage = '';
$debugAPI = false;



$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'input file or api. ie: in=config.xml  or in=api://192.168.1.1 or in=api://0018CAEC3@panorama.company.com', 'argDesc' => '[filename]|[api://IP]|[api://serial@IP]');
$supportedArguments['location'] = Array('niceName' => 'Location', 'shortHelp' => 'specify if you want to limit your query to a VSYS/DG. By default location=shared for Panorama, =vsys1 for PANOS. ie: location=any or location=vsys2,vsys1', 'argDesc' => '=sub1[,sub2]');
$supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');
$supportedArguments['apitimeout'] = Array('niceName' => 'apiTimeout', 'shortHelp' => 'in case API takes too long time to anwer, increase this value (default=60)');
$supportedArguments['loadpanoramapushedconfig'] = Array('niceName' => 'loadPanoramaPushedConfig', 'shortHelp' => 'load Panorama pushed config from the firewall to take in account panorama objects and rules' );




PH::processCliArgs();

$nestedQueries = Array();


if( isset(PH::$args['help']) )
{
    $pos = array_search('help', $argv);

    if( $pos === false )
        display_usage_and_exit(false);

    $keys = array_keys($argv);

    if( $pos == end($keys) )
        display_usage_and_exit(false);

    $action = $argv[(array_search($pos, $keys) +1)];

    if( !isset(RuleCallContext::$supportedActions[strtolower($action)]) )
        derr("request help for action '{$action}' but it does not exist");

    $action = & RuleCallContext::$supportedActions[strtolower($action)];

    $args = Array();
    if( isset($action['args']) )
    {
        foreach( $action['args'] as $argName => &$argDetails )
        {
            if( $argDetails['default'] == '*nodefault*' )
                $args[] = "{$argName}";
            else
                $args[] = "[{$argName}]";
        }
    }

    $args = PH::list_to_string($args);
    print "*** help for Action ".PH::boldText($action['name']).":".$args."\n";

    if( isset($action['help']) )
        print $action['help'];

    if( !isset($args) || !isset($action['args']) )
    {
        print "\n\n**No arguments required**";
    }
    else
    {
        print "\nListing arguments:\n\n";
        foreach( $action['args'] as $argName => &$argDetails )
        {
            print "-- ".PH::boldText($argName)." :";
            if( $argDetails['default'] != "*nodefault" )
                print " OPTIONAL";
            print " type={$argDetails['type']}";
            if( isset($argDetails['choices']) )
            {
                print "     choices: ".PH::list_to_string($argDetails['choices']);
            }
            print "\n";
            if( isset($argDetails['help']) )
                print " ".str_replace("\n", "\n ",$argDetails['help']);
            else
                print "  *no help avaiable*";
            print "\n\n";
        }
    }


    print "\n\n";

    exit(0);
}


// check that only supported arguments were provided
foreach ( PH::$args as $index => &$arg )
{
    if( !isset($supportedArguments[$index]) )
    {
        if( strpos($index,'subquery') === 0 )
        {
            $nestedQueries[$index] = &$arg;
            continue;
        }
        //var_dump($supportedArguments);
        display_error_usage_exit("unsupported argument provided: '$index'");
    }
}


if( ! isset(PH::$args['in']) )
    display_error_usage_exit('"in" is missing from arguments');
$configInput = PH::$args['in'];
if( !is_string($configInput) || strlen($configInput) < 1 )
    display_error_usage_exit('"in" argument is not a valid string');


if( !isset(PH::$args['apitimeout']) )
{
    $apiTimeoutValue = 60;
}
else
    $apiTimeoutValue = PH::$args['apitimeout'];


if( isset(PH::$args['debugapi'])  )
{
    $debugAPI = true;
}


//
// Rule filter provided in CLI ?
//
if( isset(PH::$args['filter'])  )
{
    $rulesFilter = PH::$args['filter'];
    if( !is_string($rulesFilter) || strlen($rulesFilter) < 1 )
        display_error_usage_exit('"filter" argument is not a valid string');
}




//
// What kind of config input do we have.
//     File or API ?
//
// <editor-fold desc="  ****  input method validation and PANOS vs Panorama auto-detect  ****" defaultstate="collapsed" >
$configInput = PH::processIOMethod($configInput, true);
$xmlDoc = null;

if( $configInput['status'] == 'fail' )
{
    fwrite(STDERR, "\n\n**ERROR** " . $configInput['msg'] . "\n\n");exit(1);
}

/** @var $inputConnector PanAPIConnector */
$inputConnector = null;

if( $configInput['type'] == 'file' )
{
    if( isset(PH::$args['loadpanoramapushedconfig']) )
    {
        derr("'loadPanoramaPushedConfig' option cannot used in API/Online mode");
    }

    if( !file_exists($configInput['filename']) )
        derr("file '{$configInput['filename']}' not found");

    $xmlDoc = new DOMDocument();
    if( ! $xmlDoc->load($configInput['filename'], XML_PARSE_BIG_LINES) )
        derr("error while reading xml config file");

}
elseif ( $configInput['type'] == 'api'  )
{
    $inputConnector = $configInput['connector'];
    if($debugAPI)
       $inputConnector->setShowApiCalls(true);
    print " - Downloading config from API... ";
    $xmlDoc = $inputConnector->getCandidateConfig($apiTimeoutValue);
    print "OK!\n";
}
else
    derr('not supported yet');

//
// Determine if PANOS or Panorama
//
$xpathResult = DH::findXPath('/config/devices/entry/vsys', $xmlDoc);
if( $xpathResult === FALSE )
    derr('XPath error happened');
if( $xpathResult->length <1 )
{
    $configType = 'panorama';
    if( isset(PH::$args['loadpanoramapushedconfig']) )
    {
        derr("'loadPanoramaPushedConfig' mode can be used only on Firewalls but Panorama was detected");
    }
}
else
    $configType = 'panos';
unset($xpathResult);

print " - Detected platform type is '{$configType}'\n";

if( $configType == 'panos' )
{
    if( isset(PH::$args['loadpanoramapushedconfig']) )
    {
        print " - 'loadPanoramaPushedConfig' was requested, downloading it through API...";
        $panoramaDoc = $inputConnector->getPanoramaPushedConfig();

        $xpathResult = DH::findXPath('/panorama/vsys', $panoramaDoc);

        if( $xpathResult === false )
            derr("could not find any VSYS");

        if( $xpathResult->length != 1 )
            derr("found more than 1 <VSYS>");

        $fakePanorama = new PanoramaConf();
        $fakePanorama->_fakeMode = true;
        $inputConnector->refreshSystemInfos();
        $newDGRoot = $xpathResult->item(0);
        $panoramaString = "<config version=\"{$inputConnector->info_PANOS_version}\"><shared></shared><devices><entry name=\"localhost.localdomain\"><device-group>".DH::domlist_to_xml($newDGRoot->childNodes)."</device-group></entry></devices></config>";
        #print $panoramaString;
        $fakePanorama->load_from_xmlstring($panoramaString);

        $pan = new PANConf($fakePanorama);
    }
    else $pan = new PANConf();
}
else
    $pan = new PanoramaConf();

if( $inputConnector !== null )
    $pan->connector = $inputConnector;

// </editor-fold>


//
// Location provided in CLI ?
//
if( isset(PH::$args['location'])  )
{
    $rulesLocation = PH::$args['location'];
    if( !is_string($rulesLocation) || strlen($rulesLocation) < 1 )
        display_error_usage_exit('"location" argument is not a valid string');
}
else
{
    if( $configType == 'panos' )
    {
        print " - No 'location' provided so using default ='vsys1'\n";
        $rulesLocation = 'vsys1';
    }
    else
    {
        print " - No 'location' provided so using default ='shared'\n";
        $rulesLocation = 'shared';
    }
}

//
// Determine rule types
//

$supportedRuleTypes = Array('all', 'any', 'security', 'nat', 'decryption', 'appoverride', 'captiveportal', 'authentication', 'pbf', 'qos', 'dos');
if( !isset(PH::$args['ruletype'])  )
{
    print " - No 'ruleType' specified, using 'security' by default\n";
    $ruleTypes = Array('security');
}
else
{
    $ruleTypes = explode(',', PH::$args['ruletype']);
    foreach( $ruleTypes as &$rType)
    {
        $rType = strtolower($rType);
        if( array_search($rType, $supportedRuleTypes) === false )
        {
            display_error_usage_exit("'ruleType' has unsupported value: '".$rType."'. Supported values are: ".PH::list_to_string($supportedRuleTypes));
        }
        if( $rType == 'all' )
            $rType = 'any';
    }

    $ruleTypes = array_unique($ruleTypes);
}




//
// load the config
//
print " - Loading configuration through PAN-PHP-FRAMEWORK library... ";
$loadStartMem = memory_get_usage(true);
$loadStartTime = microtime(true);
$pan->load_from_domxml($xmlDoc);
$loadEndTime = microtime(true);
$loadEndMem = memory_get_usage(true);
$loadElapsedTime = number_format( ($loadEndTime - $loadStartTime), 2, '.', '');
$loadUsedMem = convert($loadEndMem - $loadStartMem);
print "OK! ($loadElapsedTime seconds, $loadUsedMem memory)\n";
// --------------------


//
// Location Filter Processing
//

// <editor-fold desc="Location Filter Processing" defaultstate="collapsed" >

/**@var RuleStore[] $ruleStoresToProcess */
$rulesLocation = explode(',', $rulesLocation);

foreach( $rulesLocation as &$location )
{
    if( strtolower($location) == 'shared' )
        $location = 'shared';
    else if( strtolower($location) == 'any' )
        $location = 'any';
    else if( strtolower($location) == 'all' )
        $location = 'any';
}
unset($location);

$rulesLocation = array_unique($rulesLocation);
$rulesToProcess = Array();

foreach( $rulesLocation as $location )
{
    $locationFound = false;

    if( $configType == 'panos')
    {
        foreach ($pan->getVirtualSystems() as $sub)
        {
            if( isset(PH::$args['loadpanoramapushedconfig']) )
            {
                if( ($location == 'any' || $location == 'all' || $location == $sub->name() && !isset($ruleStoresToProcess[$sub->name()]) ))
                {
                    if( array_search('any', $ruleTypes) !== false || array_search('security', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->securityRules, 'rules' => $sub->securityRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('nat', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->natRules, 'rules' => $sub->natRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('qos', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->qosRules, 'rules' => $sub->qosRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('pbf', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->pbfRules, 'rules' => $sub->pbfRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('decryption', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->decryptionRules, 'rules' => $sub->decryptionRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('appoverride', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->appOverrideRules, 'rules' => $sub->appOverrideRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('captiveportal', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->captivePortalRules, 'rules' => $sub->captivePortalRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('authentication', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->authenticationRules, 'rules' => $sub->authenticationRules->resultingRuleSet());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('dos', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->dosRules, 'rules' => $sub->dosRules->resultingRuleSet());
                    }
                    $locationFound = true;
                }
            }
            else
            {
                if( ($location == 'any' || $location == 'all' || $location == $sub->name() && !isset($ruleStoresToProcess[$sub->name()]) ))
                {
                    if( array_search('any', $ruleTypes) !== false || array_search('security', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->securityRules, 'rules' => $sub->securityRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('nat', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->natRules, 'rules' => $sub->natRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('qos', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->qosRules, 'rules' => $sub->qosRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('pbf', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->pbfRules, 'rules' => $sub->pbfRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('decryption', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->decryptionRules, 'rules' => $sub->decryptionRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('appoverride', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->appOverrideRules, 'rules' => $sub->appOverrideRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('captiveportal', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->captivePortalRules, 'rules' => $sub->captivePortalRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('authentication', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->authenticationRules, 'rules' => $sub->authenticationRules->rules());
                    }
                    if( array_search('any', $ruleTypes) !== false || array_search('dos', $ruleTypes) !== false )
                    {
                        $rulesToProcess[] = Array('store' => $sub->dosRules, 'rules' => $sub->dosRules->rules());
                    }
                    $locationFound = true;
                }
            }
        }
    }
    else
    {
        if( $location == 'shared' || $location == 'any' || $location == 'all'  )
        {
            if( array_search('any', $ruleTypes) !== false || array_search('security', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->securityRules, 'rules' => $pan->securityRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('nat', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->natRules, 'rules' => $pan->natRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('qos', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->qosRules, 'rules' => $pan->qosRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('pbf', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->pbfRules, 'rules' => $pan->pbfRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('decryption', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->decryptionRules, 'rules' => $pan->decryptionRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('appoverride', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->appOverrideRules, 'rules' => $pan->appOverrideRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('captiveportal', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->captivePortalRules, 'rules' => $pan->captivePortalRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('authentication', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->authenticationRules, 'rules' => $pan->authenticationRules->rules());
            }
            if( array_search('any', $ruleTypes) !== false || array_search('dos', $ruleTypes) !== false )
            {
                $rulesToProcess[] = Array('store' => $pan->dosRules, 'rules' => $pan->dosRules->rules());
            }
            $locationFound = true;
        }

        foreach( $pan->getDeviceGroups() as $sub )
        {
            if( $location == 'any' || $location == 'all' || $location == $sub->name() )
            {
                if( array_search('any', $ruleTypes) !== false || array_search('security', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->securityRules, 'rules' => $sub->securityRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('nat', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->natRules, 'rules' => $sub->natRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('qos', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->qosRules, 'rules' => $sub->qosRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('pbf', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->pbfRules, 'rules' => $sub->pbfRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('decryption', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->decryptionRules, 'rules' => $sub->decryptionRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('appoverride', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->appOverrideRules, 'rules' => $sub->appOverrideRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('captiveportal', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->captivePortalRules, 'rules' => $sub->captivePortalRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('authentication', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->authenticationRules, 'rules' => $sub->authenticationRules->rules());
                }
                if( array_search('any', $ruleTypes) !== false || array_search('dos', $ruleTypes) !== false )
                {
                    $rulesToProcess[] = Array('store' => $sub->dosRules, 'rules' => $sub->dosRules->rules());
                }
                $locationFound = true;
            }
        }
    }

    if( !$locationFound )
    {
        print "ERROR: location '$location' was not found. Here is a list of available ones:\n";
        print " - shared\n";
        if( $configType == 'panos' )
        {
            foreach( $pan->getVirtualSystems() as $sub )
            {
                print " - ".$sub->name()."\n";
            }
        }
        else
        {
            foreach( $pan->getDeviceGroups() as $sub )
            {
                print " - ".$sub->name()."\n";
            }
        }
        print "\n\n";
        exit(1);
    }
}
// </editor-fold>


print "\n";
$pan->display_statistics();
print "\n";
$processedLocations = Array();
foreach( $rulesToProcess as &$record )
{
    if( get_class($record['store']->owner) != 'PanoramaConf' && get_class($record['store']->owner) != 'PANConf' )
    {
        /** @var DeviceGroup|VirtualSystem $sub */
        $sub = $record['store']->owner;
        if( isset($processedLocations[$sub->name()]) )
            continue;
        $processedLocations[$sub->name()] = true;
        $sub->display_statistics();
        echo "\n";
    }
}


print "\n\n************ END OF RULE-STATS UTILITY ************\n";
print     "**************************************************\n";
print "\n\n";




